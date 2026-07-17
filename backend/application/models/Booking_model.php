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

    /** Create a new booking. Returns the booking_id. */
    public function create_booking(array $data)
    {
        $booking_id = 'KFB-' . strtoupper(base_convert((string)(microtime(true) * 1000), 10, 36));

        // Normalize child seat breakdown if it's an array → JSON
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
                // recount total from the breakdown if provided
                $childSeats = array_sum($clean);
            }
        } elseif (!empty($data['childSeatsBreakdown']) && is_string($data['childSeatsBreakdown'])) {
            $childSeatsBreakdown = $data['childSeatsBreakdown'];
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

        // Stops
        if (!empty($data['stops']) && is_array($data['stops'])) {
            foreach (array_values($data['stops']) as $i => $addr) {
                $this->db->insert('kfb_stops', [
                    'booking_id' => $booking_id,
                    'sequence'   => $i,
                    'address'    => $addr,
                ]);
            }
        }
        return $booking_id;
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
        return $row;
    }

    public function record_payment($booking_id, $paypal_order_id, $event, $status, $payload = [])
    {
        $this->db->insert('kfb_payments', [
            'booking_id'      => $booking_id,
            'paypal_order_id' => $paypal_order_id,
            'event'           => $event,        // 'create' | 'capture' | 'refund'
            'status'          => $status,        // 'CREATED' | 'COMPLETED' | 'FAILED'
            'amount'          => $payload['amount'] ?? NULL,
            'currency'        => $payload['currency'] ?? 'USD',
            'raw_response'    => json_encode($payload),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public function update_booking_status($booking_id, $status, $paypal_order_id = NULL)
    {
        $this->db->where('booking_id', $booking_id)->update('kfb_bookings', [
            'status'         => $status,
            'paypal_order_id'=> $paypal_order_id,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
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
            ];
        }, $rows);
    }
}
