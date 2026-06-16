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

        $this->db->insert('kfb_bookings', [
            'booking_id'     => $booking_id,
            'service_type'   => $data['service'] ?? NULL,
            'pickup_date'    => $data['pickupDate'] ?? NULL,
            'pickup_time'    => $data['pickupTime'] ?? NULL,
            'pickup'         => $data['pickup'] ?? NULL,
            'dropoff'        => $data['dropoff'] ?? NULL,
            'passengers'     => (int)($data['passengers'] ?? 1),
            'luggage'        => (int)($data['luggage'] ?? 0),
            'child_seats'    => (int)($data['childSeats'] ?? 0),
            'notes'          => $data['notes'] ?? NULL,
            'vehicle_id'     => $data['vehicle_id'] ?? NULL,
            'vehicle_name'   => $data['vehicle_name'] ?? NULL,
            'distance_miles' => (float)($data['distanceMiles'] ?? 0),
            'duration_mins'  => (int)($data['durationMins'] ?? 0),
            'amount'         => (float)($data['amount'] ?? 0),
            'currency'       => 'USD',
            'first_name'     => $data['firstName'] ?? NULL,
            'last_name'      => $data['lastName'] ?? NULL,
            'email'          => $data['email'] ?? NULL,
            'phone'          => $data['phone'] ?? NULL,
            'status'         => 'pending',
            'created_at'     => date('Y-m-d H:i:s'),
            'ip_address'     => $this->input->ip_address(),
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

    /** Get default sample fleet (you can replace with DB later). */
    public function get_fleet()
    {
        return [
            ['id' => 'sedan',    'name' => 'Luxury Sedan',      'desc' => 'Mercedes E-Class / BMW 5',       'capacity' => 3,  'luggage' => 3,  'basePrice' => 95,  'perMile' => 3.5, 'emoji' => '🚘'],
            ['id' => 'suv',      'name' => 'Premium SUV',       'desc' => 'Cadillac Escalade / Suburban',    'capacity' => 6,  'luggage' => 6,  'basePrice' => 145, 'perMile' => 4.5, 'emoji' => '🚙'],
            ['id' => 'sprinter', 'name' => 'Luxury Sprinter',   'desc' => 'Executive van — groups up to 12', 'capacity' => 12, 'luggage' => 10, 'basePrice' => 220, 'perMile' => 5.5, 'emoji' => '🚐'],
            ['id' => 'limo',     'name' => 'Stretch Limousine', 'desc' => 'Lincoln Stretch — VIP nights',    'capacity' => 10, 'luggage' => 6,  'basePrice' => 320, 'perMile' => 6.0, 'emoji' => '🏁'],
        ];
    }
}
