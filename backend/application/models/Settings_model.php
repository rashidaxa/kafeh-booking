<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Settings_model — kfb_settings (global key/value config)
 *
 * Holds the legacy Meet & Greet fee (unused since v16 — see
 * kfb_surcharges.MEET_GREET) and, since v16, every global pricing knob
 * the customer's rate engine needs (service radius, travel fee,
 * multipliers, hourly minimums, gratuity, garage coordinates, worldwide
 * quote threshold) — see Pricing_engine.
 */
class Settings_model extends CI_Model
{
    protected $defaults = [
        'meet_greet_chicago'   => '65.00',
        'meet_greet_elsewhere' => '95.00',

        'pricing_local_service_radius_miles'     => '75.00',
        'pricing_regional_travel_fee_per_mile'   => '1.50',
        'pricing_long_distance_multiplier'       => '3.00',
        'pricing_worldwide_multiplier'           => '3.00',
        'pricing_local_hourly_minimum_hours'     => '4.00',
        'pricing_regional_hourly_minimum_hours'  => '5.00',
        'pricing_worldwide_hourly_minimum_hours' => '5.00',
        'pricing_default_gratuity_pct'           => '20.00',
        'pricing_garage_lat'                     => '41.98429380078823',
        'pricing_garage_lng'                     => '-87.9099405503354',
        'pricing_worldwide_quote_threshold_miles'=> '5000.00',
        'pricing_child_seat_fee'                 => '15.00',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function get_all()
    {
        $rows = $this->db->get('kfb_settings')->result_array();
        $out = $this->defaults;
        foreach ($rows as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    }

    public function get($key, $default = NULL)
    {
        $row = $this->db->get_where('kfb_settings', ['setting_key' => $key])->row_array();
        if ($row) return $row['setting_value'];
        return $default !== NULL ? $default : ($this->defaults[$key] ?? NULL);
    }

    public function set($key, $value)
    {
        $existing = $this->db->get_where('kfb_settings', ['setting_key' => $key])->row_array();
        if ($existing) {
            return $this->db->where('setting_key', $key)->update('kfb_settings', [
                'setting_value' => $value,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]) ? TRUE : FALSE;
        }
        return $this->db->insert('kfb_settings', [
            'setting_key'   => $key,
            'setting_value' => $value,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    public function set_many(array $pairs)
    {
        $ok = TRUE;
        foreach ($pairs as $key => $value) {
            if (!$this->set($key, $value)) $ok = FALSE;
        }
        return $ok;
    }

    /** Meet & Greet fees, coerced to float, with defaults applied. */
    public function meet_greet_fees()
    {
        $all = $this->get_all();
        return [
            'meet_greet_chicago'   => (float)($all['meet_greet_chicago']   ?? 65),
            'meet_greet_elsewhere' => (float)($all['meet_greet_elsewhere'] ?? 95),
        ];
    }

    /** Global pricing settings, coerced to float, with defaults applied. */
    public function pricing_settings()
    {
        $all = $this->get_all();
        $keys = [
            'pricing_local_service_radius_miles',
            'pricing_regional_travel_fee_per_mile',
            'pricing_long_distance_multiplier',
            'pricing_worldwide_multiplier',
            'pricing_local_hourly_minimum_hours',
            'pricing_regional_hourly_minimum_hours',
            'pricing_worldwide_hourly_minimum_hours',
            'pricing_default_gratuity_pct',
            'pricing_garage_lat',
            'pricing_garage_lng',
            'pricing_worldwide_quote_threshold_miles',
            'pricing_child_seat_fee',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (float)($all[$k] ?? $this->defaults[$k]);
        }
        return $out;
    }
}
