<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Booking Model
 * Tables:
 *   kfb_bookings   - one row per reservation
 *   kfb_stops      - multiple stop rows per booking
 *   kfb_payments   - payment events (create / capture / refund)
 */

class Booking_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Create a new booking. Returns the booking_id.
     *
     * When isReturnTrip is set, a second linked booking row is also created
     * for the return leg: pickup/dropoff are swapped (the outbound dropoff
     * becomes the return leg's pickup, and vice versa), the return leg's
     * own airport/flight fields (returnAirline, returnFlightNumber, ...
     * returnDropoffAirline, returnDropoffFlightNumber, ...) are used, and
     * the two rows are linked both ways via return_booking_id.
     */
    public function create_booking(array $data)
    {
        $booking_id = $this->_new_booking_id();
        list($childSeats, $childSeatsBreakdown) = $this->_normalize_child_seats($data);

        $return_booking_id = NULL;
        if (!empty($data['isReturnTrip'])) {
            $return_booking_id = $this->_create_return_leg($data, $booking_id, $childSeats, $childSeatsBreakdown);
        }

        // Normalize add-ons if provided
        $addonsJson = NULL;
        $addonsTotal = 0.0;
        if (!empty($data['addons']) && is_array($data['addons'])) {
            $addonsJson = json_encode(array_values($data['addons']), JSON_UNESCAPED_UNICODE);
            foreach ($data['addons'] as $a) {
                $addonsTotal += (float)($a['line_total'] ?? 0);
            }
        }

        $this->db->insert('kfb_bookings', [
            'booking_id'            => $booking_id,
            'service_type'          => $data['service'] ?? NULL,
            'pickup_date'           => $data['pickupDate'] ?? NULL,
            'pickup_time'           => $data['pickupTime'] ?? NULL,
            'pickup_loc_type'       => $data['pickupType'] ?? $data['pickup_loc_type'] ?? NULL,
            'dropoff_loc_type'      => $data['dropoffType'] ?? $data['dropoff_loc_type'] ?? NULL,
            'pickup'                => $data['pickup'] ?? NULL,
            'dropoff'               => $data['dropoff'] ?? NULL,
            'airline'               => !empty($data['airline']) ? trim($data['airline']) : NULL,
            'flight_number'         => !empty($data['flightNumber']) ? strtoupper(trim($data['flightNumber'])) : NULL,
            'arrival_time'          => !empty($data['arrivalTime']) ? $data['arrivalTime'] : NULL,
            'pickup_point'          => !empty($data['pickupPoint']) ? $data['pickupPoint'] : NULL,
            'dropoff_airline'       => !empty($data['dropoffAirline']) ? trim($data['dropoffAirline']) : NULL,
            'dropoff_flight_number' => !empty($data['dropoffFlightNumber']) ? strtoupper(trim($data['dropoffFlightNumber'])) : NULL,
            'dropoff_arrival_time'  => !empty($data['dropoffArrivalTime']) ? $data['dropoffArrivalTime'] : NULL,
            'dropoff_pickup_point'  => !empty($data['dropoffPickupPoint']) ? $data['dropoffPickupPoint'] : NULL,
            'pickup_type_detail'    => $data['pickupTypeDetail'] ?? NULL,
            'tail_number'           => !empty($data['tailNumber']) ? strtoupper(trim($data['tailNumber'])) : NULL,
            'dropoff_type_detail'   => $data['dropoffTypeDetail'] ?? NULL,
            'dropoff_tail_number'   => !empty($data['dropoffTailNumber']) ? strtoupper(trim($data['dropoffTailNumber'])) : NULL,
            'addons_json'           => $addonsJson,
            'addons_total'          => $addonsTotal,
            'min_fare_applied'      => (float)($data['minFareApplied'] ?? 0),
            'is_return_trip'        => !empty($data['isReturnTrip']) ? 1 : 0,
            'return_booking_id'     => $return_booking_id,
            'return_date'           => !empty($data['returnDate']) ? $data['returnDate'] : NULL,
            'return_time'           => !empty($data['returnTime']) ? $data['returnTime'] : NULL,
            'passengers'            => (int)($data['passengers'] ?? 1),
            'luggage'               => (int)($data['luggage'] ?? 0),
            'child_seats'           => $childSeats,
            'child_seats_breakdown' => $childSeatsBreakdown,
            'notes'                 => $data['notes'] ?? NULL,
            'vehicle_id'            => $data['vehicle_id'] ?? NULL,
            'vehicle_name'          => $data['vehicle_name'] ?? NULL,
            'distance_miles'        => (float)($data['distanceMiles'] ?? 0),
            'duration_mins'         => (int)($data['durationMins'] ?? 0),
            'amount'                => (float)($data['amount'] ?? 0),
            'discount_amount'       => (float)($data['discountAmount'] ?? 0),
            'promo_code'            => !empty($data['promoCode']) ? strtoupper(trim($data['promoCode'])) : NULL,
            'currency'              => 'USD',
            'first_name'            => $data['firstName'] ?? NULL,
            'last_name'             => $data['lastName'] ?? NULL,
            'email'                 => $data['email'] ?? NULL,
            'phone'                 => $data['phone'] ?? NULL,
            'status'                => 'pending',
            'created_at'            => date('Y-m-d H:i:s'),
            'ip_address'            => $this->input->ip_address(),
        ]);

        // Stops (outbound leg only — the widget doesn't collect separate return-leg stops)
        if (!empty($data['stops']) && is_array($data['stops'])) {
            foreach (array_values($data['stops']) as $i => $addr) {
                $this->db->insert('kfb_stops', [
                    'booking_id' => $booking_id,
                    'sequence'   => $i,
                    'address'    => $addr,
                ]);
            }
        }

        // Add-on line items (outbound leg only)
        if (!empty($data['addons']) && is_array($data['addons'])) {
            foreach ($data['addons'] as $a) {
                $this->db->insert('kfb_booking_addons', [
                    'booking_id' => $booking_id,
                    'addon_id'   => (int)($a['id'] ?? 0),
                    'addon_code' => $a['code'] ?? '',
                    'addon_name' => $a['name'] ?? '',
                    'quantity'   => (int)($a['quantity'] ?? 1),
                    'unit_price' => (float)($a['unit_price'] ?? 0),
                    'line_total' => (float)($a['line_total'] ?? 0),
                    'region'     => $a['region'] ?? 'worldwide',
                ]);
            }
        }

        return $booking_id;
    }

    /**
     * Insert the return leg's booking row and return its booking_id.
     *
     * Pickup/dropoff are the outbound leg's dropoff/pickup swapped — the
     * widget already resolves $data['pickup']/$data['dropoff'] (and their
     * loc types) to their final values before submitting, so we just flip
     * them rather than re-deriving anything. Fare/add-ons/promo are left
     * at 0 — the whole reservation is authorized and captured as one PayPal
     * transaction against the outbound booking (see update_booking_status(),
     * which also marks this row paid/cancelled when the outbound one is).
     */
    protected function _create_return_leg(array $data, $primary_booking_id, $childSeats, $childSeatsBreakdown)
    {
        $return_booking_id = $this->_new_booking_id();

        $this->db->insert('kfb_bookings', [
            'booking_id'            => $return_booking_id,
            'service_type'          => $data['service'] ?? NULL,
            'pickup_date'           => !empty($data['returnDate']) ? $data['returnDate'] : NULL,
            'pickup_time'           => !empty($data['returnTime']) ? $data['returnTime'] : NULL,
            'pickup_loc_type'       => $data['dropoffType'] ?? NULL,
            'dropoff_loc_type'      => $data['pickupType'] ?? NULL,
            'pickup'                => $data['dropoff'] ?? NULL,
            'dropoff'               => $data['pickup'] ?? NULL,
            'airline'               => !empty($data['returnAirline']) ? trim($data['returnAirline']) : NULL,
            'flight_number'         => !empty($data['returnFlightNumber']) ? strtoupper(trim($data['returnFlightNumber'])) : NULL,
            'arrival_time'          => !empty($data['returnArrivalTime']) ? $data['returnArrivalTime'] : NULL,
            'pickup_type_detail'    => $data['returnPickupTypeDetail'] ?? NULL,
            'tail_number'           => !empty($data['returnTailNumber']) ? strtoupper(trim($data['returnTailNumber'])) : NULL,
            'dropoff_airline'       => !empty($data['returnDropoffAirline']) ? trim($data['returnDropoffAirline']) : NULL,
            'dropoff_flight_number' => !empty($data['returnDropoffFlightNumber']) ? strtoupper(trim($data['returnDropoffFlightNumber'])) : NULL,
            'dropoff_arrival_time'  => !empty($data['returnDropoffArrivalTime']) ? $data['returnDropoffArrivalTime'] : NULL,
            'dropoff_type_detail'   => $data['returnDropoffTypeDetail'] ?? NULL,
            'dropoff_tail_number'   => !empty($data['returnDropoffTailNumber']) ? strtoupper(trim($data['returnDropoffTailNumber'])) : NULL,
            'addons_json'           => NULL,
            'addons_total'          => 0.00,
            'min_fare_applied'      => 0.00,
            'is_return_trip'        => 1,
            'return_booking_id'     => $primary_booking_id,
            'return_date'           => $data['pickupDate'] ?? NULL,
            'return_time'           => $data['pickupTime'] ?? NULL,
            'passengers'            => (int)($data['passengers'] ?? 1),
            'luggage'               => (int)($data['luggage'] ?? 0),
            'child_seats'           => $childSeats,
            'child_seats_breakdown' => $childSeatsBreakdown,
            'notes'                 => $data['notes'] ?? NULL,
            'vehicle_id'            => $data['vehicle_id'] ?? NULL,
            'vehicle_name'          => $data['vehicle_name'] ?? NULL,
            'distance_miles'        => (float)($data['distanceMiles'] ?? 0),
            'duration_mins'         => (int)($data['durationMins'] ?? 0),
            'amount'                => 0.00,
            'discount_amount'       => 0.00,
            'promo_code'            => NULL,
            'currency'              => 'USD',
            'first_name'            => $data['firstName'] ?? NULL,
            'last_name'             => $data['lastName'] ?? NULL,
            'email'                 => $data['email'] ?? NULL,
            'phone'                 => $data['phone'] ?? NULL,
            'status'                => 'pending',
            'created_at'            => date('Y-m-d H:i:s'),
            'ip_address'            => $this->input->ip_address(),
        ]);

        return $return_booking_id;
    }

    protected function _new_booking_id()
    {
        return 'KFB-' . strtoupper(base_convert((string)(microtime(true) * 1000), 10, 36));
    }

    /** Normalize child seat breakdown if it's an array → JSON. Returns [count, json|NULL]. */
    protected function _normalize_child_seats(array $data)
    {
        $childSeats = (int)($data['childSeats'] ?? 0);
        $childSeatsBreakdown = NULL;
        if (!empty($data['childSeatsBreakdown']) && is_array($data['childSeatsBreakdown'])) {
            $clean = [];
            foreach ($data['childSeatsBreakdown'] as $type => $qty) {
                $qty = (int)$qty;
                if ($qty > 0) $clean[substr((string)$type, 0, 40)] = $qty;
            }
            if (!empty($clean)) {
                $childSeatsBreakdown = json_encode($clean, JSON_UNESCAPED_UNICODE);
                $childSeats = array_sum($clean);
            }
        } elseif (!empty($data['childSeatsBreakdown']) && is_string($data['childSeatsBreakdown'])) {
            $childSeatsBreakdown = $data['childSeatsBreakdown'];
        }
        return [$childSeats, $childSeatsBreakdown];
    }

    /**
     * Save the e-signature for a booking (base64 PNG).
     * Returns TRUE on success.
     */
    public function save_signature($booking_id, $base64_data, $terms_version = 'v1')
    {
        if (!$booking_id || !$base64_data) return FALSE;
        return $this->db->where('booking_id', $booking_id)->update('kfb_bookings', [
            'signature_data' => $base64_data,
            'signature_ip'   => $this->input->ip_address(),
            'signature_at'   => date('Y-m-d H:i:s'),
            'terms_version'  => $terms_version,
        ]) ? TRUE : FALSE;
    }

    public function get_booking($booking_id)
    {
        $row = $this->db->get_where('kfb_bookings', ['booking_id' => $booking_id])->row_array();
        if (!$row) return NULL;
        $row['stops'] = $this->db
            ->order_by('sequence', 'ASC')
            ->get_where('kfb_stops', ['booking_id' => $booking_id])
            ->result_array();
        $row['payments'] = $this->db
            ->order_by('created_at', 'DESC')
            ->get_where('kfb_payments', ['booking_id' => $booking_id])
            ->result_array();
        $row['return_leg'] = !empty($row['return_booking_id'])
            ? $this->db->get_where('kfb_bookings', ['booking_id' => $row['return_booking_id']])->row_array()
            : NULL;
        return $row;
    }

    /** Log a PayPal lifecycle event (authorize / capture / cancel / additional_charge) against a booking. */
    public function record_payment($booking_id, $paypal_transaction_id, $event, $status, $payload = [])
    {
        $this->db->insert('kfb_payments', [
            'booking_id'             => $booking_id,
            'provider'               => 'paypal',
            'paypal_transaction_id'  => $paypal_transaction_id,
            'event'                  => $event,        // 'authorize' | 'capture' | 'cancel' | 'additional_charge'
            'status'                 => $status,        // PayPal ACK/PAYMENTSTATUS, e.g. 'Success' | 'Completed'
            'amount'                 => $payload['amount'] ?? NULL,
            'currency'               => $payload['currency'] ?? 'USD',
            'raw_response'           => json_encode($payload),
            'created_at'             => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Persist the PayPal order id right after it's created, before the
     * customer is redirected to paypal.com to approve it. Lets the
     * return-URL handler verify the ?token= PayPal sends back actually
     * belongs to this booking.
     */
    public function save_paypal_order($booking_id, $orderId)
    {
        return $this->db->where('booking_id', $booking_id)->update('kfb_bookings', [
            'paypal_order_id' => $orderId,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    /**
     * Persist the result of the initial PayPal authorization (placed once
     * the customer approves the order and PayPal redirects back) on a
     * booking and mark it awaiting admin approval. Only touches the leg
     * that was actually charged — see the class doc on _create_return_leg()
     * for why the return leg doesn't carry its own payment info.
     */
    public function save_paypal_auth($booking_id, array $data)
    {
        return $this->db->where('booking_id', $booking_id)->update('kfb_bookings', [
            'paypal_auth_transaction_id' => $data['paypal_auth_transaction_id'] ?? NULL,
            'card_brand'                 => $data['card_brand'] ?? NULL,
            'card_last4'                 => $data['card_last4'] ?? NULL,
            'status'                     => 'awaiting_approval',
            'updated_at'                 => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    /** Persist the DoCapture transaction id — used as the reference for later DoReferenceTransaction charges. */
    public function save_paypal_capture($booking_id, $captureTransactionId)
    {
        return $this->db->where('booking_id', $booking_id)->update('kfb_bookings', [
            'paypal_capture_transaction_id' => $captureTransactionId,
            'updated_at'                    => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    /**
     * List bookings for the admin reservations screen. Return-trip legs are
     * synthetic rows (amount always 0, see _create_return_leg()) that ride
     * on the outbound leg's payment — they're excluded here and shown
     * nested under the outbound booking's detail page instead.
     */
    public function list_all(array $filters = [])
    {
        $this->db->where('NOT (is_return_trip = 1 AND amount = 0)', NULL, FALSE);
        if (!empty($filters['status'])) $this->db->where('status', $filters['status']);
        return $this->db->order_by('created_at', 'DESC')->get('kfb_bookings')->result_array();
    }

    /** Record an admin accept/reject decision: status + audit stamp, cascading to a linked return leg. */
    public function set_approval($booking_id, $status, $admin_username)
    {
        $this->update_booking_status($booking_id, $status);

        $ids = [$booking_id];
        $row = $this->db->select('return_booking_id')->get_where('kfb_bookings', ['booking_id' => $booking_id])->row_array();
        if (!empty($row['return_booking_id'])) $ids[] = $row['return_booking_id'];

        $this->db->where_in('booking_id', $ids)->update('kfb_bookings', [
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $admin_username,
        ]);
    }

    public function update_booking_status($booking_id, $status)
    {
        $this->db->where('booking_id', $booking_id)->update('kfb_bookings', [
            'status'         => $status,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        // Return-trip bookings are two linked rows charged as a single PayPal
        // authorization — keep the linked leg's status in sync with this one.
        $row = $this->db->select('return_booking_id')->get_where('kfb_bookings', ['booking_id' => $booking_id])->row_array();
        if (!empty($row['return_booking_id'])) {
            $this->db->where('booking_id', $row['return_booking_id'])->update('kfb_bookings', [
                'status'         => $status,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** Get the enabled fleet from kfb_vehicles (DB-driven, replaces the old static list). */
    public function get_fleet()
    {
        $rows = $this->db
            ->where('status', 1)
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('kfb_vehicles')
            ->result_array();
        if (empty($rows)) return [];

        return array_map(function ($row) {
            return [
                // Public identity (matches the embed widget's existing shape)
                'id'        => $row['code'] ?: ('v' . $row['id']),
                'name'      => $row['name'],
                'desc'      => $row['description'] ?: '',
                'capacity'  => (int)$row['max_passengers'],
                'luggage'   => (int)($row['luggage_capacity'] ?? 0),
                'emoji'     => $row['emoji'] ?: '🚖',
                'image'     => $row['image'] ?: NULL,
                'min_passengers' => (int)$row['min_passengers'],
                'max_passengers' => (int)$row['max_passengers'],

                // Region-scoped rates (used by the widget's priceFor()).
                // Keys are <field>_<region> with region ∈ chicago/america/worldwide.
                'hourly_chicago'   => (float)$row['hourly_chicago'],
                'hourly_america'   => (float)$row['hourly_america'],
                'hourly_worldwide' => (float)$row['hourly_worldwide'],

                'per_km_chicago'   => (float)$row['per_km_chicago'],
                'per_km_america'   => (float)$row['per_km_america'],
                'per_km_worldwide' => (float)$row['per_km_worldwide'],

                'surcharge_chicago'   => (float)$row['surcharge_chicago'],
                'surcharge_america'   => (float)$row['surcharge_america'],
                'surcharge_worldwide' => (float)$row['surcharge_worldwide'],

                'gratuity_chicago'   => (float)$row['gratuity_chicago'],
                'gratuity_america'   => (float)$row['gratuity_america'],
                'gratuity_worldwide' => (float)$row['gratuity_worldwide'],

                'waiting_chicago'   => (float)$row['waiting_chicago'],
                'waiting_america'   => (float)$row['waiting_america'],
                'waiting_worldwide' => (float)$row['waiting_worldwide'],

                'child_seat_chicago'   => (float)($row['child_seat_chicago']   ?? 0),
                'child_seat_america'   => (float)($row['child_seat_america']   ?? 0),
                'child_seat_worldwide' => (float)($row['child_seat_worldwide'] ?? 0),

                'min_fare'             => (float)($row['min_fare']             ?? 0),
            ];
        }, $rows);
    }
}
