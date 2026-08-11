<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Flights — Aviationstack wrapper for real-time flight lookups
 *
 * API doc: https://aviationstack.com/documentation
 *   GET https://api.aviationstack.com/v1/flights
 *     ?access_key=KEY
 *     &flight_iata=AA1234
 *     &flight_date=2026-08-01
 *
 * The library is *non-blocking* — if no API key is configured, or the
 * upstream call fails, it returns ok=false with reason="unavailable"
 * so the frontend can fall back to manual entry.
 *
 * Config (application/config/aviationstack.php):
 *   $config['aviationstack_access_key'] = 'YOUR_KEY';
 *   $config['aviationstack_base_url']  = 'http://api.aviationstack.com/v1/';
 *   $config['aviationstack_timeout']   = 8;  // seconds
 */
class Flights
{
    protected $CI;
    protected $access_key;
    protected $base_url;
    protected $timeout;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->config('aviationstack', TRUE);
        $cfg = $this->CI->config->item('aviationstack');
        $this->access_key = $cfg['access_key'] ?? '';
        $this->base_url    = rtrim($cfg['base_url'] ?? 'http://api.aviationstack.com/v1/', '/') . '/';
        $this->timeout     = (int)($cfg['timeout'] ?? 8);
    }

    /**
     * Look up a flight by IATA code + date.
     * Returns:
     *   [
     *     'ok'         => bool,
     *     'reason'     => 'ok' | 'not_found' | 'unavailable' | 'invalid_params',
     *     'flight'     => [ normalized flight record ] | null,
     *     'message'    => human-readable error (only on failure)
     *   ]
     */
    public function lookup($flight, $date)
    {
        $flight = strtoupper(trim((string)$flight));
        $date   = trim((string)$date);

        if (!preg_match('/^[A-Z0-9]{2}\s?\d{1,4}[A-Z]?$/', $flight)) {
            return ['ok' => FALSE, 'reason' => 'invalid_params', 'message' => 'Invalid flight number format'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => FALSE, 'reason' => 'invalid_params', 'message' => 'Date must be YYYY-MM-DD'];
        }

        if ($this->access_key === '' || $this->access_key === 'YOUR_KEY') {
            return ['ok' => FALSE, 'reason' => 'unavailable', 'message' => 'Flight API key not configured'];
        }

        $url = $this->base_url . 'flights';
        $params = [
            'access_key'  => $this->access_key,
            'flight_iata' => $flight,
            'flight_date' => $date,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url . '?' . http_build_query($params),
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => TRUE,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === FALSE) {
            log_message('error', '[Flights] curl error: ' . $err);
            return ['ok' => FALSE, 'reason' => 'unavailable', 'message' => 'Network error reaching flight API'];
        }
        if ($code >= 400) {
            log_message('error', '[Flights] API HTTP ' . $code . ': ' . substr($body, 0, 300));
            return ['ok' => FALSE, 'reason' => 'unavailable', 'message' => 'Flight API returned HTTP ' . $code];
        }

        $json = json_decode($body, TRUE);
        if (!is_array($json)) {
            return ['ok' => FALSE, 'reason' => 'unavailable', 'message' => 'Invalid response from flight API'];
        }

        // Aviationstack returns {"data": [...]} or {"error": {...}}
        if (!empty($json['error'])) {
            return ['ok' => FALSE, 'reason' => 'unavailable', 'message' => $json['error']['message'] ?? 'API error'];
        }
        $rows = $json['data'] ?? [];
        if (empty($rows)) {
            return ['ok' => FALSE, 'reason' => 'not_found', 'message' => 'No flight found for ' . $flight . ' on ' . $date];
        }

        // Take the first match and normalize
        $row = $rows[0];
        $dep = $row['departure'] ?? [];
        $arr = $row['arrival']   ?? [];
        $airline = $row['airline'] ?? [];
        $flight_info = $row['flight'] ?? [];

        return [
            'ok'     => TRUE,
            'reason' => 'ok',
            'flight' => [
                'flight_number'      => $flight_info['iata']        ?? $flight,
                'flight_date'        => $row['flight_date']         ?? $date,
                'flight_status'      => $row['flight_status']       ?? 'unknown',
                'airline_name'       => $airline['name']            ?? NULL,
                'airline_iata'       => $airline['iata']            ?? NULL,
                'departure_airport'  => $dep['airport']             ?? NULL,
                'departure_iata'     => $dep['iata']                ?? NULL,
                'departure_timezone' => $dep['timezone']            ?? NULL,
                'departure_scheduled'=> $dep['scheduled']           ?? NULL,
                'departure_estimated'=> $dep['estimated']           ?? NULL,
                'arrival_airport'    => $arr['airport']             ?? NULL,
                'arrival_iata'       => $arr['iata']                ?? NULL,
                'arrival_timezone'   => $arr['timezone']            ?? NULL,
                'arrival_scheduled'  => $arr['scheduled']           ?? NULL,
                'arrival_estimated'  => $arr['estimated']           ?? NULL,
            ],
        ];
    }
}
