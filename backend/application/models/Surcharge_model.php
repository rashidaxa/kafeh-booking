<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Surcharge_model — kfb_surcharges catalog (v16)
 *
 * Admin-manageable fee line items (Airport fee, Fuel surcharge, Toll,
 * Parking, Meet & greet, Additional stop, Waiting time, Holiday
 * surcharge, Special event surcharge, Other fees). Each surcharge is
 * either a flat $ amount or a percent of the transportation+travel-fee
 * base, and can auto-apply via a known `auto_trigger` key (airport |
 * airport_meet_greet) or be manually selected on a booking.
 *
 * Consumed by Pricing_engine::_apply_surcharges() via resolve_amount().
 */
class Surcharge_model extends CI_Model
{
    /** auto_trigger values Pricing_engine knows how to evaluate. */
    const KNOWN_TRIGGERS = ['airport', 'airport_meet_greet'];

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
            ->get('kfb_surcharges')
            ->result_array();
    }

    public function get($id)
    {
        if (!$id) return NULL;
        return $this->db->get_where('kfb_surcharges', ['id' => (int)$id])->row_array();
    }

    public function get_by_code($code)
    {
        if (!$code) return NULL;
        return $this->db->get_where('kfb_surcharges', ['code' => strtoupper($code)])->row_array();
    }

    public function create(array $data)
    {
        $row = $this->_build_row($data);
        if (!$row['__ok']) return FALSE;
        $row['data']['created_at'] = date('Y-m-d H:i:s');
        $ok = $this->db->insert('kfb_surcharges', $row['data']);
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
        return $this->db->where('id', (int)$id)->update('kfb_surcharges', $row['data']) ? TRUE : FALSE;
    }

    public function delete($id)
    {
        if (!$id) return FALSE;
        return $this->db->where('id', (int)$id)->delete('kfb_surcharges') ? TRUE : FALSE;
    }

    public function toggle_status($id)
    {
        if (!$id) return FALSE;
        $existing = $this->get($id);
        if (!$existing) return FALSE;
        $new = ((int)$existing['status'] === 1) ? 0 : 1;
        return $this->db->where('id', (int)$id)->update('kfb_surcharges', [
            'status'     => $new,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    /** Resolve a surcharge row's dollar amount against a base (for percent-type rows). */
    public function resolve_amount(array $surcharge, $base)
    {
        $amount = (float)($surcharge['amount'] ?? 0);
        if (($surcharge['pricing_type'] ?? 'flat') === 'percent') {
            return round((float)$base * ($amount / 100), 2);
        }
        return round($amount, 2);
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
            $dup = $this->db->get_where('kfb_surcharges', ['code' => $code])->row_array();
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

        $amount = $data['amount'] ?? NULL;
        if ($amount === '' || $amount === NULL) {
            $errors['amount'] = 'Amount is required.';
        } elseif (!is_numeric($amount) || (float)$amount < 0) {
            $errors['amount'] = 'Amount must be zero or positive.';
        } elseif ($type === 'percent' && (float)$amount > 100) {
            $errors['amount'] = 'Percent amount cannot exceed 100.';
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
            'code'         => strtoupper(trim((string)$data['code'])),
            'name'         => trim((string)$data['name']),
            'description'  => trim((string)($data['description'] ?? '')) ?: NULL,
            'pricing_type' => in_array($data['pricing_type'] ?? 'flat', ['flat', 'percent'], TRUE) ? $data['pricing_type'] : 'flat',
            'amount'       => (float)$data['amount'],
            // auto_trigger is admin read-only (rendered as a badge, not a
            // free-text field) — preserve the existing value rather than
            // ever accept it from the form.
            'auto_trigger' => $existing['auto_trigger'] ?? NULL,
            'sort_order'   => (int)($data['sort_order'] ?? 0),
            'status'       => isset($data['status']) ? ((int)$data['status'] ? 1 : 0) : 1,
        ];
        return ['__ok' => TRUE, 'errors' => [], 'data' => $row];
    }
}
