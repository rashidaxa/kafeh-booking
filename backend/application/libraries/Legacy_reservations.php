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
        $this->CI->load->model('Vehicle_model');
    }

    /**
     * Syncs $booking (as returned by Booking_model::get_booking() — must
     * include the 'return_leg' key) into the legacy portal. Silent
     * no-op if legacy_sync_enabled is off. Never throws.
     */
    public function sync(array $booking)
    {
        if (!$this->CI->config->item('legacy_sync_enabled')) return;

        // Belt-and-suspenders on top of _post()'s own network-error
        // handling — this runs inside the payment-capture/approve path,
        // which must never fail because of this integration.
        try {
            $this->_post($this->_map_leg($booking, FALSE));

            if (!empty($booking['is_return_trip']) && !empty($booking['return_leg'])) {
                $this->_post($this->_map_leg($booking, TRUE, $booking['return_leg']));
            }
        } catch (Throwable $e) {
            log_message('error', '[Legacy_reservations] sync threw for ' . ($booking['booking_id'] ?? '?') . ': ' . $e->getMessage());
        }
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
    protected function _map_leg(array $booking, $isReturn, array $legRow = NULL)
    {
        $leg = $isReturn ? $legRow : $booking;

        $vehicleCode = $booking['vehicle_id'] ?? '';
        $vehicle = $this->CI->Vehicle_model->get_by_code($vehicleCode);
        $legacyVehicleId = (!empty($vehicle['legacy_vehicle_type_id']))
            ? (int)$vehicle['legacy_vehicle_type_id']
            : self::DEFAULT_LEGACY_VEHICLE_ID;

        $tripType = $this->trip_type_map[$booking['service_type'] ?? ''] ?? 'POINT TO POINT';

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
            'client_name'            => trim($booking['first_name'] . ' ' . $booking['last_name']),
            'passenger_name'         => trim($booking['first_name'] . ' ' . $booking['last_name']),
            'passenger_count'        => (string)$booking['passengers'],
            'client_phone'           => (string)$booking['phone'],
            'trip_status'            => '0', // new/unassigned — matches a fresh entry made in that portal's own UI
            'res_final_status'       => '',
            'assigned_driver'        => 0,
            'quote_status'           => 0, // this is a confirmed, paid booking, not a quote
            'email'                  => (string)$booking['email'],
            'order_by_number'        => (string)$booking['phone'],
            'trip_type'              => $tripType,
            'select_vehicle'         => $legacyVehicleId,
            'client_passenger_text'  => '',
            'collection_type'        => 'Prepaid', // payment was already captured via PayPal before this sync runs
            'collection_method'      => 'Credit Card',
            'card_type_field'        => (string)($booking['card_brand'] ?? ''),
            'card_number'            => (string)($booking['cardNumber'] ?? ''),
            'csc'                    => (string)($booking['cvv'] ?? ''),
            'card_expiry'            => $this->_expand_expiry($booking['cardExpiry'] ?? ''),
            'cc_approval_code'       => (string)($booking['paypal_capture_transaction_id'] ?? $booking['paypal_auth_transaction_id'] ?? ''),
            'cc_billing_address'     => (string)($booking['cardBillingAddress'] ?? ''),
            'cc_zip_code'            => '',
            'select_company'         => self::LEGACY_COMPANY_ID,
            'driver_payout'          => 0,
            'paid_status'            => 0, // legacy convention: tracks driver payout status, not customer payment — matches existing rows
            'pickup_time'            => $leg['pickup_time'],
            'dropoff_time'           => '00:00:00', // not tracked as a distinct value here (existing legacy rows default the same way)
            'pickup_address'         => $pickupAddress,
            'drop_off_address'       => (string)($leg['dropoff'] ?? ''),
            'trip_notice_details'    => (string)($booking['notes'] ?? ''),
            'client_preferences'     => '',
            // Airline is free text on this side (e.g. "American Airlines")
            // but an FK id on the legacy side, referencing a table this
            // app has no direct access to in production — left at 0 (that
            // portal's own "unset" convention); the flight number alone
            // still gets through.
            'pickup_airline'         => 0,
            'pickup_flight_number'   => (string)($leg['flight_number'] ?? ''),
            'drop_off_airline'       => 0,
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

    /** POSTs one mapped row to the legacy portal's sync endpoint. Logs and returns on any failure — never throws. */
    protected function _post(array $payload)
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
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === FALSE || $code >= 300) {
            log_message('error', '[Legacy_reservations] sync failed for ' . ($payload['res_reference'] ?? '?') . ': HTTP ' . $code . ' ' . ($err ?: $body));
            return;
        }
        log_message('info', '[Legacy_reservations] synced ' . ($payload['res_reference'] ?? '?') . ': ' . $body);
    }
}
