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

    /**
     * The legacy portal's price lines (its prices_categories, lower-cased and
     * hyphenated — the keys its Client Pricing form saves and reads), in the
     * order that form lists them. Every reservation is sent with ALL of them,
     * 0 where we have no value, so the form is complete.
     */
    const LEGACY_PRICE_KEYS = [
        'meet-greet', 'surcharge', 'distance', 'cleanup-fee', 'parking', 'am-/-pm-fee', 'security-srv',
        'wait-time', 'hwy-tolls', 'airport-fee', 'car-seat', 'stc', 'garage-to-garage', 'outside-serv',
    ];

    /** Our surcharge code -> legacy price line. Anything not listed (fuel, holiday, extra stop, custom ones...) goes to the generic 'surcharge' line. */
    protected $surcharge_key_map = [
        'MEET_GREET'     => 'meet-greet',
        'AIRPORT_FEE'    => 'airport-fee',
        'PARKING'        => 'parking',
        'TOLL'           => 'hwy-tolls',
        'WAITING_TIME'   => 'wait-time',
        'LATE_NIGHT_FEE' => 'am-/-pm-fee',
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

        // json_encode() would otherwise write 48.14 as 48.1400000000000005 on
        // hosts whose serialize_precision is 17 — those digits end up in the
        // legacy price fields.
        ini_set('serialize_precision', '-1');

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

        // Pricing always comes from the outbound row (see docblock) — one-way,
        // per-leg figures, laid out the way the legacy Client Pricing form
        // does its own math (see leg_pricing()).
        $pricing = $this->leg_pricing($booking);
        $hours = (float)($booking['hours_requested'] ?? 0);
        $tripFields = (($booking['service_type'] ?? '') === 'Hourly' && $hours > 0)
            ? ['min' => $hours, 'flat' => 0, 'rate' => round($pricing['base'] / $hours, 4)]
            : ['min' => 1,      'flat' => 1, 'rate' => $pricing['base']];

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
            // "Finalization" dropdown: paid through PayPal (captured before this sync runs) -> "Web Payment";
            // PayPal off -> "Collect", which is that dropdown's empty value (a literal "Collect" would match no
            // option, and the portal's dashboard colours any non-empty value green as "finalized").
            'res_final_status'       => (($booking['payment_mode'] ?? 'paypal') === 'none') ? '' : 'Web Payment',
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
            'dropoff_time'           => $this->dropoff_time($booking, $leg), // pickup + booked hours / route duration
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
            // "Client Pricing" fields — see leg_pricing() for how they add up.
            // Trip Min / Flat / Rate behind "Charges": an Hourly booking is hours x hourly rate;
            // everything else is entered the way staff enter a flat fare (1 x fare), so the
            // fields multiply out to Charges if someone edits the entry over there.
            'p_trip_min'             => $tripFields['min'],
            'p_trip_flat'            => $tripFields['flat'],
            'p_trip_rate'            => $tripFields['rate'],
            'p_charges'              => $pricing['base'],       // "Charges" — the base our gratuity % applies to (transportation + travel fee)
            'graduity'               => $pricing['gratuity'],   // legacy shows this / Charges as the gratuity %
            'discount'               => $pricing['discount'],
            'p_total'                => $pricing['p_total'],    // Charges + gratuity + every price line below (what the legacy form's "Total" is)
            'deposit'                => 0,
            'grand_total'            => $pricing['grand'],      // p_total - discount
            'prices'                 => json_encode($pricing['prices'], JSON_FORCE_OBJECT), // {"Surcharges": 48.14, ...} — name => amount, as the legacy form iterates it
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
     * One leg's price lines in the legacy portal's shape. The legacy Client
     * Pricing form computes  Total = Charges + Gratuity + sum(price lines)
     * and shows Gratuity % as Gratuity / Charges, with the price lines as a
     * {name: amount} map (see LEGACY_PRICE_KEYS). So:
     *   base     "Charges": whatever remains after every other line, which is
     *            transportation + travel fee — exactly what our gratuity %
     *            is applied to (Pricing_engine), so the % shown there matches.
     *   prices   all LEGACY_PRICE_KEYS, 0 where unused: each stored surcharge on
     *            its matching line (surcharge_key_map), child seats on 'car-seat',
     *            and add-ons + any unmapped surcharge on the generic 'surcharge'.
     *            Bookings from before v27 have no itemised surcharges, so their
     *            surcharge total lands on 'surcharge'.
     * `amount` is the only figure doubled for a round trip, so each leg gets
     * half of it; everything else is already per leg. Shared with the
     * customer confirmation email (which hides the zero lines).
     */
    public function leg_pricing(array $booking)
    {
        $n = function ($k) use ($booking) { return (float)($booking[$k] ?? 0); };
        $grand    = round(!empty($booking['is_return_trip']) ? $n('amount') / 2 : $n('amount'), 2);
        $gratuity = $n('gratuity_amount');
        $discount = $n('discount_amount');

        $prices = array_fill_keys(self::LEGACY_PRICE_KEYS, 0.0);
        $add = function ($key, $amt) use (&$prices) { $prices[$key] = round($prices[$key] + (float)$amt, 2); };

        $items = !empty($booking['surcharges_json']) ? json_decode($booking['surcharges_json'], TRUE) : NULL;
        if (is_array($items) && $items) {
            foreach ($items as $it) {
                $code = strtoupper((string)($it['code'] ?? ''));
                $add($this->surcharge_key_map[$code] ?? 'surcharge', $it['amount'] ?? 0);
            }
        } else {
            $add('surcharge', $n('surcharges_total_amount')); // pre-v27 booking: total only
        }
        $add('car-seat', $n('child_seats_fee_amount'));
        $addons = !empty($booking['addons_json']) ? json_decode($booking['addons_json'], TRUE) : NULL;
        $addonSum = 0.0;
        if (is_array($addons)) foreach ($addons as $a) $addonSum += (float)($a['line_total'] ?? 0);
        $add('surcharge', $addonSum > 0 ? $addonSum : $n('addons_total'));

        $base    = round(max(0, $grand + $discount - $gratuity - array_sum($prices)), 2);
        $p_total = round($base + $gratuity + array_sum($prices), 2);
        return [
            'base'     => $base,
            'gratuity' => $gratuity,
            'prices'   => $prices,
            'discount' => $discount,
            'p_total'  => $p_total,
            'grand'    => round($p_total - $discount, 2),
        ];
    }

    /**
     * Drop-off time (H:i:s) for one leg: pickup + the booked hours for Hourly
     * service, otherwise pickup + the route's driving duration. Falls back to
     * the pickup time when neither is known. Time of day only — the legacy
     * column and the email both show a clock time.
     */
    public function dropoff_time(array $booking, array $leg)
    {
        $pickup = $leg['pickup_time'] ?? '00:00:00';
        $hours  = (float)($booking['hours_requested'] ?? 0);
        $mins   = (($booking['service_type'] ?? '') === 'Hourly' && $hours > 0)
            ? (int)round($hours * 60)
            : (int)($leg['duration_mins'] ?? $booking['duration_mins'] ?? 0);
        if ($mins <= 0) return date('H:i:s', strtotime($pickup));
        return date('H:i:s', strtotime(($leg['pickup_date'] ?? 'today') . ' ' . $pickup . ' +' . $mins . ' minutes'));
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
