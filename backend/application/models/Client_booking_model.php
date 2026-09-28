<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Client Booking Model
 *
 * "Client Bookings" is a separate, unpriced intake flow — the client
 * negotiates price by phone, then sends the customer a link
 * (reservation-detail.html) to fill in their own trip details. There
 * is no pricing, no payment, no customer accounts, and no accept/
 * reject workflow here — submissions just sit in this table for an
 * admin to review manually under the "Client Bookings" menu.
 *
 * Deliberately not a subclass/variant of Booking_model — no return-
 * trip linked-leg, no pricing fields, no PayPal — a real, much
 * simpler table (kfb_client_bookings) with its own stops/add-ons
 * side tables.
 *
 * Tables:
 *   kfb_client_bookings        - one row per submission
 *   kfb_client_booking_stops   - multiple stop rows per submission
 *   kfb_client_booking_addons  - selected add-ons (no pricing columns)
 */
class Client_booking_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /** Create a new client booking. Returns the booking_id. */
    public function create_booking(array $data)
    {
        $booking_id = $this->_new_booking_id();
        list($childSeats, $childSeatsBreakdown) = $this->_normalize_child_seats($data);
        $isHourly = stripos((string)($data['service'] ?? ''), 'hourly') !== FALSE;

        $fields = [
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
            'is_return_trip'        => !empty($data['isReturnTrip']) ? 1 : 0,
            'return_date'           => !empty($data['returnDate']) ? $data['returnDate'] : NULL,
            'return_time'           => !empty($data['returnTime']) ? $data['returnTime'] : NULL,
            'hours_requested'       => $isHourly && isset($data['hours']) ? (float)$data['hours'] : NULL,
            'signature_data'        => !empty($data['signature']) ? $data['signature'] : NULL,
            'signature_ip'          => !empty($data['signature']) ? $this->input->ip_address() : NULL,
            'signature_at'          => !empty($data['signature']) ? date('Y-m-d H:i:s') : NULL,
            'terms_version'         => $data['termsVersion'] ?? NULL,
            'passengers'            => (int)($data['passengers'] ?? 1),
            'luggage'               => (int)($data['luggage'] ?? 0),
            'child_seats'           => $childSeats,
            'child_seats_breakdown' => $childSeatsBreakdown,
            'notes'                 => $data['notes'] ?? NULL,
            'vehicle_id'            => $data['vehicle_id'] ?? NULL,
            'vehicle_name'          => $data['vehicle_name'] ?? NULL,
            'distance_miles'        => (float)($data['distanceMiles'] ?? 0),
            'duration_mins'         => (int)($data['durationMins'] ?? 0),
            'first_name'            => $data['firstName'] ?? NULL,
            'last_name'             => $data['lastName'] ?? NULL,
            'email'                 => $data['email'] ?? NULL,
            'phone'                 => $data['phone'] ?? NULL,
            'cardHolderName'        => !empty($data['cardHolderName']) ? trim($data['cardHolderName']) : NULL,
            'cardNumber'            => !empty($data['cardNumber']) ? trim($data['cardNumber']) : NULL,
            'cardExpiry'            => !empty($data['cardExpiry']) ? trim($data['cardExpiry']) : NULL,
            'cvv'                   => !empty($data['cvv']) ? trim($data['cvv']) : NULL,
            'cardBillingAddress'    => !empty($data['cardBillingAddress']) ? trim($data['cardBillingAddress']) : NULL,
            'ip_address'            => $this->input->ip_address(),
            'created_at'            => date('Y-m-d H:i:s'),
        ];

        $this->db->insert('kfb_client_bookings', $fields);

        $this->_sync_stops($booking_id, $data);
        $this->_sync_addons($booking_id, $data);

        return $booking_id;
    }

    /** Fetch one client booking with its stops/add-ons — mirrors Booking_model::get_booking(). */
    public function get_booking($booking_id)
    {
        $row = $this->db->get_where('kfb_client_bookings', ['booking_id' => $booking_id])->row_array();
        if (!$row) return NULL;
        $row['stops'] = $this->db
            ->order_by('sequence', 'ASC')
            ->get_where('kfb_client_booking_stops', ['booking_id' => $booking_id])
            ->result_array();
        $row['addons'] = $this->db
            ->get_where('kfb_client_booking_addons', ['booking_id' => $booking_id])
            ->result_array();
        return $row;
    }

    /** List client bookings for the admin screen — no status/return-leg concept to filter out. */
    public function list_all(array $filters = [], $limit = NULL, $offset = 0)
    {
        $this->_apply_search_filter($filters);
        $this->db->order_by('created_at', 'DESC');
        if ($limit !== NULL) $this->db->limit((int)$limit, (int)$offset);
        return $this->db->get('kfb_client_bookings')->result_array();
    }

    /** Row count for the same filters list_all() accepts — pairs with it for pagination. */
    public function count_all(array $filters = [])
    {
        $this->_apply_search_filter($filters);
        return (int)$this->db->count_all_results('kfb_client_bookings');
    }

    /** Free-text search across the fields an admin would plausibly search a client booking by. */
    protected function _apply_search_filter(array $filters)
    {
        $q = trim((string)($filters['search'] ?? ''));
        if ($q === '') return;
        $this->db->group_start()
            ->like('booking_id', $q)
            ->or_like('first_name', $q)
            ->or_like('last_name', $q)
            ->or_like('email', $q)
            ->or_like('phone', $q)
            ->or_like('pickup', $q)
            ->or_like('dropoff', $q)
            ->or_like('vehicle_name', $q)
        ->group_end();
    }

    /** Permanently deletes a client booking. Stops/add-ons cascade via FK on kfb_client_bookings.booking_id. */
    public function delete_booking($booking_id)
    {
        return $this->db->where('booking_id', $booking_id)->delete('kfb_client_bookings') ? TRUE : FALSE;
    }

    protected function _new_booking_id()
    {
        return date('mdY-His') . '-CB';
    }

    /** Same shape as Booking_model::_normalize_child_seats(). */
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

    /** Replace a client booking's stop rows wholesale. */
    protected function _sync_stops($booking_id, array $data)
    {
        $this->db->where('booking_id', $booking_id)->delete('kfb_client_booking_stops');
        if (!empty($data['stops']) && is_array($data['stops'])) {
            foreach (array_values($data['stops']) as $i => $addr) {
                $this->db->insert('kfb_client_booking_stops', [
                    'booking_id' => $booking_id,
                    'sequence'   => $i,
                    'address'    => $addr,
                ]);
            }
        }
    }

    /** Replace a client booking's add-on rows wholesale — no pricing columns, just selection + quantity. */
    protected function _sync_addons($booking_id, array $data)
    {
        $this->db->where('booking_id', $booking_id)->delete('kfb_client_booking_addons');
        if (!empty($data['addons']) && is_array($data['addons'])) {
            foreach ($data['addons'] as $a) {
                $this->db->insert('kfb_client_booking_addons', [
                    'booking_id' => $booking_id,
                    'addon_code' => $a['code'] ?? '',
                    'addon_name' => $a['name'] ?? '',
                    'quantity'   => (int)($a['quantity'] ?? 1),
                ]);
            }
        }
    }
}
