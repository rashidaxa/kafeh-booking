<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer_model — kfb_customers (optional repeat-customer accounts)
 *
 * Customers can checkout as a guest (NULL password_hash) or create a
 * real account (password_hash set via password_hash()). Either way
 * the email/phone lets us look up their past bookings.
 */
class Customer_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function get($id)
    {
        if (!$id) return NULL;
        return $this->db->get_where('kfb_customers', ['id' => (int)$id])->row_array();
    }

    public function get_by_email($email)
    {
        if (!$email) return NULL;
        return $this->db->get_where('kfb_customers', ['email' => strtolower(trim($email))])->row_array();
    }

    /**
     * Create or return an existing customer record for the given email.
     * Used by the booking flow so a returning customer is auto-linked
     * without requiring them to sign up first.
     */
    public function upsert_from_booking(array $data)
    {
        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '') return NULL;

        $existing = $this->get_by_email($email);
        if ($existing) {
            $this->db->where('id', (int)$existing['id'])->update('kfb_customers', [
                'first_name'      => $data['first_name'] ?? $existing['first_name'],
                'last_name'       => $data['last_name']  ?? $existing['last_name'],
                'phone'           => $data['phone']      ?? $existing['phone'],
                'total_bookings'  => (int)$existing['total_bookings'] + 1,
                'last_booking_at' => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            return (int)$existing['id'];
        }

        $row = [
            'email'         => $email,
            'first_name'    => $data['first_name'] ?? '',
            'last_name'     => $data['last_name']  ?? '',
            'phone'         => $data['phone']      ?? NULL,
            'total_bookings' => 1,
            'last_booking_at' => date('Y-m-d H:i:s'),
            'status'        => 'active',
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        $this->db->insert('kfb_customers', $row);
        return (int)$this->db->insert_id();
    }

    /**
     * Promote a guest checkout to a real account by setting a password.
     */
    public function set_password($customer_id, $password)
    {
        if (!$customer_id || !$password) return FALSE;
        return $this->db->where('id', (int)$customer_id)->update('kfb_customers', [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    // ---------------------- Login / bearer tokens ----------------------

    /**
     * Verify email + password. Returns the customer row (password_hash
     * stripped) on success, NULL on any failure — mirrors
     * Admin_model::authenticate()'s shape.
     */
    public function authenticate($email, $password)
    {
        $row = $this->get_by_email($email);
        if (!$row) return NULL;
        if (empty($row['password_hash'])) return NULL; // guest checkout, no account yet
        if (($row['status'] ?? '') !== 'active') return NULL;
        if (!password_verify((string)$password, (string)$row['password_hash'])) return NULL;

        unset($row['password_hash']);
        return $row;
    }

    /**
     * Issue a new bearer token for a customer. Only the raw token is ever
     * returned in plaintext — the DB stores just its SHA-256 hash, since
     * this is already high-entropy random data (not a low-entropy
     * password), so a fast hash is the correct, sufficient choice.
     */
    public function issue_token($customer_id, $user_agent = NULL, $ip_address = NULL)
    {
        $raw = bin2hex(random_bytes(32));
        $this->db->insert('kfb_customer_tokens', [
            'customer_id'  => (int)$customer_id,
            'token_hash'   => hash('sha256', $raw),
            'created_at'   => date('Y-m-d H:i:s'),
            'expires_at'   => date('Y-m-d H:i:s', strtotime('+30 days')),
            'user_agent'   => $user_agent,
            'ip_address'   => $ip_address,
        ]);
        return $raw;
    }

    /**
     * Resolve a raw bearer token to its customer row (NULL if missing,
     * revoked, expired, or the account is disabled). Touches
     * last_used_at on every successful lookup.
     */
    public function get_by_token($token)
    {
        $token = trim((string)$token);
        if ($token === '') return NULL;

        $row = $this->db
            ->select('kfb_customer_tokens.id AS token_id, kfb_customers.*')
            ->from('kfb_customer_tokens')
            ->join('kfb_customers', 'kfb_customers.id = kfb_customer_tokens.customer_id')
            ->where('kfb_customer_tokens.token_hash', hash('sha256', $token))
            ->where('kfb_customer_tokens.revoked_at IS NULL')
            ->where('kfb_customer_tokens.expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->row_array();

        if (!$row || ($row['status'] ?? '') !== 'active') return NULL;

        $this->db->where('id', $row['token_id'])->update('kfb_customer_tokens', [
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);

        unset($row['password_hash'], $row['token_id']);
        return $row;
    }

    /** Revoke a single bearer token (logout). */
    public function revoke_token($token)
    {
        $token = trim((string)$token);
        if ($token === '') return;
        $this->db->where('token_hash', hash('sha256', $token))
            ->where('revoked_at IS NULL')
            ->update('kfb_customer_tokens', ['revoked_at' => date('Y-m-d H:i:s')]);
    }

    /** Revoke every active token for a customer (called after a password reset). */
    public function revoke_all_tokens($customer_id)
    {
        $this->db->where('customer_id', (int)$customer_id)
            ->where('revoked_at IS NULL')
            ->update('kfb_customer_tokens', ['revoked_at' => date('Y-m-d H:i:s')]);
    }

    // ---------------------- Password reset ----------------------

    /**
     * Start a password-reset flow for an email. Returns [customer, raw
     * token] on success, NULL if no account with that email exists.
     * The controller always responds success-shaped regardless of the
     * return value, to avoid leaking which emails have accounts.
     */
    public function create_password_reset($email)
    {
        $customer = $this->get_by_email($email);
        if (!$customer || empty($customer['password_hash'])) return NULL; // no real account to reset

        $raw = bin2hex(random_bytes(32));
        $this->db->insert('kfb_password_resets', [
            'customer_id' => (int)$customer['id'],
            'token_hash'  => hash('sha256', $raw),
            'created_at'  => date('Y-m-d H:i:s'),
            'expires_at'  => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);
        unset($customer['password_hash']);
        return [$customer, $raw];
    }

    /**
     * Consume a password-reset token: sets the new password, marks the
     * token used, and revokes every existing bearer token for that
     * customer (standard practice — a reset often follows a suspected
     * compromise). Returns TRUE on success, FALSE on an invalid/expired/
     * already-used token.
     */
    public function consume_password_reset($token, $new_password)
    {
        $token = trim((string)$token);
        if ($token === '' || !$new_password) return FALSE;

        $row = $this->db->get_where('kfb_password_resets', [
            'token_hash' => hash('sha256', $token),
        ])->row_array();

        if (!$row || $row['used_at'] !== NULL || strtotime($row['expires_at']) < time()) {
            return FALSE;
        }

        $this->set_password($row['customer_id'], $new_password);
        $this->db->where('id', $row['id'])->update('kfb_password_resets', [
            'used_at' => date('Y-m-d H:i:s'),
        ]);
        $this->revoke_all_tokens($row['customer_id']);
        return TRUE;
    }

    // ---------------------- Profile self-service ----------------------

    /**
     * Update a logged-in customer's own profile fields. Email is only
     * changed if provided and different, and is rejected (FALSE) if it
     * already belongs to a different customer.
     */
    public function update_profile($customer_id, array $data)
    {
        $customer_id = (int)$customer_id;
        if (!$customer_id) return FALSE;

        $update = [
            'first_name' => $data['first_name'] ?? '',
            'last_name'  => $data['last_name']  ?? '',
            'phone'      => $data['phone']      ?? NULL,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (!empty($data['email'])) {
            $email = strtolower(trim((string)$data['email']));
            $existing = $this->get_by_email($email);
            if ($existing && (int)$existing['id'] !== $customer_id) {
                return FALSE; // email already belongs to a different account
            }
            $update['email'] = $email;
        }

        $this->db->where('id', $customer_id)->update('kfb_customers', $update);
        return TRUE;
    }

    /**
     * Change a logged-in customer's own password, requiring the current
     * password. Returns FALSE if the account has no password (guest) or
     * the current password doesn't match.
     */
    public function change_password($customer_id, $currentPassword, $newPassword)
    {
        $row = $this->get((int)$customer_id);
        if (!$row || empty($row['password_hash'])) return FALSE;
        if (!password_verify((string)$currentPassword, (string)$row['password_hash'])) return FALSE;
        if (!$newPassword) return FALSE;

        $this->set_password($customer_id, $newPassword);
        return TRUE;
    }
}
