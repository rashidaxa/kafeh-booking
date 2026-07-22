<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Booking — public REST API
 *
 * Routes (defined in application/config/routes.php):
 *   GET  /api/fleet                       → list vehicles
 *   POST /api/reservation                 → create booking (returns booking_id)
 *   GET  /api/reservation/:id             → fetch booking detail
 *   POST /api/paypal/create-order         → start PayPal order
 *   POST /api/paypal/capture-order/:oid   → complete PayPal order
 *   GET  /api/health                      → health check
 *
 * CORS: open by default (set $allowed_origins in __construct to lock down).
 */

class Api extends CI_Controller
{
    /** Comma-separated list of allowed origins, or ['*'] for any. */
    protected $allowed_origins = ['*'];

    public function __construct()
    {
        parent::__construct();
        $this->load->model(['Booking_model', 'Promo_model']);
        $this->load->library('paypal');
        $this->_set_cors_headers();
    }

    // ----------------- ROUTES -----------------

    public function fleet()
    {
        $this->_json($this->Booking_model->get_fleet());
    }

    public function health()
    {
        $this->_json([
            'ok'    => TRUE,
            'env'   => $this->config->item('environment', 'paypal'),
            'time'  => date('c'),
            'php'   => PHP_VERSION,
        ]);
    }

    /** POST /api/reservation  { ...ride fields, vehicle, amount, customer } */
    public function reservation_create()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);

        $required = ['service', 'pickupDate', 'pickupTime', 'pickup',
                     'passengers', 'vehicle_id', 'amount',
                     'firstName', 'lastName', 'email', 'phone'];
        foreach ($required as $field) {
            if (!isset($raw[$field]) || $raw[$field] === '') {
                return $this->_error("Missing field: $field", 422);
            }
        }
        // dropoff can be missing if "return at same location" — fall back to pickup
        if (empty($raw['dropoff'])) $raw['dropoff'] = $raw['pickup'];
        if (!filter_var($raw['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->_error('Invalid email address', 422);
        }

        $booking_id = $this->Booking_model->create_booking($raw);

        $this->_json([
            'success'    => TRUE,
            'booking_id' => $booking_id,
            'status'     => 'pending',
        ], 201);
    }

    /** GET /api/reservation/:id */
    public function reservation_get($id = NULL)
    {
        if (!$id) return $this->_error('Booking ID required', 400);
        $row = $this->Booking_model->get_booking($id);
        if (!$row) return $this->_error('Booking not found', 404);
        $this->_json($row);
    }

    /** POST /api/paypal/create-order
     *  Body: { amount, bookingId, description, customer, ride, vehicle, distanceMiles, durationMins } */
    public function paypal_create_order()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);

        $amount    = $raw['amount'] ?? NULL;
        $bookingId = $raw['bookingId'] ?? NULL;
        $description = $raw['description'] ?? 'Chauffeur booking';

        if (!$amount || !$bookingId) {
            return $this->_error('amount and bookingId are required', 422);
        }

        try {
            $order = $this->paypal->createOrder($amount, $bookingId, $description);
        } catch (Exception $e) {
            log_message('error', '[Booking] createOrder: ' . $e->getMessage());
            return $this->_error('PayPal create-order failed', 502, ['detail' => $e->getMessage()]);
        }

        // Persist
        $this->Booking_model->record_payment($bookingId, $order['id'], 'create', 'CREATED', [
            'amount'   => $amount,
            'currency' => 'USD',
            'order'    => $order,
        ]);
        $this->Booking_model->update_booking_status($bookingId, 'awaiting_payment', $order['id']);

        $this->_json([
            'id'        => $order['id'],
            'status'    => $order['status'] ?? 'CREATED',
            'bookingId' => $bookingId,
        ], 201);
    }

    /** POST /api/paypal/capture-order/:orderId */
    public function paypal_capture_order($orderId = NULL)
    {
        if (!$orderId) return $this->_error('Order ID required', 400);

        try {
            $result = $this->paypal->captureOrder($orderId);
        } catch (Exception $e) {
            log_message('error', '[Booking] captureOrder: ' . $e->getMessage());
            return $this->_error('PayPal capture failed', 502, ['detail' => $e->getMessage()]);
        }

        // Find booking by order_id
        $booking = $this->db->get_where('kfb_bookings', ['paypal_order_id' => $orderId])->row_array();
        $bookingId = $booking['booking_id'] ?? NULL;

        $status = $result['status'] ?? 'UNKNOWN';
        $success = ($status === 'COMPLETED');

        if ($bookingId) {
            $this->Booking_model->record_payment($bookingId, $orderId, 'capture', $status, [
                'amount'   => $booking['amount'] ?? NULL,
                'currency' => 'USD',
                'result'   => $result,
            ]);
            $this->Booking_model->update_booking_status(
                $bookingId,
                $success ? 'paid' : 'payment_failed',
                $orderId
            );

            // Bump promo usage counter on successful payment
            if ($success && !empty($booking['promo_code'])) {
                $this->Promo_model->record_booking_use($booking['promo_code']);
            }

            // Optional: send confirmation email / SMS
            if ($success && $this->config->item('send_confirmation_email', 'kafeh')) {
                $this->_send_confirmation($booking, $result);
            }
        }

        $this->_json([
            'success'      => $success,
            'status'       => $status,
            'bookingId'    => $bookingId,
            'paypalOrderId'=> $orderId,
        ]);
    }

    /**
     * GET /api/promo/validate?code=XYZ&amount=189.50
     * Returns { ok, code, discount, final, reason, description } so the
     * embed widget can display the discount before sending the booking.
     */
    public function promo_validate()
    {
        $code   = trim((string)($this->input->get('code') ?? ''));
        $amount = (float)($this->input->get('amount') ?? 0);
        if ($code === '') {
            return $this->_error('Promo code is required', 422);
        }
        $result = $this->Promo_model->validate($code, $amount);
        $this->_json(array_merge(['success' => TRUE], $result));
    }

    // ----------------- helpers -----------------

    protected function _send_confirmation($booking, $paypalResult)
    {
        $to      = $booking['email'];
        $subject = 'Booking ' . $booking['booking_id'] . ' Confirmed';
        $body    =
            "Hi {$booking['first_name']},\n\n" .
            "Your booking is confirmed.\n\n" .
            "Booking ID: {$booking['booking_id']}\n" .
            "Service:    {$booking['service_type']}\n" .
            "Pickup:     {$booking['pickup_date']} at {$booking['pickup_time']}\n" .
            "From:       {$booking['pickup']}\n" .
            "To:         {$booking['dropoff']}\n" .
            "Vehicle:    {$booking['vehicle_name']}\n" .
            "Amount:     \${$booking['amount']}\n" .
            (!empty($booking['promo_code'])
                ? "Promo:      {$booking['promo_code']} (-\${$booking['discount_amount']})\n"
                : "") .
            "\nThank you for choosing our chauffeur service.\n";
        $headers = "From: no-reply@bookings.local\r\n";

        // Best-effort. If your server doesn't have mail() configured, swap for SMTP.
        @mail($to, $subject, $body, $headers);
    }

    protected function _read_json()
    {
        $raw = $this->input->raw_input_stream;
        if (!$raw) $raw = file_get_contents('php://input');
        $data = json_decode($raw, TRUE);
        return is_array($data) ? $data : NULL;
    }

    protected function _set_cors_headers()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
        if (in_array('*', $this->allowed_origins, TRUE)) {
            header('Access-Control-Allow-Origin: *');
        } elseif (in_array($origin, $this->allowed_origins, TRUE)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
        header('Content-Type: application/json; charset=utf-8');

        if ($this->input->method() === 'options') {
            header('HTTP/1.1 204 No Content');
            exit;
        }
    }

    protected function _json($data, $code = 200)
    {
        $this->output
            ->set_status_header($code)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function _error($message, $code = 400, $extra = [])
    {
        $this->_json(array_merge(['success' => FALSE, 'error' => $message], $extra), $code);
    }
}
