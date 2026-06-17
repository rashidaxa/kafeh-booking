<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Admin Portal — Authentication
 *
 * Routes:
 *   GET  /admin/login           → render login form
 *   POST /admin/login           → submit credentials (form or JSON)
 *   GET  /admin/logout          → destroy session, redirect to login
 *   GET  /admin/setup           → if no admin exists, render first-admin form
 *   POST /admin/setup           → create first admin (rejected once any exists)
 */

class Auth extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Admin_model');
        $this->load->library('session');
    }

    /** GET /admin/login */
    public function login_get()
    {
        if ($this->_is_logged_in()) {
            return redirect('admin');
        }
        $data = [
            'flash'    => $this->session->flashdata('flash'),
            'username' => '',
            'is_setup' => ($this->Admin_model->count_all() === 0),
        ];
        $this->load->view('admin/login', $data);
    }

    /** POST /admin/login/save — accept JSON or form-encoded. */
    public function do_login()
    {
        $is_json = strpos((string)($this->input->server('CONTENT_TYPE')), 'application/json') !== FALSE;
        $raw     = $is_json ? json_decode($this->input->raw_input_stream, TRUE) : $this->input->post();
        $raw     = is_array($raw) ? $raw : [];

        $username = trim((string)($this->input->post('username', TRUE) ?: ($raw['username'] ?? '')));
        $password = (string)($this->input->post('password', TRUE) ?: ($raw['password'] ?? ''));

        if (!$username || !$password) {
            return $this->_login_response($is_json, FALSE, 'Username and password are required.', $username);
        }

        $admin = $this->Admin_model->authenticate($username, $password);
        if (!$admin) {
            return $this->_login_response($is_json, FALSE, 'Invalid credentials.', $username);
        }

        // Set session
        $this->session->set_userdata([
            'admin_id'       => (int)$admin['id'],
            'admin_username' => $admin['username'],
            'admin_name'     => $admin['display_name'] ?: $admin['username'],
            'logged_in'      => TRUE,
        ]);

        if ($is_json) {
            return $this->_json([
                'success'  => TRUE,
                'admin'    => $admin,
                'redirect' => site_url('admin'),
            ]);
        }
        return redirect('admin');
    }

    /** GET /admin/logout */
    public function logout_get()
    {
        $this->session->unset_userdata(['admin_id', 'admin_username', 'admin_name', 'logged_in']);
        $this->session->sess_destroy();
        return redirect('admin/login');
    }

    /** GET /admin/setup — only available when there are zero admins. */
    public function setup_get()
    {
        if ($this->Admin_model->count_all() > 0) {
            // Setup is single-use
            $this->session->set_flashdata('flash', ['type' => 'info', 'message' => 'Setup has already been completed.']);
            return redirect('admin/login');
        }
        $this->load->view('admin/setup', ['flash' => NULL]);
    }

    /** POST /admin/setup/save — create the first admin. */
    public function do_setup()
    {
        if ($this->Admin_model->count_all() > 0) {
            return $this->_json(['success' => FALSE, 'error' => 'Setup has already been completed.'], 403);
        }

        $username     = trim((string)$this->input->post('username', TRUE));
        $password     = (string)$this->input->post('password', TRUE);
        $password2    = (string)$this->input->post('password_confirm', TRUE);
        $display_name = trim((string)$this->input->post('display_name', TRUE));
        $email        = trim((string)$this->input->post('email', TRUE));

        $errors = [];
        if (!$username || strlen($username) < 3) {
            $errors['username'] = 'Username must be at least 3 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
            $errors['username'] = 'Username may contain letters, numbers, dot, dash and underscore only.';
        }
        if (!$password || strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }
        if ($password !== $password2) {
            $errors['password_confirm'] = 'Password confirmation does not match.';
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        if (!empty($errors)) {
            $this->load->view('admin/setup', [
                'flash'       => ['type' => 'error', 'message' => 'Please fix the highlighted fields.'],
                'errors'      => $errors,
                'username'    => $username,
                'display_name'=> $display_name,
                'email'       => $email,
            ]);
            return;
        }

        $id = $this->Admin_model->create_admin($username, $password, $display_name ?: NULL, $email ?: NULL);
        if (!$id) {
            $this->load->view('admin/setup', [
                'flash'       => ['type' => 'error', 'message' => 'Could not create admin (username may already exist).'],
                'username'    => $username,
                'display_name'=> $display_name,
                'email'       => $email,
            ]);
            return;
        }

        $this->session->set_flashdata('flash', [
            'type'    => 'success',
            'message' => 'Admin account created. Please log in.',
        ]);
        return redirect('admin/login');
    }

    // ----------------- helpers -----------------

    protected function _is_logged_in()
    {
        return (bool)$this->session->userdata('logged_in');
    }

    protected function _login_response($is_json, $ok, $message, $username)
    {
        if ($is_json) {
            return $this->_json([
                'success' => $ok,
                'error'   => $ok ? NULL : $message,
                'redirect'=> $ok ? site_url('admin') : NULL,
            ], $ok ? 200 : 401);
        }
        $this->session->set_flashdata('flash', [
            'type'    => $ok ? 'success' : 'error',
            'message' => $message,
        ]);
        return redirect('admin/login');
    }

    protected function _json($data, $code = 200)
    {
        $this->output
            ->set_status_header($code)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}