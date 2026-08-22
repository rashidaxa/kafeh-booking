<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API — public REST API
 *
 * Routes (defined in application/config/routes_api.php):
 *   GET  /api/fleet                       → list vehicles
 *   GET  /api/settings                    → global settings (Meet & Greet fees)
 *   POST /api/reservation                 → create booking (returns booking_id)
 *   GET  /api/reservation/:id             → fetch booking detail
 *   POST /api/reservation/:id/update      → edit a pre-acceptance booking (customer, auth required)
 *   POST /api/paypal/create-order         → open a PayPal order, returns the approval redirect URL
 *   GET  /api/paypal/return               → PayPal redirects here after the customer approves
 *   GET  /api/paypal/cancel               → PayPal redirects here if the customer cancels
 *   GET  /api/health                      → health check
 *   POST /api/customers/register          → promote a guest checkout to a real account
 *   POST /api/customers/login             → bearer-token login
 *   POST /api/customers/logout            → revoke the current bearer token
 *   GET  /api/customers/me                → resolve the current bearer token
 *   GET  /api/customers/reservations      → the logged-in customer's own reservations
 *   POST /api/customers/forgot-password   → email a password-reset link
 *   POST /api/customers/reset-password    → consume a password-reset token
 *
 * Payment is a redirect checkout (PayPal Orders v2 REST API): the widget
 * calls paypal_create_order(), sends the browser to the approval link
 * PayPal returns, and PayPal redirects back to paypal_return() once the
 * customer approves — that's where the hold is actually placed. An admin
 * must still accept the reservation (see Admin_api::reservations_accept)
 * before the held funds are captured. See
 * backend/application/libraries/Paypal.php.
 *
 * Customer auth is a bearer token (Authorization: Bearer <token>), not a
 * cookie session — chosen because the widget is embedded cross-origin on
 * third-party sites, where cookie sessions are unreliable. See
 * Customer_model::issue_token()/get_by_token() and _authenticate_customer()
 * below. This is a separate, much lighter-weight mechanism from the admin
 * portal's session-based login (Auth.php/Admin.php).
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
        $this->load->library(['paypal', 'flights', 'mailer']);
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

        // If a logged-in customer is booking, their account email is
        // authoritative — overwrite whatever was submitted so the booking
        // always links to the right customer_id (and never to a second,
        // accidental customer record created from a mistyped email).
        $customer = $this->_optional_authenticate_customer();
        if ($customer && is_array($raw)) {
            $raw['email']     = $customer['email'];
            $raw['firstName'] = $customer['first_name'];
            $raw['lastName']  = $customer['last_name'];
        }

        $err = $this->_validate_reservation_payload($raw);
        if ($err) return $this->_error($err[0], $err[1]);

        // dropoff can be missing if "return at same location" — fall back to pickup
        if (empty($raw['dropoff'])) $raw['dropoff'] = $raw['pickup'];

        $booking_id = $this->Booking_model->create_booking($raw);

        if ($customer) {
            // Already know exactly which account this is — no email lookup needed.
            $this->db->where('booking_id', $booking_id)->update('kfb_bookings', ['customer_id' => (int)$customer['id']]);
        } elseif (!empty($raw['email'])) {
            // Guest checkout — auto-link to a customer record (creates one if new)
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
     * POST /api/reservation/:id/update
     * Body: same shape as POST /api/reservation. Requires the customer to
     * be logged in, to own the reservation, and the reservation to still
     * be pre-acceptance (not paid/cancelled/refunded).
     *
     * If the new total differs from the current amount — or the
     * reservation never actually completed its original PayPal
     * authorization in the first place — the old authorization (if any)
     * is voided and the booking is reset to `pending` so the widget can
     * run the customer back through PayPal approval for the new amount.
     * PayPal's REST redirect checkout has no way to change an
     * already-authorized amount (see Paypal::chargeReference()), so this
     * is the only way to handle a price-changing edit.
     */
    public function reservation_update($id = NULL)
    {
        $customer = $this->_authenticate_customer();
        if (!$customer) return;

        if (!$id) return $this->_error('Booking ID required', 400);
        $booking = $this->Booking_model->get_booking($id);
        if (!$booking) return $this->_error('Booking not found', 404);

        if ((int)($booking['customer_id'] ?? 0) !== (int)$customer['id']) {
            return $this->_error('Not authorized to edit this reservation', 403);
        }
        if (in_array($booking['status'], ['paid', 'cancelled', 'refunded'], TRUE)) {
            return $this->_error('This reservation can no longer be edited.', 422);
        }

        $raw = $this->_read_json();
        // Email is locked to the account on edit — name/phone stay editable
        // (the client explicitly distinguished "pre-populated" from
        // "readonly"). Changing the email client-side has no effect; this
        // also prevents a booking silently drifting to a different email
        // than the account it's still linked to via customer_id.
        if (is_array($raw)) $raw['email'] = $customer['email'];
        $err = $this->_validate_reservation_payload($raw);
        if ($err) return $this->_error($err[0], $err[1]);
        if (empty($raw['dropoff'])) $raw['dropoff'] = $raw['pickup'];

        $oldAmount = (float)$booking['amount'];
        $oldAuthId = $booking['paypal_auth_transaction_id'];

        $this->Booking_model->update_booking($id, $raw);
        $this->Booking_model->mark_edited_by_customer($id);

        $newAmount = (float)($raw['amount'] ?? 0);
        // A reservation can be "editable" (pre-acceptance) simply because
        // the customer never finished the *original* PayPal approval —
        // empty($oldAuthId) means there's nothing to compare the new
        // amount against, so a fresh authorization is required regardless
        // of whether the price happens to match.
        $requiresPayment = (abs($newAmount - $oldAmount) >= 0.01) || empty($oldAuthId);

        if (!$requiresPayment) {
            $this->_json(['success' => TRUE, 'booking_id' => $id, 'price_changed' => FALSE, 'status' => $booking['status']]);
            return;
        }

        if (!empty($oldAuthId)) {
            try {
                $this->paypal->voidTransaction($oldAuthId);
            } catch (Exception $e) {
                // Log and continue — don't block the edit on a void failure;
                // the admin can void manually from the PayPal dashboard as
                // a fallback (same spirit as chargeReference()'s error).
                log_message('error', '[PayPal] void-on-edit failed for ' . $id . ': ' . $e->getMessage());
            }
            $this->Booking_model->record_payment($id, $oldAuthId, 'cancel', 'Success', [
                'amount' => $oldAmount,
                'reason' => 'customer_edit_reprice',
            ]);
        }

        $this->Booking_model->update_booking_status($id, 'pending');
        $this->Booking_model->clear_paypal_auth($id);

        $this->_json([
            'success'       => TRUE,
            'booking_id'    => $id,
            'price_changed' => TRUE,
            'status'        => 'pending',
            // A formatted STRING, not a float — this server's php.ini has
            // serialize_precision=100 (non-default; should be -1), so
            // json_encode() expands ANY float that isn't exactly
            // representable in binary out to ~100 digits regardless of
            // round(). number_format() sidesteps the float serializer
            // entirely, matching how amounts already arrive as strings
            // everywhere else in this API (MySQL DECIMAL columns come
            // back as PHP strings via mysqli, never floats).
            'amount'        => number_format($newAmount, 2, '.', ''),
        ]);
    }

    /**
     * POST /api/paypal/create-order
     * Body: { booking_id, amount, return_url, cancel_url }
     * Opens a PayPal order (intent=AUTHORIZE — nothing is held or charged
     * yet) and returns the URL to redirect the customer's browser to so
     * they can approve it on paypal.com. return_url/cancel_url are
     * supplied by the widget (its own current page, so it can bring the
     * customer back to the right place) — PayPal appends its own
     * ?token=&PayerID= to return_url when it redirects back.
     */
    public function paypal_create_order()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);

        $bookingId = trim((string)($raw['booking_id'] ?? ''));
        $amount    = $raw['amount'] ?? NULL;
        $returnUrl = trim((string)($raw['return_url'] ?? ''));
        $cancelUrl = trim((string)($raw['cancel_url'] ?? ''));
        if ($bookingId === '' || !$amount) {
            return $this->_error('booking_id and amount are required', 422);
        }
        if (!preg_match('#^https?://#i', $returnUrl) || !preg_match('#^https?://#i', $cancelUrl)) {
            return $this->_error('return_url and cancel_url must be absolute http(s) URLs', 422);
        }

        $booking = $this->db->get_where('kfb_bookings', ['booking_id' => $bookingId])->row_array();
        if (!$booking) return $this->_error('Booking not found', 404);

        try {
            $order = $this->paypal->createOrder($amount, $bookingId, $returnUrl, $cancelUrl);
        } catch (Exception $e) {
            log_message('error', '[PayPal] create-order failed for ' . $bookingId . ': ' . $e->getMessage());
            return $this->_error('Could not start PayPal checkout', 502, ['detail' => $e->getMessage()]);
        }

        $approveUrl = $this->paypal->approveLink($order);
        if (empty($order['id']) || !$approveUrl) {
            log_message('error', '[PayPal] create-order for ' . $bookingId . ' had no approval link: ' . json_encode($order));
            return $this->_error('PayPal did not return an approval link', 502);
        }

        $this->Booking_model->save_paypal_order($bookingId, $order['id']);

        $this->_json(['success' => TRUE, 'booking_id' => $bookingId, 'approve_url' => $approveUrl]);
    }

    /**
     * GET /api/paypal/return
     * Query: token (PayPal order id), PayerID, booking_id, site_return
     * (the widget's own page — where we send the browser back to).
     * Places the authorization hold now that the customer has approved,
     * then redirects into the widget's page with a ?kfb_paypal= status
     * flag so it can show a result without needing the lost form state.
     */
    public function paypal_return()
    {
        $token      = trim((string)($this->input->get('token') ?? ''));
        $bookingId  = trim((string)($this->input->get('booking_id') ?? ''));
        $siteReturn = (string)($this->input->get('site_return') ?? '');

        $booking = $bookingId !== '' ? $this->db->get_where('kfb_bookings', ['booking_id' => $bookingId])->row_array() : NULL;

        // The order id PayPal sends back must match the one we created for
        // this booking — otherwise this callback isn't trustworthy.
        if (!$booking || $token === '' || empty($booking['paypal_order_id']) || !hash_equals($booking['paypal_order_id'], $token)) {
            log_message('error', '[PayPal] return callback token mismatch for booking ' . $bookingId);
            return $this->_redirect_to_site($siteReturn, $bookingId, 'error', 'Invalid PayPal return');
        }

        try {
            $result = $this->paypal->authorizeOrder($token);
        } catch (Exception $e) {
            log_message('error', '[PayPal] authorizeOrder failed for ' . $bookingId . ': ' . $e->getMessage());
            return $this->_redirect_to_site($siteReturn, $bookingId, 'error', 'Payment could not be authorized');
        }

        $this->Booking_model->save_paypal_auth($bookingId, [
            'paypal_auth_transaction_id' => $result['TRANSACTIONID'] ?? NULL,
            'card_brand'                 => NULL,
            'card_last4'                 => NULL,
        ]);
        $this->Booking_model->record_payment($bookingId, $result['TRANSACTIONID'] ?? NULL, 'authorize', $result['ACK'] ?? 'Success', [
            'amount'   => $booking['amount'],
            'currency' => 'USD',
            'result'   => $result,
        ]);

        $this->_redirect_to_site($siteReturn, $bookingId, 'success', '');
    }

    /** GET /api/paypal/cancel — the customer backed out of the PayPal approval page. */
    public function paypal_cancel()
    {
        $bookingId  = trim((string)($this->input->get('booking_id') ?? ''));
        $siteReturn = (string)($this->input->get('site_return') ?? '');
        $this->_redirect_to_site($siteReturn, $bookingId, 'cancelled', '');
    }

    /**
     * Send the browser back into the widget's own page with a status
     * flag. $siteReturn is client-supplied, so it's restricted to
     * http(s) (never javascript:/data: etc.) — this is a public,
     * unauthenticated endpoint either way, same trust level as the rest
     * of this controller.
     */
    protected function _redirect_to_site($siteReturn, $bookingId, $status, $message)
    {
        if (!preg_match('#^https?://#i', $siteReturn)) {
            $siteReturn = '/';
        }
        $sep = (strpos($siteReturn, '?') === FALSE) ? '?' : '&';
        $url = $siteReturn . $sep . 'kfb_paypal=' . rawurlencode($status) . '&booking_id=' . rawurlencode((string)$bookingId);
        if ($message !== '') $url .= '&kfb_paypal_message=' . rawurlencode($message);
        header('Location: ' . $url, TRUE, 302);
        exit;
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
     * Saves the e-signature for a booking — required on every booking,
     * regardless of amount.
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
        $row = $this->db->get_where('kfb_bookings', ['booking_id' => $bookingId])->row_array();
        if (!$row) return $this->_error('Booking not found', 404);
        $ok = $this->Booking_model->save_signature($bookingId, $signature, $terms);
        if (!$ok) return $this->_error('Could not save signature', 500);
        $this->_json(['success' => TRUE, 'booking_id' => $bookingId]);
    }

    /**
     * POST /api/customers/register
     * Body: { email, password, first_name, last_name, phone? }
     * Promotes a guest checkout to a real account, or creates a new one.
     * Does NOT log the customer in — a 6-digit OTP is emailed instead,
     * and the account only becomes loginable once it's verified (see
     * Customer_model::authenticate()). This is what stops someone
     * registering with an email they don't own: they'd never receive
     * the code, so the account just sits unverified and unusable.
     *
     * If an existing account at this email is already verified, this is
     * a 409 conflict as before. If it exists but was never verified
     * (an abandoned/squatted registration), this OVERWRITES it — the
     * real owner proving they control the inbox (by receiving this new
     * OTP) is what should win, not whoever registered first.
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
        if ($existing && !empty($existing['password_hash']) && !empty($existing['email_verified_at'])) {
            return $this->_error('Account already exists for this email', 409);
        }

        if ($existing) {
            // Guest promotion OR overwriting an abandoned unverified registration.
            $id = (int)$existing['id'];
            $this->db->where('id', $id)->update('kfb_customers', [
                'password_hash'     => password_hash($password, PASSWORD_BCRYPT),
                'first_name'        => $raw['first_name'] ?? $existing['first_name'],
                'last_name'         => $raw['last_name']  ?? $existing['last_name'],
                'phone'             => $raw['phone']      ?? $existing['phone'],
                'email_verified_at' => NULL,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
        } else {
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
        }

        $otp = $this->Customer_model->create_email_otp($id);
        $this->_send_verification_email(['email' => $email, 'first_name' => $raw['first_name'] ?? ''], $otp);

        $this->_json(['success' => TRUE, 'customer_id' => $id, 'requires_verification' => TRUE, 'email' => $email]);
    }

    /**
     * POST /api/customers/verify-email
     * Body: { email, otp }
     * Verifies the 6-digit code and — on success — logs the customer in
     * immediately (same shape as /customers/login), since this is the
     * exact moment the account becomes loginable.
     */
    public function customer_verify_email()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        $email = strtolower(trim((string)($raw['email'] ?? '')));
        $otp   = trim((string)($raw['otp'] ?? ''));
        if ($email === '' || $otp === '') return $this->_error('Email and code are required', 422);

        $customer = $this->Customer_model->get_by_email($email);
        if (!$customer) return $this->_error('This code is invalid or has expired', 400);

        $ok = $this->Customer_model->verify_email_otp($customer['id'], $otp);
        if (!$ok) return $this->_error('This code is invalid or has expired', 400);

        $token = $this->Customer_model->issue_token(
            $customer['id'],
            (string)($this->input->user_agent() ?: ''),
            $this->input->ip_address()
        );
        unset($customer['password_hash']);
        $customer['email_verified_at'] = date('Y-m-d H:i:s');
        $this->_json(['success' => TRUE, 'token' => $token, 'customer' => $customer]);
    }

    /**
     * POST /api/customers/resend-otp
     * Body: { email }
     * Always responds success-shaped for an unknown email (avoids
     * enumeration); tells an already-verified account to just log in.
     */
    public function customer_resend_otp()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        $email = strtolower(trim((string)($raw['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->_error('Valid email is required', 422);
        }

        $customer = $this->Customer_model->get_by_email($email);
        if ($customer && empty($customer['email_verified_at']) && !empty($customer['password_hash'])) {
            $otp = $this->Customer_model->create_email_otp($customer['id']);
            $this->_send_verification_email($customer, $otp);
        } elseif ($customer && !empty($customer['email_verified_at'])) {
            $this->_json(['success' => TRUE, 'already_verified' => TRUE]);
            return;
        }

        $this->_json(['success' => TRUE]);
    }

    /**
     * POST /api/customers/login
     * Body: { email, password }
     * Returns { success, token, customer } on success. The token is a
     * bearer token (not a cookie session) — see the class docblock in
     * Customer_model::issue_token() for why: this widget is embedded
     * cross-origin on third-party sites, where cookie sessions are
     * unreliable.
     */
    public function customer_login()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        $email    = trim((string)($raw['email'] ?? ''));
        $password = (string)($raw['password'] ?? '');
        if ($email === '' || $password === '') {
            return $this->_error('Email and password are required', 422);
        }

        $customer = $this->Customer_model->authenticate($email, $password);
        if (!$customer) {
            // Give the actual account owner a useful message — but only
            // once they've proven it's them by supplying the right
            // password, so this doesn't leak account existence to anyone else.
            if ($this->Customer_model->credentials_valid_but_unverified($email, $password)) {
                return $this->_error('Please verify your email before logging in.', 403, ['requires_verification' => TRUE, 'email' => strtolower(trim($email))]);
            }
            return $this->_error('Invalid email or password', 401);
        }

        $token = $this->Customer_model->issue_token(
            $customer['id'],
            (string)($this->input->user_agent() ?: ''),
            $this->input->ip_address()
        );

        $this->_json(['success' => TRUE, 'token' => $token, 'customer' => $customer]);
    }

    /** POST /api/customers/logout — best-effort; always responds success. */
    public function customer_logout()
    {
        $header = $this->input->get_request_header('Authorization', TRUE);
        if ($header && stripos($header, 'Bearer ') === 0) {
            $this->Customer_model->revoke_token(trim(substr($header, 7)));
        }
        $this->_json(['success' => TRUE]);
    }

    /** GET /api/customers/me — resolves the current bearer token to a customer. */
    public function customer_me()
    {
        $customer = $this->_authenticate_customer();
        if (!$customer) return;
        $this->_json(['success' => TRUE, 'customer' => $customer]);
    }

    /** GET /api/customers/reservations — the logged-in customer's own reservations. */
    public function customer_reservations()
    {
        $customer = $this->_authenticate_customer();
        if (!$customer) return;
        $rows = $this->Booking_model->list_for_customer($customer['id']);
        $this->_json(['success' => TRUE, 'reservations' => $rows]);
    }

    /**
     * POST /api/customers/forgot-password
     * Body: { email, reset_url_base }
     * Always responds success-shaped regardless of whether the email has
     * an account, to avoid leaking which emails are registered.
     * reset_url_base is the widget's own page — client-supplied since
     * this is a cross-origin embed and the backend can't assume its own
     * domain is customer-facing — validated the same way
     * paypal_create_order() validates return_url/cancel_url.
     */
    public function customer_forgot_password()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        $email        = trim((string)($raw['email'] ?? ''));
        $resetUrlBase = trim((string)($raw['reset_url_base'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->_error('Valid email is required', 422);
        }
        if (!preg_match('#^https?://#i', $resetUrlBase)) {
            return $this->_error('reset_url_base must be an absolute http(s) URL', 422);
        }

        $result = $this->Customer_model->create_password_reset($email);
        if ($result) {
            list($customer, $rawToken) = $result;
            $sep = (strpos($resetUrlBase, '?') === FALSE) ? '?' : '&';
            $resetLink = $resetUrlBase . $sep . 'kfb_reset_token=' . rawurlencode($rawToken);
            $this->_send_password_reset_email($customer, $resetLink);
        }

        $this->_json(['success' => TRUE, 'message' => 'If that email is registered, a reset link has been sent.']);
    }

    /**
     * POST /api/customers/reset-password
     * Body: { token, password }
     */
    public function customer_reset_password()
    {
        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        $token    = trim((string)($raw['token'] ?? ''));
        $password = (string)($raw['password'] ?? '');
        if ($token === '') return $this->_error('Reset token is required', 422);
        if (strlen($password) < 6) return $this->_error('Password must be at least 6 characters', 422);

        $ok = $this->Customer_model->consume_password_reset($token, $password);
        if (!$ok) return $this->_error('This reset link is invalid or has expired', 400);
        $this->_json(['success' => TRUE]);
    }

    /**
     * POST /api/customers/update
     * Body: { first_name, last_name, phone, email? }
     * Self-service profile edit. Email is optional — if provided and it
     * already belongs to a different account, the change is rejected.
     */
    public function customer_update_profile()
    {
        $customer = $this->_authenticate_customer();
        if (!$customer) return;

        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        if (!empty($raw['email']) && !filter_var($raw['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->_error('Invalid email address', 422);
        }

        $ok = $this->Customer_model->update_profile($customer['id'], $raw);
        if (!$ok) return $this->_error('That email is already in use by another account', 409);

        $updated = $this->Customer_model->get($customer['id']);
        unset($updated['password_hash']);
        $this->_json(['success' => TRUE, 'customer' => $updated]);
    }

    /**
     * POST /api/customers/change-password
     * Body: { current_password, new_password }
     */
    public function customer_change_password()
    {
        $customer = $this->_authenticate_customer();
        if (!$customer) return;

        $raw = $this->_read_json();
        if (!$raw) return $this->_error('Invalid JSON body', 400);
        $current = (string)($raw['current_password'] ?? '');
        $new     = (string)($raw['new_password'] ?? '');
        if ($current === '' || $new === '') {
            return $this->_error('Current and new password are required', 422);
        }
        if (strlen($new) < 6) return $this->_error('New password must be at least 6 characters', 422);

        $ok = $this->Customer_model->change_password($customer['id'], $current, $new);
        // 403, not 401 — the bearer token is valid (they ARE authenticated);
        // it's specifically the submitted current_password that's wrong.
        // The frontend's handleAuthFailure() treats any 401 from an
        // authenticated endpoint as "token expired, log out" — reusing 401
        // here would incorrectly force-logout a customer who just fat-
        // fingered their current password.
        if (!$ok) return $this->_error('Current password is incorrect', 403);

        $this->_json(['success' => TRUE]);
    }

    /**
     * GET /api/customers/cards — list the logged-in customer's saved cards
     * (brand/last4/expiry/nickname/is_default only — see Customer_model
     * for why the full number and CVV are never stored).
     * POST /api/customers/cards — add one. Body: { card_number, expiry,
     * nickname?, make_default? }. card_number is used only to derive
     * brand + last 4 digits and is never stored or logged — see
     * Customer_model::add_card().
     *
     * Both verbs share one route (not CI3's $route[...]['get']/['post']
     * arrays) so an OPTIONS preflight still matches this same method and
     * gets the constructor's CORS headers, same as every other endpoint
     * in this controller — see the comment in routes_api.php.
     */
    public function customer_cards()
    {
        $customer = $this->_authenticate_customer();
        if (!$customer) return;

        if ($this->input->method() === 'post') {
            $raw = $this->_read_json();
            if (!$raw) return $this->_error('Invalid JSON body', 400);
            $card = $this->Customer_model->add_card($customer['id'], $raw);
            if (!$card) return $this->_error('Enter a valid card number and expiry (MM/YY)', 422);
            $this->_json(['success' => TRUE, 'card' => $card], 201);
            return;
        }

        $this->_json(['success' => TRUE, 'cards' => $this->Customer_model->list_cards($customer['id'])]);
    }

    /** POST /api/customers/cards/:id/default */
    public function customer_cards_set_default($id = NULL)
    {
        $customer = $this->_authenticate_customer();
        if (!$customer) return;
        if (!$id) return $this->_error('Card ID required', 400);

        $ok = $this->Customer_model->set_default_card($customer['id'], $id);
        if (!$ok) return $this->_error('Card not found', 404);
        $this->_json(['success' => TRUE, 'cards' => $this->Customer_model->list_cards($customer['id'])]);
    }

    /** POST /api/customers/cards/:id/delete */
    public function customer_cards_delete($id = NULL)
    {
        $customer = $this->_authenticate_customer();
        if (!$customer) return;
        if (!$id) return $this->_error('Card ID required', 400);

        $ok = $this->Customer_model->delete_card($customer['id'], $id);
        if (!$ok) return $this->_error('Card not found', 404);
        $this->_json(['success' => TRUE, 'cards' => $this->Customer_model->list_cards($customer['id'])]);
    }

    // ----------------- helpers -----------------

    protected function _read_json()
    {
        $raw = $this->input->raw_input_stream;
        if (!$raw) $raw = file_get_contents('php://input');
        $data = json_decode($raw, TRUE);
        return is_array($data) ? $data : NULL;
    }

    /**
     * Shared required-field check for POST /api/reservation and
     * POST /api/reservation/:id/update, so the two endpoints can't drift
     * apart. Returns [message, http_code] on failure, NULL when valid.
     */
    protected function _validate_reservation_payload($raw)
    {
        if (!$raw) return ['Invalid JSON body', 400];
        $required = ['service', 'pickupDate', 'pickupTime', 'pickup',
                     'passengers', 'vehicle_id', 'amount',
                     'firstName', 'lastName', 'email', 'phone'];
        foreach ($required as $field) {
            if (!isset($raw[$field]) || $raw[$field] === '') {
                return ["Missing field: $field", 422];
            }
        }
        if (!filter_var($raw['email'], FILTER_VALIDATE_EMAIL)) {
            return ['Invalid email address', 422];
        }
        return NULL;
    }

    /**
     * Resolves the `Authorization: Bearer <token>` header to a customer
     * row, or writes a 401 and returns NULL. Every customer-authenticated
     * endpoint MUST check the return value and `return;` immediately on
     * NULL — _error() only buffers a response body, it doesn't halt
     * execution (see the fix in Admin_api.php for what happens if you
     * don't check it).
     */
    protected function _authenticate_customer()
    {
        $header = $this->input->get_request_header('Authorization', TRUE);
        if (!$header || stripos($header, 'Bearer ') !== 0) {
            $this->_error('Not authenticated', 401);
            return NULL;
        }
        $customer = $this->Customer_model->get_by_token(trim(substr($header, 7)));
        if (!$customer) {
            $this->_error('Session expired — please log in again', 401);
            return NULL;
        }
        return $customer;
    }

    /**
     * Like _authenticate_customer(), but for endpoints that must stay
     * usable by guests (reservation_create()): silently returns NULL on a
     * missing/malformed/invalid token instead of writing a 401. Never
     * call _error() here — a guest hitting this path is not a failure.
     */
    protected function _optional_authenticate_customer()
    {
        $header = $this->input->get_request_header('Authorization', TRUE);
        if (!$header || stripos($header, 'Bearer ') !== 0) return NULL;
        return $this->Customer_model->get_by_token(trim(substr($header, 7)));
    }

    /** Best-effort email-verification OTP email — sent via SMTP (see Mailer library), not mail(). */
    protected function _send_verification_email(array $customer, $otp)
    {
        $to = $customer['email'];
        $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        $subject = 'Verify your email — your code is ' . $otp;
        $body = "Hi " . ($name !== '' ? $name : 'there') . ",\n\n" .
            "Your verification code is: " . $otp . "\n\n" .
            "Enter this code to verify your email and activate your account (expires in 10 minutes).\n\n" .
            "If you didn't request this, you can safely ignore this email.\n";
        $this->mailer->send($to, $subject, $body);
    }

    /** Best-effort password-reset email — sent via SMTP (see Mailer library), not mail(). */
    protected function _send_password_reset_email(array $customer, $resetLink)
    {
        $to = $customer['email'];
        $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        $subject = 'Reset your password';
        $body = "Hi " . ($name !== '' ? $name : 'there') . ",\n\n" .
            "We received a request to reset your password. Click the link below to choose a new one " .
            "(this link expires in 1 hour):\n\n" . $resetLink . "\n\n" .
            "If you didn't request this, you can safely ignore this email.\n";
        $this->mailer->send($to, $subject, $body);
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
