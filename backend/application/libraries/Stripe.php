<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Stripe Library for CodeIgniter 3
 *
 * Thin cURL wrapper over the Stripe REST API (no Composer SDK is
 * installed in this project — see backend/composer.json).
 *
 * Flow this supports:
 *   1. Customer submits a reservation → createPaymentIntent() with
 *      capture_method=manual + setup_future_usage=off_session. The
 *      frontend confirms it client-side with Stripe.js (handles 3DS).
 *      Funds are authorized/held, card is saved to the Customer.
 *   2. Admin reviews the reservation:
 *        - Accept → capturePaymentIntent()  (funds transfer)
 *        - Reject → cancelPaymentIntent()   (hold released)
 *   3. Admin can later bill the saved card again with
 *      createOffSessionCharge() (e.g. extra charges after the trip).
 *
 * Configure keys in application/config/stripe.php
 */
class Stripe
{
    protected $CI;
    protected $secret_key;
    protected $base_url = 'https://api.stripe.com/v1';

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('stripe', TRUE);
        $this->secret_key = $this->CI->config->item('secret_key', 'stripe');
    }

    // ----------------- Customers -----------------

    /** Find an existing customer by exact email match, or create one. Returns the customer id. */
    public function findOrCreateCustomer($email, $name = '')
    {
        $existing = $this->_request('GET', '/customers', ['email' => $email, 'limit' => 1]);
        if (!empty($existing['data'][0]['id'])) {
            return $existing['data'][0]['id'];
        }
        $created = $this->_request('POST', '/customers', ['email' => $email, 'name' => $name]);
        return $created['id'];
    }

    // ----------------- PaymentIntents -----------------

    /**
     * Create + confirm-ready manual-capture PaymentIntent. The frontend
     * confirms it with Stripe.js (stripe.confirmCardPayment), which also
     * handles any 3D Secure challenge. Saves the card to the customer
     * (setup_future_usage) so it can be billed again later.
     */
    public function createPaymentIntent($amount, $customerId, $bookingId, $description = '')
    {
        return $this->_request('POST', '/payment_intents', [
            'amount'                => $this->_toCents($amount),
            'currency'              => 'usd',
            'customer'              => $customerId,
            'capture_method'        => 'manual',
            'setup_future_usage'    => 'off_session',
            'payment_method_types'  => ['card'],
            'description'           => $description ?: ('Chauffeur booking ' . $bookingId),
            'metadata'              => ['booking_id' => $bookingId],
        ]);
    }

    /** Retrieve a PaymentIntent, expanded with its payment_method (for card brand/last4). */
    public function retrievePaymentIntent($id)
    {
        return $this->_request('GET', '/payment_intents/' . $id, ['expand' => ['payment_method']]);
    }

    /** Accept: transfer the held funds. */
    public function capturePaymentIntent($id)
    {
        return $this->_request('POST', '/payment_intents/' . $id . '/capture', []);
    }

    /** Reject: release the hold, nothing is charged. */
    public function cancelPaymentIntent($id)
    {
        return $this->_request('POST', '/payment_intents/' . $id . '/cancel', []);
    }

    /**
     * Admin-initiated charge against a previously saved card (e.g. extra
     * charges after the trip). Confirmed immediately (capture_method=automatic),
     * off_session since the customer isn't present for this charge.
     *
     * Throws a Stripe_CardException with a human-readable message on decline —
     * including the common off-session case where the bank requires the
     * cardholder to re-authenticate (Stripe's `authentication_required`).
     */
    public function createOffSessionCharge($amount, $customerId, $paymentMethodId, $description = '')
    {
        try {
            return $this->_request('POST', '/payment_intents', [
                'amount'         => $this->_toCents($amount),
                'currency'       => 'usd',
                'customer'       => $customerId,
                'payment_method' => $paymentMethodId,
                'off_session'    => 'true',
                'confirm'        => 'true',
                'capture_method' => 'automatic',
                'description'    => $description ?: 'Additional charge',
            ]);
        } catch (Stripe_CardException $e) {
            if ($e->declineCode === 'authentication_required') {
                throw new Stripe_CardException(
                    'The card requires the customer to re-authenticate — an off-session charge can\'t complete this automatically. ' .
                    'Ask the customer to re-enter their card, or try a smaller amount.',
                    $e->declineCode, $e->paymentIntentId
                );
            }
            throw $e;
        }
    }

    // ----------------- internals -----------------

    protected function _toCents($amount)
    {
        return (int)round(((float)$amount) * 100);
    }

    /** Flatten a Stripe-shaped array (incl. nested arrays/lists) into an application/x-www-form-urlencoded body. */
    protected function _buildBody(array $params)
    {
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    protected function _request($method, $path, array $params = [])
    {
        $url = $this->base_url . $path;
        $method = strtoupper($method);

        $ch = curl_init();
        $headers = [
            'Authorization: Basic ' . base64_encode($this->secret_key . ':'),
        ];

        if ($method === 'GET') {
            if (!empty($params)) $url .= '?' . $this->_buildBody($params);
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->_buildBody($params));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === FALSE) {
            throw new Exception('Stripe network error: ' . $err);
        }
        $data = json_decode($body, TRUE);

        if ($code >= 400) {
            $stripeErr = $data['error'] ?? [];
            $message = $stripeErr['message'] ?? ('Stripe API error: HTTP ' . $code);
            if (($stripeErr['type'] ?? '') === 'card_error') {
                throw new Stripe_CardException(
                    $message,
                    $stripeErr['decline_code'] ?? ($stripeErr['code'] ?? ''),
                    $stripeErr['payment_intent']['id'] ?? NULL
                );
            }
            throw new Exception($message);
        }

        return $data;
    }
}

/** Thrown for card_error responses (declines) so callers can show a useful message. */
class Stripe_CardException extends Exception
{
    public $declineCode;
    public $paymentIntentId;

    public function __construct($message, $declineCode = '', $paymentIntentId = NULL)
    {
        parent::__construct($message);
        $this->declineCode = $declineCode;
        $this->paymentIntentId = $paymentIntentId;
    }
}
