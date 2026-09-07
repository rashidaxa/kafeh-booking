<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API Model
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

        list($addonsJson, $addonsTotal) = $this->_normalize_addons($data);

        $fields = $this->_shared_booking_fields($data, $childSeats, $childSeatsBreakdown, $addonsJson, $addonsTotal);
        $fields['booking_id']        = $booking_id;
        $fields['return_booking_id'] = $return_booking_id;
        $fields['status']            = 'pending';
        $fields['created_at']        = date('Y-m-d H:i:s');
        $fields['ip_address']        = $this->input->ip_address();

        $this->db->insert('kfb_bookings', $fields);

        $this->_sync_stops($booking_id, $data);
        $this->_sync_addons($booking_id, $data);

        return $booking_id;
    }

    /**
     * Update an existing (pre-acceptance) booking in place — the edit
     * counterpart to create_booking(). Reuses the exact same field
     * mapping via _shared_booking_fields() so the two flows can't drift
     * apart. Reconciles the return-trip leg (create/update/delete as
     * needed) and fully replaces stops/add-ons rather than diffing them,
     * same as a fresh create.
     */
    public function update_booking($booking_id, array $data)
    {
        $existing = $this->db->get_where('kfb_bookings', ['booking_id' => $booking_id])->row_array();
        if (!$existing) return FALSE;

        list($childSeats, $childSeatsBreakdown) = $this->_normalize_child_seats($data);
        list($addonsJson, $addonsTotal) = $this->_normalize_addons($data);

        // Reconcile the return-trip leg before writing the outbound row,
        // so its return_booking_id is correct in the same pass.
        $return_booking_id = $existing['return_booking_id'];
        if (!empty($data['isReturnTrip'])) {
            $legFields = $this->_return_leg_fields($data, $booking_id, $childSeats, $childSeatsBreakdown);
            if ($return_booking_id) {
                $legFields['updated_at'] = date('Y-m-d H:i:s');
                $this->db->where('booking_id', $return_booking_id)->update('kfb_bookings', $legFields);
            } else {
                $return_booking_id = $this->_create_return_leg($data, $booking_id, $childSeats, $childSeatsBreakdown);
            }
        } elseif ($return_booking_id) {
            // Return trip turned off in this edit — drop the linked leg.
            // kfb_stops/kfb_booking_addons/kfb_payments all cascade on delete.
            $this->db->where('booking_id', $return_booking_id)->delete('kfb_bookings');
            $return_booking_id = NULL;
        }

        $fields = $this->_shared_booking_fields($data, $childSeats, $childSeatsBreakdown, $addonsJson, $addonsTotal);
        $fields['return_booking_id'] = $return_booking_id;
        $fields['updated_at']        = date('Y-m-d H:i:s');

        $this->db->where('booking_id', $booking_id)->update('kfb_bookings', $fields);

        $this->_sync_stops($booking_id, $data);
        $this->_sync_addons($booking_id, $data);

        return TRUE;
    }

    /** Stamp that the customer edited this reservation (pre-acceptance). */
    public function mark_edited_by_customer($booking_id)
    {
        $this->db->set('edit_count', '`edit_count`+1', FALSE)
            ->where('booking_id', $booking_id)
            ->update('kfb_bookings', ['edited_by_customer_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Null out a booking's PayPal/card fields — called when an edit
     * changes the price and the old authorization has just been voided,
     * so a stale auth/capture id can't be mistaken for a live one.
     * Clears the linked return leg too, for symmetry (it never carries
     * its own payment info, but keep both rows consistent regardless).
     */
    public function clear_paypal_auth($booking_id)
    {
        $fields = [
            'paypal_order_id'               => NULL,
            'paypal_auth_transaction_id'    => NULL,
            'paypal_capture_transaction_id' => NULL,
            'card_brand'                    => NULL,
            'card_last4'                    => NULL,
            'updated_at'                    => date('Y-m-d H:i:s'),
        ];
        $this->db->where('booking_id', $booking_id)->update('kfb_bookings', $fields);

        $row = $this->db->select('return_booking_id')->get_where('kfb_bookings', ['booking_id' => $booking_id])->row_array();
        if (!empty($row['return_booking_id'])) {
            $this->db->where('booking_id', $row['return_booking_id'])->update('kfb_bookings', $fields);
        }
    }

    /**
     * List a customer's own reservations for the widget's Profile view,
     * with a server-computed `editable` flag (never re-derived from
     * status client-side) — the same exclusion of synthetic return-trip
     * rows list_all() uses.
     */
    public function list_for_customer($customer_id)
    {
        $rows = $this->db
            ->where('customer_id', (int)$customer_id)
            ->where('NOT (is_return_trip = 1 AND amount = 0)', NULL, FALSE)
            ->order_by('created_at', 'DESC')
            ->get('kfb_bookings')
            ->result_array();

        foreach ($rows as &$row) {
            $row['editable'] = !in_array($row['status'], ['paid', 'cancelled', 'refunded'], TRUE);
        }
        unset($row);
        return $rows;
    }

    /**
     * Field mapping shared by create_booking() and update_booking() for
     * the outbound leg — everything except the creation-only columns
     * (booking_id, status, created_at, ip_address) and return_booking_id
     * (reconciled separately by the caller).
     */
    protected function _shared_booking_fields(array $data, $childSeats, $childSeatsBreakdown, $addonsJson, $addonsTotal)
    {
        return [
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
            // Price breakdown (v16) — populated by Pricing_engine::quote() in
            // Api::reservation_create() before this method is called, so the
            // server-computed breakdown (not just the final amount) is
            // always what gets persisted.
            'pricing_zone'            => $data['pricingZone'] ?? NULL,
            'travel_fee_amount'       => (float)($data['travelFeeAmount'] ?? 0),
            'surcharges_total_amount' => (float)($data['surchargesTotalAmount'] ?? 0),
            'gratuity_pct'            => (float)($data['gratuityPct'] ?? 0),
            'gratuity_amount'         => (float)($data['gratuityAmount'] ?? 0),
            'child_seats_fee_amount'  => (float)($data['childSeatsFeeAmount'] ?? 0),
            'is_return_trip'        => !empty($data['isReturnTrip']) ? 1 : 0,
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
            'cardHolderName'        => !empty($data['cardHolderName']) ? trim($data['cardHolderName']) : NULL,
            'cardNumber'            => !empty($data['cardNumber']) ? trim($data['cardNumber']) : NULL,
            'cardExpiry'            => !empty($data['cardExpiry']) ? trim($data['cardExpiry']) : NULL,
            'cvv'                   => !empty($data['cvv']) ? trim($data['cvv']) : NULL,
            'cardBillingAddress'    => !empty($data['cardBillingAddress']) ? trim($data['cardBillingAddress']) : NULL,
        ];
    }

    /** Normalize add-ons if provided. Returns [json|NULL, total]. */
    protected function _normalize_addons(array $data)
    {
        if (empty($data['addons']) || !is_array($data['addons'])) return [NULL, 0.0];
        $addonsJson = json_encode(array_values($data['addons']), JSON_UNESCAPED_UNICODE);
        $addonsTotal = 0.0;
        foreach ($data['addons'] as $a) {
            $addonsTotal += (float)($a['line_total'] ?? 0);
        }
        return [$addonsJson, $addonsTotal];
    }

    /** Replace a booking's kfb_stops rows wholesale (used by both create and update). */
    protected function _sync_stops($booking_id, array $data)
    {
        $this->db->where('booking_id', $booking_id)->delete('kfb_stops');
        if (!empty($data['stops']) && is_array($data['stops'])) {
            foreach (array_values($data['stops']) as $i => $addr) {
                $this->db->insert('kfb_stops', [
                    'booking_id' => $booking_id,
                    'sequence'   => $i,
                    'address'    => $addr,
                ]);
            }
        }
    }

    /** Replace a booking's kfb_booking_addons rows wholesale (used by both create and update). */
    protected function _sync_addons($booking_id, array $data)
    {
        $this->db->where('booking_id', $booking_id)->delete('kfb_booking_addons');
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
        $return_booking_id = $this->_new_booking_id('RT');

        $fields = $this->_return_leg_fields($data, $primary_booking_id, $childSeats, $childSeatsBreakdown);
        $fields['booking_id'] = $return_booking_id;
        $fields['status']     = 'pending';
        $fields['created_at'] = date('Y-m-d H:i:s');
        $fields['ip_address'] = $this->input->ip_address();

        $this->db->insert('kfb_bookings', $fields);

        return $return_booking_id;
    }

    /**
     * Field mapping for a return leg — shared by _create_return_leg() and
     * update_booking() (which updates an existing leg in place rather
     * than recreating it). See _create_return_leg()'s docblock for why
     * pickup/dropoff are swapped and fare/add-ons/promo stay at 0.
     */
    protected function _return_leg_fields(array $data, $primary_booking_id, $childSeats, $childSeatsBreakdown)
    {
        return [
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
        ];
    }

    /**
     * MMDDYYYY-HHMMSS-<suffix>, e.g. 08162026-132107-OP.
     * $suffix marks how the booking originated — "OP" (Online Payment) for
     * the normal customer-facing flow. _create_return_leg() passes "RT" so
     * a round trip's two legs (generated seconds apart in the same request)
     * can never collide on this table's primary key.
     */
    protected function _new_booking_id($suffix = 'OP')
    {
        return date('mdY-His') . '-' . $suffix;
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
    public function list_all(array $filters = [], $limit = NULL, $offset = 0)
    {
        $this->db->where('NOT (is_return_trip = 1 AND amount = 0)', NULL, FALSE);
        if (!empty($filters['status'])) $this->db->where('status', $filters['status']);
        $this->db->order_by('created_at', 'DESC');
        if ($limit !== NULL) $this->db->limit((int)$limit, (int)$offset);
        return $this->db->get('kfb_bookings')->result_array();
    }

    /** Row count for the same filters list_all() accepts — pairs with it for pagination. */
    public function count_all(array $filters = [])
    {
        $this->db->where('NOT (is_return_trip = 1 AND amount = 0)', NULL, FALSE);
        if (!empty($filters['status'])) $this->db->where('status', $filters['status']);
        return (int)$this->db->count_all_results('kfb_bookings');
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

    /**
     * Reservation counts by status, for the admin dashboard. Excludes the
     * synthetic return-trip rows (see list_all()) so a round-trip booking
     * counts once, not twice.
     */
    public function count_by_status()
    {
        $this->db->select('status, COUNT(*) AS cnt', FALSE);
        $this->db->where('NOT (is_return_trip = 1 AND amount = 0)', NULL, FALSE);
        $this->db->group_by('status');
        $rows = $this->db->get('kfb_bookings')->result_array();

        $counts = ['total' => 0];
        foreach ($rows as $r) {
            $counts[$r['status']] = (int)$r['cnt'];
            $counts['total'] += (int)$r['cnt'];
        }
        return $counts;
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

                // Local rates (v16) — informational only ("starting at $X").
                // Real pricing for any trip is computed server-side by
                // Pricing_engine via POST /api/pricing/quote, which derives
                // regional/long-distance/worldwide prices from these via
                // global multipliers rather than the widget doing any math.
                //
                // Formatted STRINGs, not floats — this server's php.ini has
                // serialize_precision=100 (non-default; should be -1), so
                // json_encode() expands any float that isn't exactly
                // representable in binary out to ~100 digits regardless of
                // round(). number_format() sidesteps the float serializer —
                // same fix as Api::reservation_update()'s amount.
                'local_per_mile_rate'    => number_format((float)$row['local_per_mile_rate'], 2, '.', ''),
                'local_hourly_rate'      => number_format((float)$row['local_hourly_rate'], 2, '.', ''),
                'local_hourly_min_hours' => isset($row['local_hourly_min_hours']) && $row['local_hourly_min_hours'] !== NULL
                                                ? number_format((float)$row['local_hourly_min_hours'], 2, '.', '') : NULL,
                'local_min_fare'         => number_format((float)($row['local_min_fare'] ?? 0), 2, '.', ''),
            ];
        }, $rows);
    }
}
