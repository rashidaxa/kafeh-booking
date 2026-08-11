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
}
