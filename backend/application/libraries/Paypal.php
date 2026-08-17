<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API PayPal Library for CodeIgniter 3 — Orders v2 REST API, redirect
 * checkout.
 *
 * The customer is sent to paypal.com to log in and approve the payment;
 * we never see their card number. Flow this supports (mirrors the admin
 * accept/reject/charge workflow):
 *
 *   1. Customer submits a reservation → createOrder() opens a PayPal
 *      order with intent=AUTHORIZE and a return/cancel URL. The widget
 *      redirects the browser to the approval link PayPal returns.
 *   2. Customer approves on paypal.com → PayPal redirects back to our
 *      return URL with the order id (?token=...). authorizeOrder() then
 *      places the hold — funds are not captured yet.
 *   3. Admin reviews the reservation:
 *        - Accept → capture()         (places/settles the hold)
 *        - Reject → voidTransaction() (hold released)
 *   4. chargeReference() (bill the card again later) is NOT supported by
 *      this integration — PayPal's REST checkout doesn't retain a
 *      chargeable payment method after the order is captured unless the
 *      merchant account has Vault enabled and the order was created
 *      with vaulting attributes, which this flow does not do. Calling
 *      it raises a clear exception rather than silently failing.
 *
 * Response shapes from capture()/voidTransaction() are normalized to the
 * same TRANSACTIONID/PAYMENTSTATUS/ACK keys the previous classic-NVP
 * library used, so callers (Admin_api, Booking_model) don't need to
 * change.
 */
class Paypal
{
    protected $CI;
    protected $client_id;
    protected $client_secret;
    protected $base_url;
    protected $access_token;
    protected $token_expires_at = 0;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('paypal', TRUE);

        $this->client_id     = $this->CI->config->item('client_id', 'paypal');
        $this->client_secret = $this->CI->config->item('client_secret', 'paypal');
        $env = $this->CI->config->item('environment', 'paypal');

        $this->base_url = ($env === 'live')
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Create an order to authorize (hold, don't capture) the trip amount.
     * $returnUrl / $cancelUrl are where PayPal sends the browser back to
     * after the customer approves/cancels — PayPal appends ?token=<order
     * id>&PayerID=<payer id> to $returnUrl itself.
     * Returns the raw order object (has 'id' and 'links').
     */
    public function createOrder($amount, $bookingId, $returnUrl, $cancelUrl)
    {
        return $this->_call('POST', '/v2/checkout/orders', [
            'intent'         => 'AUTHORIZE',
            'purchase_units' => [[
                'reference_id' => $bookingId,
                'custom_id'    => $bookingId,
                'description'  => substr('Chauffeur booking ' . $bookingId, 0, 127),
                'amount'       => [
                    'currency_code' => 'USD',
                    'value'         => $this->_fmtAmount($amount),
                ],
            ]],
            'application_context' => [
                'return_url'         => $returnUrl,
                'cancel_url'         => $cancelUrl,
                'brand_name'         => 'API Chauffeur',
                'user_action'        => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ], [201]);
    }

    /** Pull the customer-facing approval link (rel=approve) out of a createOrder() result. */
    public function approveLink(array $order)
    {
        foreach ($order['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve') return $link['href'];
        }
        return NULL;
    }

    /**
     * Place the hold after the customer approves on PayPal (called from
     * the return-URL handler). Returns a normalized array with
     * TRANSACTIONID = the authorization id used by capture()/voidTransaction().
     */
    public function authorizeOrder($orderId)
    {
        $result = $this->_call('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/authorize', new stdClass(), [200, 201]);

        $auth = $result['purchase_units'][0]['payments']['authorizations'][0] ?? NULL;
        if (!$auth || empty($auth['id'])) {
            throw new Exception('PayPal authorize response missing authorization id: ' . json_encode($result));
        }

        return [
            'TRANSACTIONID' => $auth['id'],
            'ACK'            => (($auth['status'] ?? '') === 'CREATED') ? 'Success' : $auth['status'],
            'PAYER_EMAIL'    => $result['payer']['email_address'] ?? NULL,
            'raw'            => $result,
        ];
    }

    /** Accept: transfer the held funds. */
    public function capture($authTransactionId, $amount)
    {
        $result = $this->_call('POST', '/v2/payments/authorizations/' . rawurlencode($authTransactionId) . '/capture', [
            'amount' => [
                'currency_code' => 'USD',
                'value'         => $this->_fmtAmount($amount),
            ],
            'final_capture' => TRUE,
        ], [200, 201]);

        return [
            'TRANSACTIONID'  => $result['id'] ?? NULL,
            'PAYMENTSTATUS'  => $result['status'] ?? 'Success',
            'ACK'            => 'Success',
            'raw'            => $result,
        ];
    }

    /** Reject: release the hold, nothing is charged. */
    public function voidTransaction($authTransactionId)
    {
        // PayPal returns 204 No Content on success — no JSON body to parse.
        $this->_call('POST', '/v2/payments/authorizations/' . rawurlencode($authTransactionId) . '/void', NULL, [200, 204]);
        return ['ACK' => 'Success'];
    }

    /**
     * Not supported: this redirect checkout doesn't vault a reusable
     * payment method, so there is nothing to charge a second time
     * against. Kept so Admin_api's existing try/catch around this call
     * surfaces a clear message instead of a fatal error.
     */
    public function chargeReference($referenceTransactionId, $amount, $description = '')
    {
        throw new Exception(
            'Additional charges are not supported for PayPal redirect checkout — no payment ' .
            'method is retained after checkout. Ask the customer to complete a new payment, or ' .
            'process this charge manually from the PayPal dashboard.'
        );
    }

    // ----------------- internals -----------------

    protected function _fmtAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    protected function _getAccessToken()
    {
        if ($this->access_token && time() < $this->token_expires_at - 60) {
            return $this->access_token;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->base_url . '/v1/oauth2/token',
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST           => TRUE,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_USERPWD        => $this->client_id . ':' . $this->client_secret,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === FALSE) {
            throw new Exception('PayPal auth network error: ' . $err);
        }
        $data = json_decode($body, TRUE);
        if ($code !== 200 || empty($data['access_token'])) {
            log_message('error', '[PayPal] oauth token request failed: HTTP ' . $code . ' ' . $body);
            throw new Exception('Could not authenticate with PayPal');
        }

        $this->access_token     = $data['access_token'];
        $this->token_expires_at = time() + (int)$data['expires_in'];
        return $this->access_token;
    }

    /**
     * $expectCodes is the list of HTTP status codes treated as success.
     * $body === NULL sends no request body (used for void, which takes none).
     */
    protected function _call($method, $path, $body, array $expectCodes)
    {
        $token = $this->_getAccessToken();

        // Always ask for the full resource back (not PayPal's "minimal"
        // default) — authorizeOrder() needs purchase_units[].payments to
        // read the authorization id out of the response.
        $headers = ['Authorization: Bearer ' . $token, 'Prefer: return=representation'];
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $this->base_url . $path,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
        ];
        if ($body !== NULL) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === FALSE) {
            throw new Exception('PayPal network error: ' . $err);
        }

        if (!in_array($code, $expectCodes, TRUE)) {
            log_message('error', '[PayPal] ' . $method . ' ' . $path . ' failed: HTTP ' . $code . ' ' . $responseBody);
            $data = json_decode($responseBody, TRUE);
            $message = $data['message'] ?? ('PayPal API error (HTTP ' . $code . ')');
            throw new Exception($message);
        }

        $data = json_decode((string)$responseBody, TRUE);
        return is_array($data) ? $data : [];
    }
}
