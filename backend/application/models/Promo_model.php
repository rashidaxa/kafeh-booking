<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API Promo Code Model — backend CRUD + validation for `kfb_promo_codes`.
 *
 * Lifecycle:
 *   1. admin creates a row via the admin portal
 *   2. customer enters a code on the widget's payment step → front-end calls
 *      GET /api/promo/validate?code=XYZ&amount=189.50
 *   3. validate() returns {ok, discount, finalAmount, reason} so the widget
 *      can show the discount before sending the booking
 *   4. on reservation_create, the booking row stores promo_code + discount_amount
 *   5. on successful payment, record_booking_use() bumps used_count atomically
 *
 * Promo code rules (configurable per code):
 *   - discount_type: 'percent' or 'fixed'
 *   - discount_value: % (0-100) when percent, currency units when fixed
 *   - min_amount: minimum subtotal to apply (currency units, default 0)
 *   - max_uses: 0 = unlimited, else integer
 *   - starts_at / expires_at: optional date window
 *   - status: 1 enabled, 0 disabled
 */

class Promo_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // ---------------------- CRUD ----------------------

    public function list_all()
    {
        return $this->db
            ->order_by('status', 'DESC')
            ->order_by('id', 'DESC')
            ->get('kfb_promo_codes')
            ->result_array();
    }

    public function get($id)
    {
        if (!$id) return NULL;
        return $this->db->get_where('kfb_promo_codes', ['id' => (int)$id])->row_array();
    }

    public function get_by_code($code)
    {
        $code = trim((string)$code);
        if ($code === '') return NULL;
        return $this->db->get_where('kfb_promo_codes', ['code' => $code])->row_array();
    }

    public function create(array $data)
    {
        $row = $this->_build_row($data);
        if (!$row['__ok']) return FALSE;
        $row['data']['created_at'] = date('Y-m-d H:i:s');
        $ok = $this->db->insert('kfb_promo_codes', $row['data']);
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
        return $this->db->where('id', (int)$id)->update('kfb_promo_codes', $row['data']) ? TRUE : FALSE;
    }

    public function delete($id)
    {
        if (!$id) return FALSE;
        return $this->db->where('id', (int)$id)->delete('kfb_promo_codes') ? TRUE : FALSE;
    }

    public function toggle_status($id)
    {
        if (!$id) return FALSE;
        $existing = $this->get($id);
        if (!$existing) return FALSE;
        $new = ((int)$existing['status'] === 1) ? 0 : 1;
        return $this->db->where('id', (int)$id)->update('kfb_promo_codes', [
            'status'     => $new,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    // ---------------------- Validation + apply ----------------------

    /**
     * Validate a code against a subtotal. Returns:
     *   [
     *     'ok'          => bool,
     *     'code'        => 'KAFE10',          // the canonicalized code
     *     'description' => '...',
     *     'discount'    => 18.95,             // absolute amount off
     *     'final'       => 170.55,            // subtotal - discount
     *     'reason'      => 'ok' | 'not_found' | 'disabled' | 'expired' | 'not_started'
     *                            | 'min_amount' | 'max_uses',
     *   ]
     */
    public function validate($code, $subtotal = 0)
    {
        $result = [
            'ok'          => FALSE,
            'code'        => is_string($code) ? strtoupper(trim($code)) : '',
            'description' => NULL,
            'discount'    => '0.00',
            'final'       => number_format((float)$subtotal, 2, '.', ''),
            'reason'      => 'not_found',
        ];
        if ($result['code'] === '') {
            $result['reason'] = 'not_found';
            return $result;
        }

        $row = $this->get_by_code($result['code']);
        if (!$row) {
            $result['reason'] = 'not_found';
            return $result;
        }

        if ((int)$row['status'] !== 1) {
            $result['reason'] = 'disabled';
            $result['description'] = $row['description'];
            return $result;
        }

        $today = date('Y-m-d');
        if (!empty($row['starts_at']) && $row['starts_at'] !== '0000-00-00' && $row['starts_at'] > $today) {
            $result['reason'] = 'not_started';
            $result['description'] = $row['description'];
            return $result;
        }
        if (!empty($row['expires_at']) && $row['expires_at'] !== '0000-00-00' && $row['expires_at'] < $today) {
            $result['reason'] = 'expired';
            $result['description'] = $row['description'];
            return $result;
        }

        if ((int)$row['max_uses'] > 0 && (int)$row['used_count'] >= (int)$row['max_uses']) {
            $result['reason'] = 'max_uses';
            $result['description'] = $row['description'];
            return $result;
        }

        $subtotal = (float)$subtotal;
        if ($subtotal < (float)$row['min_amount']) {
            $result['reason'] = 'min_amount';
            $result['description'] = $row['description'];
            return $result;
        }

        $discount = $this->compute_discount($row, $subtotal);
        $result['ok']          = TRUE;
        $result['code']        = $row['code'];
        $result['description'] = $row['description'];
        // Formatted STRINGs, not floats — this server's php.ini has
        // serialize_precision=100 (non-default; should be -1), so
        // json_encode() expands any float that isn't exactly representable
        // in binary out to ~100 digits regardless of round(). The frontend
        // already does +(res.discount || 0) to consume this, so a string
        // is safe here — same fix as Api::reservation_update()'s amount.
        $result['discount']    = number_format($discount, 2, '.', '');
        $result['final']       = number_format(max(0, $subtotal - $discount), 2, '.', '');
        $result['reason']      = 'ok';
        return $result;
    }

    /**
     * Pure-function discount calculator. Always non-negative, never exceeds
     * the subtotal for percent type, capped at 2 decimals.
     */
    public function compute_discount(array $row, $subtotal)
    {
        $subtotal = max(0, (float)$subtotal);
        $value    = (float)$row['discount_value'];
        if ($row['discount_type'] === 'percent') {
            // cap % at 100 to be safe
            $pct = max(0, min(100, $value));
            $discount = $subtotal * ($pct / 100);
        } else {
            $discount = max(0, $value);
        }
        // never discount more than the subtotal
        if ($discount > $subtotal) $discount = $subtotal;
        return $discount;
    }

    /**
     * Atomically bump used_count after a successful payment.
     * Returns TRUE on success, FALSE if the promo is exhausted in the meantime.
     */
    public function record_booking_use($code)
    {
        $code = trim((string)$code);
        if ($code === '') return FALSE;
        $row = $this->get_by_code($code);
        if (!$row) return FALSE;

        // For limited-use promos, guard against race conditions
        if ((int)$row['max_uses'] > 0 && (int)$row['used_count'] >= (int)$row['max_uses']) {
            return FALSE;
        }
        return $this->db
            ->where('id', (int)$row['id'])
            ->update('kfb_promo_codes', [
                'used_count' => (int)$row['used_count'] + 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]) ? TRUE : FALSE;
    }

    // ---------------------- Form validation ----------------------

    /**
     * Validate an admin-side payload. Returns ['field' => 'msg', ...] — empty == valid.
     */
    public function validate_form(array $data, $is_create = TRUE, $existing = NULL)
    {
        $errors = [];

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '') {
            $errors['code'] = 'Code is required.';
        } elseif (mb_strlen($code) > 40) {
            $errors['code'] = 'Code must be 40 characters or fewer.';
        } elseif (!preg_match('/^[A-Z0-9_\-]+$/', $code)) {
            $errors['code'] = 'Code may contain uppercase letters, numbers, dash, and underscore only.';
        } else {
            // uniqueness check (skip self on edit)
            $dup = $this->db->get_where('kfb_promo_codes', ['code' => $code])->row_array();
            if ($dup && (!$existing || (int)$dup['id'] !== (int)$existing['id'])) {
                $errors['code'] = 'This code is already in use.';
            }
        }

        $type = $data['discount_type'] ?? 'percent';
        if (!in_array($type, ['percent', 'fixed'], TRUE)) {
            $errors['discount_type'] = 'Discount type must be percent or fixed.';
        }
        $value = $data['discount_value'] ?? NULL;
        if ($value === '' || $value === NULL) {
            $errors['discount_value'] = 'Discount value is required.';
        } elseif (!is_numeric($value) || (float)$value < 0) {
            $errors['discount_value'] = 'Discount value must be zero or positive.';
        } elseif ($type === 'percent' && (float)$value > 100) {
            $errors['discount_value'] = 'Percent discount cannot exceed 100.';
        }

        $minAmount = $data['min_amount'] ?? 0;
        if ($minAmount !== '' && $minAmount !== NULL && (!is_numeric($minAmount) || (float)$minAmount < 0)) {
            $errors['min_amount'] = 'Min amount must be zero or positive.';
        }

        $maxUses = $data['max_uses'] ?? 0;
        if ($maxUses !== '' && $maxUses !== NULL && (!is_numeric($maxUses) || (int)$maxUses < 0)) {
            $errors['max_uses'] = 'Max uses must be zero (unlimited) or a positive integer.';
        }

        $status = isset($data['status']) ? (int)$data['status'] : 1;
        if (!in_array($status, [0, 1], TRUE)) {
            $errors['status'] = 'Status must be enabled or disabled.';
        }

        // dates — only validate format, not the relationship
        foreach (['starts_at' => 'Start date', 'expires_at' => 'Expiry date'] as $field => $label) {
            $v = $data[$field] ?? NULL;
            if ($v === '' || $v === NULL) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $errors[$field] = $label . ' must be in YYYY-MM-DD format.';
            }
        }
        if (empty($errors['starts_at']) && empty($errors['expires_at'])) {
            $s = $data['starts_at'] ?? NULL;
            $e = $data['expires_at'] ?? NULL;
            if ($s && $e && $s > $e) {
                $errors['expires_at'] = 'Expiry date must be on or after the start date.';
            }
        }

        return $errors;
    }

    // ---------------------- Internals ----------------------

    /**
     * Coerce payload into the DB column shape.
     * Returns ['__ok' => bool, 'data' => array, 'errors' => array].
     */
    protected function _build_row(array $data, $existing = NULL)
    {
        $errors = $this->validate_form($data, $existing === NULL, $existing);
        if (!empty($errors)) {
            return ['__ok' => FALSE, 'errors' => $errors, 'data' => []];
        }

        $row = [
            'code'           => strtoupper(trim((string)$data['code'])),
            'description'    => trim((string)($data['description'] ?? '')) ?: NULL,
            'discount_type'  => in_array($data['discount_type'] ?? 'percent', ['percent', 'fixed'], TRUE)
                                ? $data['discount_type'] : 'percent',
            'discount_value' => (float)$data['discount_value'],
            'min_amount'     => (float)($data['min_amount'] ?? 0),
            'max_uses'       => (int)($data['max_uses'] ?? 0),
            'starts_at'      => !empty($data['starts_at']) ? $data['starts_at'] : NULL,
            'expires_at'     => !empty($data['expires_at']) ? $data['expires_at'] : NULL,
            'status'         => isset($data['status']) ? ((int)$data['status'] ? 1 : 0) : 1,
        ];

        return ['__ok' => TRUE, 'errors' => [], 'data' => $row];
    }
}
