<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API Admin Model — backend portal login
 *
 *   kfb_admins — one row per admin user
 *
 * Passwords are stored as bcrypt hashes (PASSWORD_BCRYPT). Plain
 * passwords are never persisted.
 */

class Admin_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Look up an admin by username. Returns the row (incl. password_hash)
     * or NULL.
     */
    public function find_by_username($username)
    {
        if (!$username) return NULL;
        return $this->db
            ->get_where('kfb_admins', ['username' => $username])
            ->row_array();
    }

    /**
     * Verify username + password. Returns the admin row on success
     * (without password_hash) or NULL on failure.
     */
    public function authenticate($username, $password)
    {
        $row = $this->find_by_username($username);
        if (!$row) return NULL;
        if (isset($row['status']) && $row['status'] !== 'active') return NULL;
        if (!password_verify((string)$password, (string)$row['password_hash'])) return NULL;

        // Stamp last login
        $this->db
            ->where('id', $row['id'])
            ->update('kfb_admins', [
                'last_login_at' => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        unset($row['password_hash']);
        return $row;
    }

    /**
     * Create a new admin. Returns the new id, or NULL if username
     * already exists.
     */
    public function create_admin($username, $password, $display_name = NULL, $email = NULL)
    {
        if (!$username || !$password) return NULL;
        $existing = $this->find_by_username($username);
        if ($existing) return NULL;

        $this->db->insert('kfb_admins', [
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'display_name'  => $display_name,
            'email'         => $email,
            'status'        => 'active',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->insert_id();
    }

    /** Returns the count of admins in the table (used by setup wizard). */
    public function count_all()
    {
        return (int)$this->db->count_all('kfb_admins');
    }

    /** Fetch the admin currently logged in (or NULL). */
    public function get_by_id($id)
    {
        if (!$id) return NULL;
        $row = $this->db
            ->get_where('kfb_admins', ['id' => (int)$id])
            ->row_array();
        if (!$row) return NULL;
        unset($row['password_hash']);
        return $row;
    }
}