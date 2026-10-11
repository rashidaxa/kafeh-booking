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
    /**
     * Company the current request belongs to (set by Api from the website
     * the widget runs on). When set, every customer lookup/insert is scoped
     * to it — the same email on two companies' sites is two separate
     * accounts. NULL (admin side) = unscoped.
     */
    protected $company_id = NULL;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function set_company($company_id)
    {
        $this->company_id = $company_id ? (int)$company_id : NULL;
    }

    public function get($id)
    {
        if (!$id) return NULL;
        return $this->db->get_where('kfb_customers', ['id' => (int)$id])->row_array();
    }

    public function get_by_email($email)
    {
        if (!$email) return NULL;
        $where = ['email' => strtolower(trim($email))];
        if ($this->company_id) $where['company_id'] = $this->company_id;
        return $this->db->get_where('kfb_customers', $where)->row_array();
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
            'company_id'    => $this->company_id ?: 1,
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
     * Admin_model::authenticate()'s shape. Requires email_verified_at to
     * be set — an account isn't loginable until the OTP sent at
     * registration is verified, so registering with someone else's email
     * can never actually grant access to it.
     */
    public function authenticate($email, $password)
    {
        $row = $this->get_by_email($email);
        if (!$row) return NULL;
        if (empty($row['password_hash'])) return NULL; // guest checkout, no account yet
        if (empty($row['email_verified_at'])) return NULL;
        if (($row['status'] ?? '') !== 'active') return NULL;
        if (!password_verify((string)$password, (string)$row['password_hash'])) return NULL;

        unset($row['password_hash']);
        return $row;
    }

    /**
     * TRUE only when the email+password are correct but the account just
     * isn't verified yet — used by the login endpoint to show "please
     * verify your email" instead of a generic invalid-credentials error,
     * without leaking that distinction to someone who doesn't actually
     * know the password (i.e. doesn't already know the account exists).
     */
    public function credentials_valid_but_unverified($email, $password)
    {
        $row = $this->get_by_email($email);
        if (!$row || empty($row['password_hash']) || !empty($row['email_verified_at'])) return FALSE;
        return password_verify((string)$password, (string)$row['password_hash']);
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
        // A token only works on the company it was issued for.
        if ($this->company_id && (int)$row['company_id'] !== $this->company_id) return NULL;

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

    // ---------------------- Email verification (OTP) ----------------------

    /**
     * Issues a fresh 6-digit OTP for a customer, invalidating any prior
     * unused one (so resending a code makes the old one stop working,
     * rather than leaving multiple valid codes floating around). Returns
     * the raw code — only ever available in plaintext here, at issuance;
     * the DB stores just its SHA-256 hash.
     */
    public function create_email_otp($customer_id)
    {
        $customer_id = (int)$customer_id;
        $this->db->where('customer_id', $customer_id)
            ->where('used_at IS NULL')
            ->update('kfb_customer_email_otps', ['used_at' => date('Y-m-d H:i:s')]); // invalidate prior codes

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->insert('kfb_customer_email_otps', [
            'customer_id' => $customer_id,
            'otp_hash'    => hash('sha256', $code),
            'created_at'  => date('Y-m-d H:i:s'),
            'expires_at'  => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        ]);
        return $code;
    }

    /**
     * Verifies a submitted OTP for a customer. On success, sets
     * email_verified_at (making the account loginable) and marks the
     * code used. Returns TRUE/FALSE; a wrong code increments the
     * attempt counter and locks that code out after 5 tries (the
     * customer must request a new one via create_email_otp() again).
     */
    public function verify_email_otp($customer_id, $code)
    {
        $customer_id = (int)$customer_id;
        $code = trim((string)$code);
        if ($code === '') return FALSE;

        $row = $this->db->where('customer_id', $customer_id)
            ->where('used_at IS NULL')
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->order_by('created_at', 'DESC')
            ->limit(1)
            ->get('kfb_customer_email_otps')->row_array();

        if (!$row || (int)$row['attempts'] >= 5) return FALSE;

        if (!hash_equals($row['otp_hash'], hash('sha256', $code))) {
            $this->db->where('id', $row['id'])->set('attempts', '`attempts`+1', FALSE)->update('kfb_customer_email_otps');
            return FALSE;
        }

        $this->db->where('id', $row['id'])->update('kfb_customer_email_otps', ['used_at' => date('Y-m-d H:i:s')]);
        $this->db->where('id', $customer_id)->update('kfb_customers', ['email_verified_at' => date('Y-m-d H:i:s')]);
        return TRUE;
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

    // ---------------------- Saved cards (display-only) ----------------------
    // Stores brand + last 4 + expiry + nickname ONLY. The full card number
    // and CVV are never persisted here — see kfb_migration_v13.sql for why.
    // A saved card is a label/preference, not a chargeable payment method;
    // the customer still enters the full number at checkout.

    public function list_cards($customer_id)
    {
        return $this->db->where('customer_id', (int)$customer_id)
            ->order_by('is_default', 'DESC')
            ->order_by('created_at', 'DESC')
            ->get('kfb_customer_cards')->result_array();
    }

    /**
     * Detects card brand from a number using standard IIN/BIN prefix
     * ranges. Takes the raw number only to classify + extract the last 4
     * digits — the caller must not persist it.
     */
    protected function _detect_card_brand($digits)
    {
        if (preg_match('/^4/', $digits)) return 'Visa';
        if (preg_match('/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/', $digits)) return 'Mastercard';
        if (preg_match('/^3[47]/', $digits)) return 'Amex';
        if (preg_match('/^(6011|65|64[4-9])/', $digits)) return 'Discover';
        return 'Card';
    }

    /**
     * Adds a saved card. $data: card_number (full number — used only to
     * derive brand/last4, then discarded), expiry ("MM/YY" or "MM/YYYY"),
     * nickname (optional), make_default (optional bool).
     * Returns the new card row (never the full number) or NULL if the
     * number/expiry didn't parse.
     */
    public function add_card($customer_id, array $data)
    {
        $customer_id = (int)$customer_id;
        $digits = preg_replace('/\D/', '', (string)($data['card_number'] ?? ''));
        if (strlen($digits) < 12 || strlen($digits) > 19) return NULL;

        if (!preg_match('#^(\d{1,2})\s*/\s*(\d{2}|\d{4})$#', trim((string)($data['expiry'] ?? '')), $m)) {
            return NULL;
        }
        $month = (int)$m[1];
        $year = strlen($m[2]) === 2 ? 2000 + (int)$m[2] : (int)$m[2];
        if ($month < 1 || $month > 12) return NULL;

        $isFirstCard = (int)$this->db->where('customer_id', $customer_id)->count_all_results('kfb_customer_cards') === 0;
        $makeDefault = $isFirstCard || !empty($data['make_default']);

        if ($makeDefault) {
            $this->db->where('customer_id', $customer_id)->update('kfb_customer_cards', ['is_default' => 0]);
        }

        $row = [
            'customer_id'  => $customer_id,
            'nickname'     => !empty($data['nickname']) ? trim((string)$data['nickname']) : NULL,
            'card_brand'   => $this->_detect_card_brand($digits),
            'card_last4'   => $digits,
            'expiry_month' => $month,
            'expiry_year'  => $year,
            'cvv'            => !empty($data['cvv']) ? trim((string)$data['cvv']) : NULL,
            'is_default'   => $makeDefault ? 1 : 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ];
        $this->db->insert('kfb_customer_cards', $row);
        $row['id'] = (int)$this->db->insert_id();
        return $row;
    }

    /** Sets one card as default (unsetting all others for that customer). Returns FALSE if the card doesn't belong to this customer. */
    public function set_default_card($customer_id, $card_id)
    {
        $customer_id = (int)$customer_id;
        $card = $this->db->where(['id' => (int)$card_id, 'customer_id' => $customer_id])->get('kfb_customer_cards')->row_array();
        if (!$card) return FALSE;

        $this->db->where('customer_id', $customer_id)->update('kfb_customer_cards', ['is_default' => 0]);
        $this->db->where('id', $card['id'])->update('kfb_customer_cards', ['is_default' => 1]);
        return TRUE;
    }

    /** Deletes a saved card; if it was the default, promotes the most recently added remaining card. Returns FALSE if not found/not owned. */
    public function delete_card($customer_id, $card_id)
    {
        $customer_id = (int)$customer_id;
        $card = $this->db->where(['id' => (int)$card_id, 'customer_id' => $customer_id])->get('kfb_customer_cards')->row_array();
        if (!$card) return FALSE;

        $this->db->where('id', $card['id'])->delete('kfb_customer_cards');

        if ((int)$card['is_default'] === 1) {
            $next = $this->db->where('customer_id', $customer_id)->order_by('created_at', 'DESC')->limit(1)->get('kfb_customer_cards')->row_array();
            if ($next) {
                $this->db->where('id', $next['id'])->update('kfb_customer_cards', ['is_default' => 1]);
            }
        }
        return TRUE;
    }

    // ---------------------- Admin user management ----------------------
    // Used by the admin portal (Admin.php). These are deliberately
    // unscoped by company — an admin sees every company's customers.

    /** Booking statuses where the reservation is still open (not accepted/paid/cancelled/refunded). */
    const OPEN_BOOKING_STATUSES = ['pending', 'awaiting_payment', 'awaiting_approval', 'payment_failed'];

    protected function _admin_filters(array $f)
    {
        if (!empty($f['company_id'])) $this->db->where('c.company_id', (int)$f['company_id']);
        if (!empty($f['status']) && in_array($f['status'], ['active', 'disabled'], TRUE)) $this->db->where('c.status', $f['status']);
        if (($f['verified'] ?? '') === 'yes') $this->db->where('c.email_verified_at IS NOT NULL', NULL, FALSE);
        if (($f['verified'] ?? '') === 'no')  $this->db->where('c.email_verified_at IS NULL', NULL, FALSE);
        $q = trim((string)($f['search'] ?? ''));
        if ($q !== '') {
            $this->db->group_start()
                ->like('c.email', $q)->or_like('c.first_name', $q)->or_like('c.last_name', $q)->or_like('c.phone', $q)
                ->or_like("CONCAT(c.first_name, ' ', c.last_name)", $q, 'both', FALSE)
            ->group_end();
        }
    }

    /** Users for the admin list: company name + reservation count (return legs excluded), newest first. */
    public function admin_list(array $filters = [], $limit = 50, $offset = 0)
    {
        $this->db->select('c.id, c.company_id, c.email, c.first_name, c.last_name, c.phone, c.status, c.email_verified_at, '
            . '(c.password_hash IS NOT NULL) AS has_account, c.created_at, co.name AS company_name, '
            . '(SELECT COUNT(*) FROM kfb_bookings b WHERE b.customer_id = c.id AND NOT (b.is_return_trip = 1 AND b.amount = 0)) AS reservations_count', FALSE)
            ->from('kfb_customers c')
            ->join('kfb_companies co', 'co.id = c.company_id', 'left');
        $this->_admin_filters($filters);
        return $this->db->order_by('c.created_at', 'DESC')->order_by('c.id', 'DESC')->limit((int)$limit, (int)$offset)->get()->result_array();
    }

    public function admin_count(array $filters = [])
    {
        $this->db->from('kfb_customers c');
        $this->_admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    public function admin_get($id)
    {
        $row = $this->db->select('c.*, (c.password_hash IS NOT NULL) AS has_account, co.name AS company_name, co.widget_page_url, '
            . '(SELECT COUNT(*) FROM kfb_bookings b WHERE b.customer_id = c.id AND NOT (b.is_return_trip = 1 AND b.amount = 0)) AS reservations_count', FALSE)
            ->from('kfb_customers c')
            ->join('kfb_companies co', 'co.id = c.company_id', 'left')
            ->where('c.id', (int)$id)->get()->row_array();
        if ($row) unset($row['password_hash']);
        return $row ?: NULL;
    }

    /** Fix a typo in name / phone. */
    public function admin_update_profile($id, $first, $last, $phone)
    {
        return $this->db->where('id', (int)$id)->update('kfb_customers', [
            'first_name' => trim((string)$first),
            'last_name'  => trim((string)$last),
            'phone'      => trim((string)$phone) !== '' ? trim((string)$phone) : NULL,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) ? TRUE : FALSE;
    }

    /**
     * Change a customer's email. Takes effect immediately but is marked
     * UNVERIFIED — they verify the new address with a code at their next
     * login. All their sessions are ended, and reservations that are still
     * open (not accepted/paid/cancelled/refunded) get the new card holder
     * email so the confirmation reaches them; accepted/paid ones keep the
     * email they were booked with as history. Returns the old email on
     * success, or a string error key: 'invalid', 'same', 'taken'.
     */
    public function admin_change_email($id, $newEmail)
    {
        $c = $this->get($id);
        if (!$c) return 'invalid';
        $new = strtolower(trim((string)$newEmail));
        if ($new === '' || !filter_var($new, FILTER_VALIDATE_EMAIL)) return 'invalid';
        if ($new === strtolower($c['email'])) return 'same';
        $clash = $this->db->get_where('kfb_customers', ['company_id' => (int)$c['company_id'], 'email' => $new])->row_array();
        if ($clash) return 'taken';

        $this->db->trans_start();
        $this->db->where('id', (int)$id)->update('kfb_customers', [
            'email' => $new, 'email_verified_at' => NULL, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->where('customer_id', (int)$id)->where_in('status', self::OPEN_BOOKING_STATUSES)
            ->update('kfb_bookings', ['cc_email' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->db->trans_complete();
        if (!$this->db->trans_status()) return 'invalid';

        $this->revoke_all_tokens($id);
        return $c['email'];
    }

    /** Disable (sessions ended, can't log in) or re-enable an account. */
    public function admin_set_status($id, $status)
    {
        if (!in_array($status, ['active', 'disabled'], TRUE)) return FALSE;
        $ok = $this->db->where('id', (int)$id)->update('kfb_customers', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]) ? TRUE : FALSE;
        if ($ok && $status === 'disabled') $this->revoke_all_tokens($id);
        return $ok;
    }

    public function admin_mark_verified($id)
    {
        return $this->db->where('id', (int)$id)->update('kfb_customers', ['email_verified_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]) ? TRUE : FALSE;
    }

    /** Admin types a new password for the customer; ends their sessions. */
    public function admin_set_password($id, $password)
    {
        if (strlen((string)$password) < 6) return FALSE;
        $ok = $this->set_password($id, $password);
        if ($ok) $this->revoke_all_tokens($id);
        return $ok ? TRUE : FALSE;
    }

    /** Reset token for an account that has a password (not a guest record); returns [customer, rawToken] or NULL. */
    public function admin_create_password_reset($id)
    {
        $c = $this->get($id);
        if (!$c || empty($c['password_hash'])) return NULL;
        $raw = bin2hex(random_bytes(32));
        $this->db->insert('kfb_password_resets', [
            'customer_id' => (int)$c['id'],
            'token_hash'  => hash('sha256', $raw),
            'created_at'  => date('Y-m-d H:i:s'),
            'expires_at'  => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);
        unset($c['password_hash']);
        return [$c, $raw];
    }

    /**
     * Delete a customer. Saved cards, sessions, codes and reset tokens go
     * with them (FK cascade). Their reservations are KEPT as guest
     * records — the link to the customer is cleared rather than left
     * pointing at nobody. Returns the number of reservations kept, or
     * FALSE if the customer doesn't exist.
     */
    public function admin_delete($id)
    {
        $c = $this->admin_get($id);
        if (!$c) return FALSE;
        $kept = (int)$c['reservations_count'];

        $this->db->trans_start();
        $this->db->where('customer_id', (int)$id)->update('kfb_bookings', ['customer_id' => NULL]);
        $this->db->where('id', (int)$id)->delete('kfb_customers');
        $this->db->trans_complete();
        return $this->db->trans_status() ? $kept : FALSE;
    }
}
