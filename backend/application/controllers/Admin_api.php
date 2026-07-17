<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Admin Portal — REST API
 *
 * All endpoints require an authenticated admin (session-based). CORS
 * is open by default — tighten $allowed_origins for production.
 *
 * Routes (see application/config/routes_admin.php):
 *   GET    /admin/api/me                       → current admin info
 *   GET    /admin/api/vehicles                 → list all
 *   POST   /admin/api/vehicles/save            → create (+ optional image upload)
 *   GET    /admin/api/vehicles/:id             → fetch one
 *   POST   /admin/api/vehicles/:id/save        → update (+ optional image upload)
 *   POST   /admin/api/vehicles/:id/delete      → delete
 *   POST   /admin/api/vehicles/:id/toggle      → flip enabled/disabled
 */

class Admin_api extends CI_Controller
{
    /** Comma-separated list of allowed origins, or ['*'] for any. */
    protected $allowed_origins = ['*'];

    public function __construct()
    {
        parent::__construct();
        $this->load->model(['Admin_model', 'Vehicle_model', 'Promo_model']);
        $this->load->library('session');
        $this->load->helper('url');
        $this->_set_cors_headers();

        // Block everything unless logged in
        if (!$this->session->userdata('logged_in')) {
            // For preflight OPTIONS, the constructor continues but we'll 401 below
            $method = $this->input->method();
            if (strtolower($method) !== 'options') {
                $this->_error('Not authenticated', 401);
            }
        }
    }

    // ----------------- ROUTES -----------------

    /** GET /admin/api/me */
    public function me()
    {
        $id = $this->session->userdata('admin_id');
        $admin = $id ? $this->Admin_model->get_by_id($id) : NULL;
        if (!$admin) return $this->_error('Not authenticated', 401);
        $this->_json(['success' => TRUE, 'admin' => $admin]);
    }

    /** GET /admin/api/vehicles */
    public function vehicles_index()
    {
        $this->_require_login();
        $list = $this->Vehicle_model->list_all();
        // include the public shape so the embed widget can drop it straight in
        $with_public = array_map(function ($r) {
            $r['public'] = $this->Vehicle_model->to_public($r);
            return $r;
        }, $list);
        $this->_json(['success' => TRUE, 'vehicles' => $with_public]);
    }

    /** GET /admin/api/vehicles/:id */
    public function vehicles_get($id = NULL)
    {
        $this->_require_login();
        if (!$id) return $this->_error('ID required', 400);
        $row = $this->Vehicle_model->get($id);
        if (!$row) return $this->_error('Vehicle not found', 404);
        $row['public'] = $this->Vehicle_model->to_public($row);
        $this->_json(['success' => TRUE, 'vehicle' => $row]);
    }

    /** POST /admin/api/vehicles */
    public function vehicles_create()
    {
        $this->_require_login();

        $payload = $this->_collect_payload();

        $errors = $this->Vehicle_model->validate($payload);
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }

        $uploaded = $this->Vehicle_model->handle_upload('image', $upload_err);
        if ($upload_err && !empty($_FILES['image']['name'])) {
            return $this->_error('Image upload failed', 422, ['fields' => ['image' => $upload_err]]);
        }

        $id = $this->Vehicle_model->create($payload, $uploaded);
        if (!$id) return $this->_error('Could not create vehicle', 500, ['db' => $this->db->error()]);

        $row = $this->Vehicle_model->get($id);
        $row['public'] = $this->Vehicle_model->to_public($row);
        $this->_json(['success' => TRUE, 'vehicle' => $row], 201);
    }

    /** POST /admin/api/vehicles/:id (used in place of PUT for HTML form compat) */
    public function vehicles_update($id = NULL)
    {
        $this->_require_login();
        if (!$id) return $this->_error('ID required', 400);
        $existing = $this->Vehicle_model->get($id);
        if (!$existing) return $this->_error('Vehicle not found', 404);

        $payload = $this->_collect_payload();

        $errors = $this->Vehicle_model->validate($payload, FALSE);
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }

        $uploaded = $this->Vehicle_model->handle_upload('image', $upload_err);
        if ($upload_err && !empty($_FILES['image']['name'])) {
            return $this->_error('Image upload failed', 422, ['fields' => ['image' => $upload_err]]);
        }

        $delete_old = !empty($payload['remove_image']);
        $ok = $this->Vehicle_model->update($id, $payload, $uploaded, $delete_old);
        if (!$ok) return $this->_error('Could not update vehicle', 500);

        $row = $this->Vehicle_model->get($id);
        $row['public'] = $this->Vehicle_model->to_public($row);
        $this->_json(['success' => TRUE, 'vehicle' => $row]);
    }

    /** POST /admin/api/vehicles/:id/delete */
    public function vehicles_delete($id = NULL)
    {
        $this->_require_login();
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Vehicle_model->delete($id);
        if (!$ok) return $this->_error('Could not delete vehicle', 500);
        $this->_json(['success' => TRUE, 'id' => (int)$id]);
    }

    /** POST /admin/api/vehicles/:id/toggle */
    public function vehicles_toggle($id = NULL)
    {
        $this->_require_login();
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Vehicle_model->toggle_status($id);
        if (!$ok) return $this->_error('Could not toggle vehicle', 500);
        $row = $this->Vehicle_model->get($id);
        $row['public'] = $this->Vehicle_model->to_public($row);
        $this->_json(['success' => TRUE, 'vehicle' => $row]);
    }

    // ----------------- Promo codes -----------------

    /** GET /admin/api/promos */
    public function promos_index()
    {
        $this->_require_login();
        $this->_json(['success' => TRUE, 'promos' => $this->Promo_model->list_all()]);
    }

    /** POST /admin/api/promos */
    public function promos_create()
    {
        $this->_require_login();
        $payload = $this->_collect_payload();

        $errors = $this->Promo_model->validate_form($payload, TRUE);
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }
        $id = $this->Promo_model->create($payload);
        if (!$id) return $this->_error('Could not create promo code', 500, ['db' => $this->db->error()]);
        $this->_json(['success' => TRUE, 'promo' => $this->Promo_model->get($id)], 201);
    }

    /** POST /admin/api/promos/:id */
    public function promos_update($id = NULL)
    {
        $this->_require_login();
        if (!$id) return $this->_error('ID required', 400);
        $existing = $this->Promo_model->get($id);
        if (!$existing) return $this->_error('Promo code not found', 404);

        $payload = $this->_collect_payload();
        $errors = $this->Promo_model->validate_form($payload, FALSE, $existing);
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }
        $ok = $this->Promo_model->update($id, $payload);
        if (!$ok) return $this->_error('Could not update promo code', 500);
        $this->_json(['success' => TRUE, 'promo' => $this->Promo_model->get($id)]);
    }

    /** POST /admin/api/promos/:id/delete */
    public function promos_delete($id = NULL)
    {
        $this->_require_login();
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Promo_model->delete($id);
        if (!$ok) return $this->_error('Could not delete promo code', 500);
        $this->_json(['success' => TRUE, 'id' => (int)$id]);
    }

    /** POST /admin/api/promos/:id/toggle */
    public function promos_toggle($id = NULL)
    {
        $this->_require_login();
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Promo_model->toggle_status($id);
        if (!$ok) return $this->_error('Could not toggle promo code', 500);
        $this->_json(['success' => TRUE, 'promo' => $this->Promo_model->get($id)]);
    }

    // ----------------- helpers -----------------

    /** Combine POST fields and JSON body so the API works for both forms and fetch(). */
    protected function _collect_payload()
    {
        $payload = (array)$this->input->post();
        if (empty($payload)) {
            $raw = $this->input->raw_input_stream;
            $json = json_decode($raw, TRUE);
            if (is_array($json)) $payload = $json;
        }
        // Normalize checkbox → 0/1 (only if a form post actually happened)
        if ($this->input->method() === 'post' && $this->input->post() !== NULL) {
            if (isset($payload['status'])) {
                $payload['status'] = ((int)$payload['status'] || $payload['status'] === 'on') ? 1 : 0;
            } else {
                $payload['status'] = 0;
            }
        } elseif (isset($payload['status'])) {
            // JSON / fetch path
            $payload['status'] = ((int)$payload['status'] || $payload['status'] === 'on' || $payload['status'] === TRUE) ? 1 : 0;
        }
        return $payload;
    }

    protected function _require_login()
    {
        if (!$this->session->userdata('logged_in')) {
            return $this->_error('Not authenticated', 401);
        }
    }

    protected function _set_cors_headers()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
        if (in_array('*', $this->allowed_origins, TRUE)) {
            header('Access-Control-Allow-Origin: *');
        } elseif (in_array($origin, $this->allowed_origins, TRUE)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
        header('Content-Type: application/json; charset=utf-8');

        if (strtolower($this->input->method()) === 'options') {
            header('HTTP/1.1 204 No Content');
            exit;
        }
    }

    protected function _json($data, $code = 200)
    {
        $this->output
            ->set_status_header($code)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function _error($message, $code = 400, $extra = [])
    {
        $this->_json(array_merge(['success' => FALSE, 'error' => $message], $extra), $code);
    }
}