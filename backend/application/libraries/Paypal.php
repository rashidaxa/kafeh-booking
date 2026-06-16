<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh PayPal Sandbox Library for CodeIgniter 3
 *
 * Wraps the PayPal Orders v2 REST API:
 *   - createOrder()
 *   - captureOrder()
 *
 * Uses the OAuth 2.0 client_credentials flow to obtain an access token.
 *
 * Configure keys in application/config/paypal.php
 */

class Paypal {

    protected $CI;
    protected $client_id;
    protected $client_secret;
    protected $env;          // 'sandbox' or 'live'
    protected $base_url;     // https://api-m.sandbox.paypal.com or https://api-m.paypal.com
    protected $token_url;
    protected $access_token;
    protected $token_expires_at = 0;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('paypal', TRUE);

        $this->client_id     = $this->CI->config->item('client_id', 'paypal');
        $this->client_secret = $this->CI->config->item('client_secret', 'paypal');
        $this->env           = $this->CI->config->item('environment', 'paypal');

        $this->base_url  = $this->env === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
        $this->token_url = $this->base_url . '/v1/oauth2/token';
    }

    /** Get a cached access token (auto-refresh). */
    public function getAccessToken()
    {
        if ($this->access_token && time() < $this->token_expires_at - 60) {
            return $this->access_token;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->token_url,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST           => TRUE,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Language: en_US',
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new Exception('PayPal auth failed: HTTP ' . $code . ' ' . $body);
        }
        $data = json_decode($body, TRUE);
        $this->access_token      = $data['access_token'];
        $this->token_expires_at  = time() + $data['expires_in'];
        return $this->access_token;
    }

    /** Create a CAPTURE order. Returns array with 'id' (order id). */
    public function createOrder($amount, $referenceId, $description = '')
    {
        $token = $this->getAccessToken();
        $payload = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $referenceId,
                'description'  => $description ?: 'Kafeh booking ' . $referenceId,
                'amount'       => [
                    'currency_code' => 'USD',
                    'value'         => number_format((float)$amount, 2, '.', ''),
                ],
            ]],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->base_url . '/v2/checkout/orders',
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST           => TRUE,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($body, TRUE);
        if ($code !== 201 || empty($data['id'])) {
            throw new Exception('PayPal create-order failed: HTTP ' . $code . ' ' . $body);
        }
        return $data;
    }

    /** Capture a previously created order. */
    public function captureOrder($orderId)
    {
        $token = $this->getAccessToken();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->base_url . '/v2/checkout/orders/' . $orderId . '/capture',
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST           => TRUE,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($body, TRUE);
        if ($code >= 400) {
            throw new Exception('PayPal capture failed: HTTP ' . $code . ' ' . $body);
        }
        return $data;
    }
}
