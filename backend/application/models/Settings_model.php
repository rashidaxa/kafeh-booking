<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Settings_model — kfb_settings (global key/value config)
 *
 * Currently used for the Meet & Greet fee, which is a single global
 * amount (Chicago vs. all other airports) rather than a per-vehicle
 * rate — see kfb_migration_v5.sql.
 */
class Settings_model extends CI_Model
{
    protected $defaults = [
        'meet_greet_chicago'   => '65.00',
        'meet_greet_elsewhere' => '95.00',
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
}
