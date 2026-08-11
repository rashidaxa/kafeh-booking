<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Booking — public REST API
 *
 * Routes (defined in application/config/routes.php):
 *   GET  /api/fleet                       → list vehicles
 *   GET  /api/settings                    → global settings (Meet & Greet fees)
 *   POST /api/reservation                 → create booking (returns booking_id)
 *   GET  /api/reservation/:id             → fetch booking detail
 *   POST /api/stripe/create-intent        → authorize (hold) payment, save card
 *   POST /api/stripe/finalize             → confirm authorization succeeded
 *   GET  /api/health                      → health check
 *
 * Payments are authorized only here — an admin must accept the
 * reservation (see Admin_api::reservations_accept) before the held
 * funds are actually captured. See backend/application/libraries/Stripe.php.
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
        $this->load->model(['Booking_model', 'Promo_model', 'Addon_model', 'Customer_model', 'Settings_model']);
        $this->load->library(['stripe', 'flights']);
        $this->_set_cors_headers();
    }

    // ----------------- ROUTES -----------------

    public function fleet()
    {
        $this->_json($this->Booking_model->get_fleet());
    }

    /**
     * GET /api/settings
     * Public global settings the widget needs before a vehicle is picked —
     * currently just the Meet & Greet fee (Chicago vs. all other airports).
     */
    public function settings()
    {
        $this->_json(array_merge(['success' => TRUE], $this->Settings_model->meet_greet_fees()));
    }

    public function health()
    {
        $this->_json([
            'ok'    => TRUE,
            'env'   => ENVIRONMENT,
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

        // Auto-link to a customer record (creates one if new)
        if (!empty($raw['email'])) {
            $customerId = $this->Customer_model->upsert_from_booking([
                'email'      => $raw['email'],
                'first_name' => $raw['firstName'] ?? '',
                'last_name'  => $raw['lastName']  ?? '',
                'phone'      => $raw['phone']      ?? '',
            ]);
            if ($customerId) {
                $this->db->where('booking_id', $booking_id)->update('kfb_bookings', ['customer_id' => $customerId]);
            }
        }

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

    /**
     * POST /api/stripe/create-intent
     * Body: { booking_id, amount }
     * Authorizes (holds) the full trip amount and saves the card on a
     * Stripe Customer for future off-session charges. Does NOT charge the
     * customer — funds are only captured once an admin accepts the
     * reservation (Admin_api::reservations_accept).
     * Returns { client_secret, payment_intent_id } so the frontend can
     * confirm with Stripe.js (which also handles any 3-D Secure challenge).
     */
    public function stripe_create_intent()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);

        $bookingId = trim((string)($raw['booking_id'] ?? ''));
        $amount    = $raw['amount'] ?? NULL;
        if ($bookingId === '' || !$amount) {
            return $this->_error('booking_id and amount are required', 422);
        }

        $booking = $this->db->get_where('kfb_bookings', ['booking_id' => $bookingId])->row_array();
        if (!$booking) return $this->_error('Booking not found', 404);

        try {
            $customerId = $this->stripe->findOrCreateCustomer(
                $booking['email'],
                trim($booking['first_name'] . ' ' . $booking['last_name'])
            );
            $intent = $this->stripe->createPaymentIntent($amount, $customerId, $bookingId, 'Chauffeur booking ' . $bookingId);
        } catch (Exception $e) {
            log_message('error', '[Stripe] create-intent: ' . $e->getMessage());
            return $this->_error('Could not start payment', 502, ['detail' => $e->getMessage()]);
        }

        $this->_json([
            'success'           => TRUE,
            'client_secret'     => $intent['client_secret'],
            'payment_intent_id' => $intent['id'],
        ], 201);
    }

    /**
     * POST /api/stripe/finalize
     * Body: { booking_id, payment_intent_id }
     * Called after the frontend confirms the PaymentIntent with Stripe.js.
     * Verifies the authorization directly with Stripe, saves the card +
     * intent on the booking, and marks it awaiting_approval.
     */
    public function stripe_finalize()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);

        $bookingId = trim((string)($raw['booking_id'] ?? ''));
        $intentId  = trim((string)($raw['payment_intent_id'] ?? ''));
        if ($bookingId === '' || $intentId === '') {
            return $this->_error('booking_id and payment_intent_id are required', 422);
        }

        try {
            $intent = $this->stripe->retrievePaymentIntent($intentId);
        } catch (Exception $e) {
            log_message('error', '[Stripe] finalize: ' . $e->getMessage());
            return $this->_error('Could not verify payment', 502, ['detail' => $e->getMessage()]);
        }

        if (($intent['status'] ?? '') !== 'requires_capture') {
            return $this->_error('Payment was not authorized (status: ' . ($intent['status'] ?? 'unknown') . ')', 422);
        }

        $pm   = is_array($intent['payment_method'] ?? NULL) ? $intent['payment_method'] : [];
        $card = $pm['card'] ?? [];

        $this->Booking_model->save_stripe_auth($bookingId, [
            'stripe_customer_id'       => $intent['customer'] ?? NULL,
            'stripe_payment_method_id' => $pm['id'] ?? NULL,
            'stripe_payment_intent_id' => $intent['id'],
            'card_brand'               => $card['brand'] ?? NULL,
            'card_last4'               => $card['last4'] ?? NULL,
        ]);
        $this->Booking_model->record_payment($bookingId, $intent['id'], 'authorize', $intent['status'], [
            'amount'   => ($intent['amount'] ?? 0) / 100,
            'currency' => strtoupper($intent['currency'] ?? 'usd'),
            'intent'   => $intent,
        ]);

        $this->_json(['success' => TRUE, 'booking_id' => $bookingId, 'status' => 'awaiting_approval']);
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

    /**
     * GET /api/addons?region=chicago|america|worldwide
     * Returns enabled add-ons with the correct unit price for the
     * customer's detected region.
     */
    public function addons()
    {
        $region = strtolower((string)($this->input->get('region') ?? 'worldwide'));
        if (!in_array($region, ['chicago', 'america', 'worldwide'], TRUE)) $region = 'worldwide';
        $rows = $this->Addon_model->list_all(TRUE);
        $out = array_map(function ($a) use ($region) {
            $unit = $this->Addon_model->price_for_region($a, $region);
            return [
                'id'         => (int)$a['id'],
                'code'       => $a['code'],
                'name'       => $a['name'],
                'description'=> $a['description'],
                'category'   => $a['category'],
                'pricing_type'=> $a['pricing_type'],
                'unit_price' => $unit,
                'region'     => $region,
            ];
        }, $rows);
        $this->_json(['success' => TRUE, 'addons' => $out, 'region' => $region]);
    }

    /**
     * GET /api/flights/validate?flight=AA1234&date=2026-08-01
     * Looks up a real flight via the configured provider (Aviationstack
     * by default). On API error or no key configured, returns ok=false
     * with reason="unavailable" so the frontend can fall back to
     * manual entry without blocking the user.
     */
    public function flights_validate()
    {
        $flight = strtoupper(trim((string)($this->input->get('flight') ?? '')));
        $date   = trim((string)($this->input->get('date')   ?? ''));
        if ($flight === '' || $date === '') {
            return $this->_error('flight and date query params are required', 422);
        }
        $result = $this->flights->lookup($flight, $date);
        // Always return 200 with ok=false on "not found" so the frontend
        // can cleanly fall back to manual entry without treating it as
        // a server error.
        $this->_json(array_merge(['success' => TRUE], $result));
    }

    /**
     * POST /api/reservation/sign
     * Body: { booking_id, signature (base64 PNG), terms_version? }
     * Saves the e-signature for a booking (required for bookings > $500).
     * Returns ok=true on success.
     */
    public function reservation_sign()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        $bookingId = trim((string)($raw['booking_id'] ?? ''));
        $signature = (string)($raw['signature'] ?? '');
        $terms     = trim((string)($raw['terms_version'] ?? 'v1'));
        if ($bookingId === '' || $signature === '') {
            return $this->_error('booking_id and signature are required', 422);
        }
        // Verify the booking exists and is over $500
        $row = $this->db->get_where('kfb_bookings', ['booking_id' => $bookingId])->row_array();
        if (!$row) return $this->_error('Booking not found', 404);
        if ((float)$row['amount'] < 500) {
            return $this->_error('Signature not required for bookings under $500', 422);
        }
        $ok = $this->Booking_model->save_signature($bookingId, $signature, $terms);
        if (!$ok) return $this->_error('Could not save signature', 500);
        $this->_json(['success' => TRUE, 'booking_id' => $bookingId]);
    }

    /**
     * POST /api/customers/register
     * Body: { email, password, first_name, last_name, phone? }
     * Promotes a guest checkout to a real account. If the email already
     * exists, sets/updates the password on the existing customer.
     */
    public function customer_register()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        $email    = strtolower(trim((string)($raw['email'] ?? '')));
        $password = (string)($raw['password'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->_error('Valid email is required', 422);
        }
        if (strlen($password) < 6) {
            return $this->_error('Password must be at least 6 characters', 422);
        }
        $existing = $this->Customer_model->get_by_email($email);
        if ($existing && empty($existing['password_hash'])) {
            // Promote guest → account
            $this->Customer_model->set_password($existing['id'], $password);
            $this->_json(['success' => TRUE, 'customer_id' => (int)$existing['id'], 'promoted' => TRUE]);
        } elseif ($existing) {
            $this->_error('Account already exists for this email', 409);
        } else {
            // Create new
            $id = $this->db->insert('kfb_customers', [
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'first_name'    => $raw['first_name'] ?? '',
                'last_name'     => $raw['last_name']  ?? '',
                'phone'         => $raw['phone']      ?? NULL,
                'status'        => 'active',
                'created_at'    => date('Y-m-d H:i:s'),
            ]) ? (int)$this->db->insert_id() : 0;
            if (!$id) return $this->_error('Could not create account', 500);
            $this->_json(['success' => TRUE, 'customer_id' => $id, 'promoted' => FALSE]);
        }
    }

    // ----------------- helpers -----------------

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
