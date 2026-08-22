<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Syncs an approved reservation into the legacy management portal
 * (localhost/bookingOld) — a separate, older CI3 app with its own
 * `reservations` MySQL database that the dispatch/ops team still uses
 * day to day. Called once, right after Admin_api::reservations_accept()
 * captures payment and marks a booking paid.
 *
 * A round-trip booking becomes TWO rows over there: the outbound leg as
 * booked, and a second row for the return leg with pickup/dropoff
 * swapped (kfb_bookings' own return_booking_id row already has these
 * swapped — see Booking_model::_return_leg_fields()) — matching how
 * that portal has no native concept of a single "round trip" booking.
 *
 * Field values are mapped onto the legacy `reservations` table's own
 * column names/vocabulary (see field-by-field comments below); where
 * this system doesn't track an equivalent value at all (e.g. per-fee
 * breakdown, driver assignment) the legacy column is just left at the
 * same default an admin creating a blank reservation over there would
 * see, rather than guessing.
 *
 * Best-effort / non-blocking: sync failures are logged, never thrown —
 * an admin approving a reservation must never fail because the OTHER
 * system's sync endpoint is down. Ops can always re-enter it there by
 * hand as a fallback (same as before this integration existed).
 */
class Legacy_reservations
{
    protected $CI;

    /**
     * kfb_vehicles.code -> legacy reservations.vehicles.vehicle_id.
     * Hardcoded rather than looked up live — this is a small, fixed
     * fleet on both sides, and a live cross-database query would make
     * every sync depend on this app's DB user having access to the
     * legacy database too, which isn't guaranteed once these two apps
     * run on separate hosts/credentials in production.
     * (11 Sedan, 12 SUV, 15 Stretch Limo, 16 VAN — from reservations.vehicles.)
     */
    protected $vehicle_map = [
        'sedan'    => 11,
        'suv'      => 12,
        'sprinter' => 16, // "Luxury Sprinter" has no exact match on that side — VAN is the closest category
        'limo'     => 15,
    ];
    /** Fallback legacy vehicle_id when a code isn't in $vehicle_map. */
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
     * relative to the outbound — see this class's docblock); everything
     * else (contact info, card, vehicle, company) is shared between both
     * legs since it's the same trip and the same customer.
     */
    protected function _map_leg(array $booking, $isReturn, array $legRow = NULL)
    {
        $leg = $isReturn ? $legRow : $booking;

        $vehicleCode = $booking['vehicle_id'] ?? '';
        $legacyVehicleId = $this->vehicle_map[$vehicleCode] ?? self::DEFAULT_LEGACY_VEHICLE_ID;

        $tripType = $this->trip_type_map[$booking['service_type'] ?? ''] ?? 'POINT TO POINT';

        // The primary leg's `amount` covers the whole round trip already
        // (this codebase never gives the return leg its own price — see
        // Booking_model::_return_leg_fields(), amount always 0.00 there);
        // matching that same convention here rather than inventing a split.
        $amount = $isReturn ? 0.00 : (float)$booking['amount'];

        $pickupAddress = (string)($leg['pickup'] ?? '');
        if (!$isReturn && !empty($booking['stops'])) {
            foreach ($booking['stops'] as $i => $stop) {
                $pickupAddress .= "\nStop " . ($i + 1) . ': ' . $stop['address'];
            }
        }

        return [
            'res_reference'          => $booking['booking_id'] . ($isReturn ? '-RT' : ''),
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
            // Per-fee breakdown (surcharge/gratuity/meet&greet/etc.) isn't
            // persisted per booking in this system — only the final total
            // is. Leaving these at 0 is accurate; guessing a split isn't.
            'p_trip_min'             => 0,
            'p_trip_flat'            => 0,
            'p_trip_rate'            => 0,
            'p_charges'              => 0,
            'graduity'               => 0,
            'discount'               => $isReturn ? 0 : (float)$booking['discount_amount'],
            'p_total'                => $amount,
            'deposit'                => 0,
            'grand_total'            => $amount,
            'prices'                 => '{}',
            'created_at'             => date('Y-m-d H:i:s'),
        ];
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
