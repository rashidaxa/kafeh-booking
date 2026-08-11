<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Addon_model — kfb_addons catalog
 *
 * Add-ons are the configurable extras customers can attach to a booking
 * (Red Carpet, Floral & Balloon Décor, Champagne, Wedding Decorations, etc.).
 * Each add-on has three region-scoped prices (Chicago / America / Worldwide)
 * so the same add-on can cost more in the customer's region.
 *
 * Lifecycle:
 *   1. admin creates a row via the admin portal
 *   2. embed widget GET /api/addons → returns enabled add-ons
 *   3. customer picks add-ons → their unit prices are looked up at
 *      reservation time using the detected region
 *   4. line items are persisted to kfb_booking_addons
 */
class Addon_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function list_all($only_enabled = FALSE)
    {
        if ($only_enabled) $this->db->where('status', 1);
        return $this->db
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('kfb_addons')
            ->result_array();
    }

    public function get($id)
    {
        if (!$id) return NULL;
        return $this->db->get_where('kfb_addons', ['id' => (int)$id])->row_array();
    }

    public function create(array $data)
    {
        $row = $this->_build_row($data);
        if (!$row['__ok']) return FALSE;
        $row['data']['created_at'] = date('Y-m-d H:i:s');
        $ok = $this->db->insert('kfb_addons', $row['data']);
        return $ok ? (int)$this->db->insert_id() : FALSE;
    }

    public function update($id, array $data)
    {
        if (!$id) return FALSE;
        $existing = $this->get($id);
        if (!$existing) return FALSE;
        $row = $this->_build_row($data, $existing);
        if (!$row['__ok']) return FALSE;
        $row['data']['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->where('id', (int)$id)->update('kfb_addons', $row['data']) ? TRUE : FALSE;
    }

    public function delete($id)
    {
        if (!$id) return FALSE;
        return $this->db->where('id', (int)$id)->delete('kfb_addons') ? TRUE : FALSE;
    }

    public function toggle_status($id)
    {
        if (!$id) return FALSE;
        $existing = $this->get($id);
        if (!$existing) return FALSE;
        $new = ((int)$existing['status'] === 1) ? 0 : 1;
        return $this->db->where('id', (int)$id)->update('kfb_addons', [
            'status'     => $new,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    /**
     * Return the unit price of an add-on for the given region.
     * Region can be: chicago | america | worldwide (lowercase).
     */
    public function price_for_region(array $addon, $region)
    {
        $r = strtolower($region);
        if (!in_array($r, ['chicago', 'america', 'worldwide'], TRUE)) $r = 'worldwide';
        return (float)($addon['price_' . $r] ?? 0);
    }

    public function validate_form(array $data, $is_create = TRUE, $existing = NULL)
    {
        $errors = [];

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '') $errors['code'] = 'Code is required.';
        elseif (mb_strlen($code) > 40) $errors['code'] = 'Code must be 40 characters or fewer.';
        elseif (!preg_match('/^[A-Z0-9_\-]+$/', $code)) {
            $errors['code'] = 'Code may contain uppercase letters, numbers, dash, and underscore only.';
        } else {
            $dup = $this->db->get_where('kfb_addons', ['code' => $code])->row_array();
            if ($dup && (!$existing || (int)$dup['id'] !== (int)$existing['id'])) {
                $errors['code'] = 'This code is already in use.';
            }
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') $errors['name'] = 'Name is required.';
        elseif (mb_strlen($name) > 120) $errors['name'] = 'Name must be 120 characters or fewer.';

        $type = $data['pricing_type'] ?? 'flat';
        if (!in_array($type, ['flat', 'percent'], TRUE)) {
            $errors['pricing_type'] = 'Pricing type must be flat or percent.';
        }

        foreach (['chicago', 'america', 'worldwide'] as $r) {
            $col = 'price_' . $r;
            $v = $data[$col] ?? NULL;
            if ($v === '' || $v === NULL) {
                $errors[$col] = 'Price is required.';
            } elseif (!is_numeric($v) || (float)$v < 0) {
                $errors[$col] = 'Price must be zero or positive.';
            } elseif ($type === 'percent' && (float)$v > 100) {
                $errors[$col] = 'Percent price cannot exceed 100.';
            }
        }

        $status = isset($data['status']) ? (int)$data['status'] : 1;
        if (!in_array($status, [0, 1], TRUE)) {
            $errors['status'] = 'Status must be enabled or disabled.';
        }

        return $errors;
    }

    protected function _build_row(array $data, $existing = NULL)
    {
        $errors = $this->validate_form($data, $existing === NULL, $existing);
        if (!empty($errors)) {
            return ['__ok' => FALSE, 'errors' => $errors, 'data' => []];
        }
        $row = [
            'code'             => strtoupper(trim((string)$data['code'])),
            'name'             => trim((string)$data['name']),
            'description'      => trim((string)($data['description'] ?? '')) ?: NULL,
            'category'         => trim((string)($data['category'] ?? '')) ?: NULL,
            'pricing_type'     => in_array($data['pricing_type'] ?? 'flat', ['flat', 'percent'], TRUE) ? $data['pricing_type'] : 'flat',
            'price_chicago'   => (float)$data['price_chicago'],
            'price_america'   => (float)$data['price_america'],
            'price_worldwide' => (float)$data['price_worldwide'],
            'sort_order'       => (int)($data['sort_order'] ?? 0),
            'status'           => isset($data['status']) ? ((int)$data['status'] ? 1 : 0) : 1,
        ];
        return ['__ok' => TRUE, 'errors' => [], 'data' => $row];
    }
}
