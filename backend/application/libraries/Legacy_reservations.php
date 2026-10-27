<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Syncs an approved reservation into the legacy management portal
 * (localhost/bookingOld locally, "works" in production) — a separate,
 * older CI3 app with its own `reservations` MySQL database that the
 * dispatch/ops team still uses day to day. Called once, right after
 * Admin_api::reservations_accept() captures payment and marks a
 * booking paid.
 *
 * A round-trip booking becomes TWO independent reservations over there
 * (v20) — the outbound leg and the return leg — each priced as HALF the
 * round trip, not one full-price row + one zero row. This works out
 * cleanly because only `amount` on the outbound kfb_bookings row is
 * ever doubled for a round trip (see Pricing_engine::_compute()) —
 * every other stored breakdown figure (transportation_amount,
 * surcharges_total_amount, gratuity_amount, discount_amount,
 * addons_total/addons_json) is already the one-way, per-leg value and
 * needs no further division. `amount` itself is halved at sync time
 * (see grand_total below). The return leg's OWN kfb_bookings row never
 * stores its own breakdown (Booking_model::_return_leg_fields() leaves
 * those columns at their defaults) — pricing is always read from the
 * outbound row, for both legacy entries; only pickup/dropoff/date/time/
 * flight info comes from each leg's own row.
 *
 * Field values are mapped onto the legacy `reservations` table's own
 * column names/vocabulary (see field-by-field comments below); where
 * this system doesn't track an equivalent value at all (e.g. driver
 * assignment) the legacy column is just left at the same default an
 * admin creating a blank reservation over there would see, rather than
 * guessing.
 *
 * Best-effort / non-blocking: sync failures are logged, never thrown —
 * an admin approving a reservation must never fail because the OTHER
 * system's sync endpoint is down. Ops can always re-enter it there by
 * hand as a fallback (same as before this integration existed).
 */
#[\AllowDynamicProperties]
class Legacy_reservations
{
    protected $CI;

    /** Fallback legacy vehicle_id when a vehicle has no legacy_vehicle_type_id set (v20) — Sedan. */
    const DEFAULT_LEGACY_VEHICLE_ID = 11;

    /** Our service_type -> legacy reservations.trip_type vocabulary (see that table's DISTINCT values). */
    protected $trip_type_map = [
        'Transfer'       => 'POINT TO POINT',
        'Point to Point' => 'POINT TO POINT',
        'From Airport'   => 'ARRIVAL (FROM AIRPORT)',
        'To Airport'     => 'DEPARTURE (TO AIRPORT)',
        'Hourly'         => 'HOURLY SERVICE',
        'Wedding'        => 'WEDDING',
        'Tour'           => 'CHARTER',
    ];

    /** A1 Limousine Service's company_id in the legacy companies table — this portal serves one brand. */
    const LEGACY_COMPANY_ID = 6;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('crmsync');
        $this->CI->load->model(['Vehicle_model', 'Company_model']);
    }

    /**
     * Syncs $booking (as returned by Booking_model::get_booking() — must
     * include the 'return_leg' key) into the legacy portal. Silent
     * no-op if legacy_sync_enabled is off. Never throws.
     *
     * Returns a log array (one entry per leg posted, or one 'disabled'/
     * 'exception' entry) so the caller can persist it for admins to
     * inspect — see Admin_api::reservations_accept() and the "View
     * Legacy Sync Log" button on the reservation detail page.
     */
    public function sync(array $booking)
    {
        if (!$this->CI->config->item('legacy_sync_enabled')) {
            return [['leg' => 'outbound', 'skipped' => TRUE, 'reason' => 'legacy_sync_enabled is FALSE', 'at' => date('Y-m-d H:i:s')]];
        }

        $logs = [];
        // Belt-and-suspenders on top of _post()'s own network-error
        // handling — this runs inside the payment-capture/approve path,
        // which must never fail because of this integration.
        try {
            $logs[] = $this->_post('outbound', $this->_map_leg($booking, FALSE));

            if (!empty($booking['is_return_trip']) && !empty($booking['return_leg'])) {
                $logs[] = $this->_post('return', $this->_map_leg($booking, TRUE, $booking['return_leg']));
            }
        } catch (Throwable $e) {
            log_message('error', '[Legacy_reservations] sync threw for ' . ($booking['booking_id'] ?? '?') . ': ' . $e->getMessage());
            $logs[] = ['leg' => 'exception', 'error' => $e->getMessage(), 'at' => date('Y-m-d H:i:s')];
        }
        return $logs;
    }

    /**
     * Builds one legacy `reservations` row. $isReturn + $legRow together
     * select which leg's own fields to read for pickup/dropoff/date/time/
     * flight info (the return leg row already has pickup/dropoff swapped
     * relative to the outbound — see Booking_model::_return_leg_fields());
     * every pricing figure, and everything else (contact info, card,
     * vehicle, company), always comes from $booking (the outbound row) —
     * see this class's docblock for why.
     */
    protected function _map_leg(array $booking, $isReturn, ?array $legRow = NULL)
    {
        $leg = $isReturn ? $legRow : $booking;

        $vehicleCode = $booking['vehicle_id'] ?? '';
        $vehicle = $this->CI->Vehicle_model->get_by_code($vehicleCode);
        $legacyVehicleId = (!empty($vehicle['legacy_vehicle_type_id']))
            ? (int)$vehicle['legacy_vehicle_type_id']
            : self::DEFAULT_LEGACY_VEHICLE_ID;

        // Each company maps to its own company in the legacy portal; the
        // default (A1 Limousine) is LEGACY_COMPANY_ID, as before.
        $company = $this->CI->Company_model->get($booking['company_id'] ?? 1);
        $legacyCompanyId = !empty($company['legacy_company_id']) ? (int)$company['legacy_company_id'] : self::LEGACY_COMPANY_ID;

        $tripType = $this->_trip_type($booking['service_type'] ?? '', $leg);

        // Client = the credit card holder (v23); passenger = whoever
        // Passenger Info names. Bookings from before v23 have no
        // cardholder, so they keep the old behaviour (client = passenger).
        $passengerName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
        $clientName    = trim(($booking['cc_first_name'] ?? '') . ' ' . ($booking['cc_last_name'] ?? ''));
        if ($clientName === '') {
            $clientName = $passengerName;
        } elseif (!empty($booking['cc_company'])) {
            $clientName .= ' | ' . trim($booking['cc_company']);
        }
        $clientPhone = (string)(!empty($booking['cc_phone']) ? $booking['cc_phone'] : ($booking['phone'] ?? ''));
        $clientEmail = (string)(!empty($booking['cc_email']) ? $booking['cc_email'] : ($booking['email'] ?? ''));

        // Pricing always comes from the outbound row (see docblock) —
        // these are the one-way, per-leg figures already, nothing here
        // needs dividing.
        $baseAmount    = (float)($booking['transportation_amount'] ?? 0);
        $surchargesAmt = (float)($booking['surcharges_total_amount'] ?? 0);
        $gratuityAmt   = (float)($booking['gratuity_amount'] ?? 0);
        $discountAmt   = (float)($booking['discount_amount'] ?? 0);

        // `amount` is the only figure that's ever doubled for a round
        // trip (see docblock) — each leg's legacy entry gets half of it.
        $isRoundTrip = !empty($booking['is_return_trip']);
        $fullAmount  = (float)($booking['amount'] ?? 0);
        $grandTotal  = round($isRoundTrip ? $fullAmount / 2 : $fullAmount, 2);

        $pickupAddress = (string)($leg['pickup'] ?? '');
        if (!$isReturn && !empty($booking['stops'])) {
            foreach ($booking['stops'] as $i => $stop) {
                $pickupAddress .= "\nStop " . ($i + 1) . ': ' . $stop['address'];
            }
        }

        return [
            // Outbound: our exact booking_id (already ends "-ON" — see
            // Booking_model::_new_booking_id()). Return: the return leg's
            // own real booking_id (ends "-RT"), not a synthesized one —
            // it's a genuine independent reservation now (v20), so its
            // reference should trace back to its own row, not the
            // outbound's id with a suffix tacked on.
            'res_reference'          => $isReturn ? ($legRow['booking_id'] ?? ($booking['booking_id'] . '-RT')) : $booking['booking_id'],
            'res_date'               => $leg['pickup_date'],
            'client_name'            => $clientName,
            'passenger_name'         => $passengerName,
            'passenger_count'        => (string)$booking['passengers'],
            'client_phone'           => $clientPhone,
            'trip_status'            => '0', // new/unassigned — matches a fresh entry made in that portal's own UI
            'res_final_status'       => '',
            'assigned_driver'        => 0,
            'quote_status'           => 0, // this is a confirmed, paid booking, not a quote
            'email'                  => $clientEmail,
            'order_by_number'        => $clientPhone,
            'trip_type'              => $tripType,
            'select_vehicle'         => $legacyVehicleId,
            'client_passenger_text'  => '',
            // Booked through PayPal: payment was already captured before this sync runs.
            // Booked with PayPal switched off: nothing was charged online — the card is processed manually in the legacy portal.
            'collection_type'        => (($booking['payment_mode'] ?? 'paypal') === 'none') ? 'Process' : 'Prepaid',
            'collection_method'      => 'Credit Card',
            'card_type_field'        => (string)($booking['card_brand'] ?? ''),
            'card_number'            => (string)($booking['cardNumber'] ?? ''),
            'csc'                    => (string)($booking['cvv'] ?? ''),
            'card_expiry'            => $this->_expand_expiry($booking['cardExpiry'] ?? ''),
            'cc_approval_code'       => (string)($booking['paypal_capture_transaction_id'] ?? $booking['paypal_auth_transaction_id'] ?? ''),
            'cc_billing_address'     => (string)($booking['cardBillingAddress'] ?? ''),
            'cc_zip_code'            => '',
            'select_company'         => $legacyCompanyId,
            'driver_payout'          => 0,
            'paid_status'            => 0, // legacy convention: tracks driver payout status, not customer payment — matches existing rows
            'pickup_time'            => $leg['pickup_time'],
            'dropoff_time'           => '00:00:00', // not tracked as a distinct value here (existing legacy rows default the same way)
            'pickup_address'         => $pickupAddress,
            'drop_off_address'       => (string)($leg['dropoff'] ?? ''),
            'trip_notice_details'    => $this->_trip_notes($booking, $leg, $clientPhone, $clientEmail),
            'client_preferences'     => '',
            // Airline is free text on this side but an FK id on the legacy
            // side (locations.location_id), which only that portal can
            // look up. Sent as 0 plus the name in `*_airline_name`; the
            // portal's create_reservation endpoint resolves the name to
            // an id, and appends it to trip_notice_details if no match.
            'pickup_airline'         => 0,
            'pickup_airline_name'    => (string)($leg['airline'] ?? ''),
            'pickup_flight_number'   => (string)($leg['flight_number'] ?? ''),
            'drop_off_airline'       => 0,
            'drop_off_airline_name'  => (string)($leg['dropoff_airline'] ?? ''),
            'drop_off_flight_number' => (string)($leg['dropoff_flight_number'] ?? ''),
            'pickup_connecting_city' => '',
            'dropoff_connecting_city' => '',
            // "Client Pricing" fields (addreservation.php's own labels in
            // parens) — see this class's docblock for the half-split.
            // p_trip_min/p_trip_flat/p_trip_rate deliberately left unset
            // (NULL, that table's own default) — our zone multipliers and
            // hourly-minimum floors mean there's no single hours x rate
            // that reliably reproduces p_total, so rather than send a
            // number that sometimes doesn't multiply out right, we just
            // don't populate these three at all.
            'p_charges'              => $surchargesAmt,         // "Charges"
            'graduity'               => $gratuityAmt,
            'discount'               => $discountAmt,
            'p_total'                => $baseAmount,            // base rate only (excludes travel fee/surcharges/gratuity/add-ons/discount)
            'deposit'                => 0,
            'grand_total'            => $grandTotal,            // full total — surcharges, add-ons, discount, gratuity all included
            'prices'                 => json_encode($this->_prices_breakdown($booking)),
            'created_at'             => date('Y-m-d H:i:s'),
        ];
    }

    /** Public wrappers so the customer confirmation email (Admin_api) shows the exact trip type / notes the legacy portal receives. */
    public function trip_type($service, array $leg) { return $this->_trip_type($service, $leg); }
    public function trip_notes(array $booking, array $leg, $clientPhone, $clientEmail) { return $this->_trip_notes($booking, $leg, $clientPhone, $clientEmail); }

    /**
     * Legacy trip_type for one leg. Pickup at an airport -> ARRIVAL (FROM
     * AIRPORT); otherwise dropoff at an airport -> DEPARTURE (TO
     * AIRPORT); otherwise the plain service mapping (POINT TO POINT for
     * Transfer/Point to Point). Hourly/Wedding/Tour keep their own types
     * regardless of airport endpoints. Evaluated per leg, so a round
     * trip's return leg (pickup/dropoff swapped) flips correctly.
     */
    protected function _trip_type($service, array $leg)
    {
        $base = $this->trip_type_map[$service] ?? 'POINT TO POINT';
        if (in_array($service, ['Hourly', 'Wedding', 'Tour'], TRUE)) return $base;
        if (($leg['pickup_loc_type'] ?? '') === 'Airport')  return 'ARRIVAL (FROM AIRPORT)';
        if (($leg['dropoff_loc_type'] ?? '') === 'Airport') return 'DEPARTURE (TO AIRPORT)';
        return $base;
    }

    /**
     * The legacy portal has no columns for arrival time, FBO tail number
     * or a separate passenger contact, so they ride along in the free-text
     * trip_notice_details after the customer's own notes. Only present
     * items are included.
     */
    protected function _trip_notes(array $booking, array $leg, $clientPhone, $clientEmail)
    {
        $parts = [];
        $fmt = function ($t) { return $t ? date('g:i A', strtotime($t)) : ''; };

        if (!empty($leg['arrival_time']))         $parts[] = 'Arrival: ' . $fmt($leg['arrival_time']);
        if (!empty($leg['tail_number']))          $parts[] = 'Tail #: ' . $leg['tail_number'];
        if (!empty($leg['dropoff_arrival_time'])) $parts[] = 'Drop-off Arrival: ' . $fmt($leg['dropoff_arrival_time']);
        if (!empty($leg['dropoff_tail_number']))  $parts[] = 'Drop-off Tail #: ' . $leg['dropoff_tail_number'];

        // Passenger contact only when it isn't already the client's.
        if (!empty($booking['phone']) && $booking['phone'] !== $clientPhone) $parts[] = 'Passenger Phone: ' . $booking['phone'];
        if (!empty($booking['email']) && strcasecmp($booking['email'], $clientEmail) !== 0) $parts[] = 'Passenger Email: ' . $booking['email'];

        $notes = trim((string)($booking['notes'] ?? ''));
        $extra = implode(' | ', $parts);
        return $notes !== '' && $extra !== '' ? $notes . "\n" . $extra : $notes . $extra;
    }

    /**
     * Itemized add-ons + surcharges for the legacy `prices` JSON column.
     * Add-ons are itemized (addons_json has real per-item name/amount);
     * surcharges are only stored as an aggregate total on this side (no
     * itemized per-surcharge table on a booking), so that comes through
     * as a single labeled line rather than a guessed breakdown.
     */
    protected function _prices_breakdown(array $booking)
    {
        $items = [];

        if (!empty($booking['addons_json'])) {
            $addons = json_decode($booking['addons_json'], TRUE);
            if (is_array($addons)) {
                foreach ($addons as $a) {
                    $items[] = [
                        'name'   => $a['name'] ?? ($a['code'] ?? 'Add-on'),
                        'amount' => (float)($a['line_total'] ?? 0),
                    ];
                }
            }
        }

        $surcharges = (float)($booking['surcharges_total_amount'] ?? 0);
        if ($surcharges > 0) {
            $items[] = ['name' => 'Surcharges', 'amount' => $surcharges];
        }

        return $items;
    }

    /** "12/29" -> "12/2029". Leaves already-4-digit or unrecognized values as-is. */
    protected function _expand_expiry($expiry)
    {
        if (preg_match('#^(\d{2})/(\d{2})$#', trim((string)$expiry), $m)) {
            return $m[1] . '/20' . $m[2];
        }
        return (string)$expiry;
    }

    /**
     * POSTs one mapped row to the legacy portal's sync endpoint. Logs and
     * returns a log entry on any failure — never throws.
     *
     * CURLOPT_USERAGENT is required here: both booking/ and works/ sit
     * behind the host's ModSecurity, which returns a blanket "406 Not
     * Acceptable" for any request with no User-Agent header — which is
     * exactly what PHP's curl sends by default. Without this, every
     * sync POST was silently swallowed as a network failure before it
     * ever reached works/'s own X-Sync-Key check.
     */
    protected function _post($leg, array $payload)
    {
        $url = rtrim($this->CI->config->item('legacy_sync_base_url'), '/') . '/api/create_reservation';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST           => TRUE,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Sync-Key: ' . $this->CI->config->item('legacy_sync_api_key'),
            ],
            CURLOPT_USERAGENT      => 'KafehBookingSync/1.0',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $entry = [
            'leg'          => $leg,
            'res_reference'=> $payload['res_reference'] ?? '?',
            'url'          => $url,
            'http_code'    => $code,
            'response'     => $body === FALSE ? NULL : $body,
            'curl_error'   => $err ?: NULL,
            'at'           => date('Y-m-d H:i:s'),
        ];

        if ($body === FALSE || $code >= 300) {
            log_message('error', '[Legacy_reservations] sync failed for ' . $entry['res_reference'] . ': HTTP ' . $code . ' ' . ($err ?: $body));
        } else {
            log_message('info', '[Legacy_reservations] synced ' . $entry['res_reference'] . ': ' . $body);
        }
        return $entry;
    }
}
