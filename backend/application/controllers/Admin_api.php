<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API Admin Portal — REST API
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
 *   GET    /admin/api/reservations             → list (?status= filter)
 *   GET    /admin/api/reservations/:id         → fetch one (trip + payment history)
 *   POST   /admin/api/reservations/:id/accept  → capture held funds, mark paid
 *   POST   /admin/api/reservations/:id/reject  → release hold, mark cancelled
 *   POST   /admin/api/reservations/:id/charge  → bill the saved card again { amount, description? }
 */

class Admin_api extends CI_Controller
{
    /** Comma-separated list of allowed origins, or ['*'] for any. */
    protected $allowed_origins = ['*'];

    public function __construct()
    {
        parent::__construct();
        $this->load->model(['Admin_model', 'Vehicle_model', 'Promo_model', 'Addon_model', 'Surcharge_model', 'Settings_model', 'Booking_model']);
        $this->load->library(['session', 'paypal', 'legacy_reservations', 'mailer']);
        $this->load->helper('url');
        $this->_set_cors_headers();

        // Block everything unless logged in. A constructor can't stop
        // CodeIgniter from still invoking the routed action method
        // afterward — returning early here would NOT be enough — so this
        // has to _display() the buffered 401 body and exit() outright.
        // (CI's normal _display() call only happens after the controller
        // method returns via CodeIgniter.php's own flow, not on a bare
        // exit, so it must be triggered explicitly before exiting here.)
        if (!$this->session->userdata('logged_in')) {
            // For preflight OPTIONS, the constructor continues but we'll 401 below
            $method = $this->input->method();
            if (strtolower($method) !== 'options') {
                $this->_error('Not authenticated', 401);
                $this->output->_display();
                exit;
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
        if (!$this->_require_login()) return;
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
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $row = $this->Vehicle_model->get($id);
        if (!$row) return $this->_error('Vehicle not found', 404);
        $row['public'] = $this->Vehicle_model->to_public($row);
        $this->_json(['success' => TRUE, 'vehicle' => $row]);
    }

    /** POST /admin/api/vehicles */
    public function vehicles_create()
    {
        if (!$this->_require_login()) return;

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
        if (!$this->_require_login()) return;
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
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Vehicle_model->delete($id);
        if (!$ok) return $this->_error('Could not delete vehicle', 500);
        $this->_json(['success' => TRUE, 'id' => (int)$id]);
    }

    /** POST /admin/api/vehicles/:id/toggle */
    public function vehicles_toggle($id = NULL)
    {
        if (!$this->_require_login()) return;
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
        if (!$this->_require_login()) return;
        $this->_json(['success' => TRUE, 'promos' => $this->Promo_model->list_all()]);
    }

    /** POST /admin/api/promos */
    public function promos_create()
    {
        if (!$this->_require_login()) return;
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
        if (!$this->_require_login()) return;
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
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Promo_model->delete($id);
        if (!$ok) return $this->_error('Could not delete promo code', 500);
        $this->_json(['success' => TRUE, 'id' => (int)$id]);
    }

    /** POST /admin/api/promos/:id/toggle */
    public function promos_toggle($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Promo_model->toggle_status($id);
        if (!$ok) return $this->_error('Could not toggle promo code', 500);
        $this->_json(['success' => TRUE, 'promo' => $this->Promo_model->get($id)]);
    }

    // ----------------- Add-ons -----------------

    /** GET /admin/api/addons */
    public function addons_index()
    {
        if (!$this->_require_login()) return;
        $this->_json(['success' => TRUE, 'addons' => $this->Addon_model->list_all()]);
    }

    /** POST /admin/api/addons */
    public function addons_create()
    {
        if (!$this->_require_login()) return;
        $payload = $this->_collect_payload();
        $errors = $this->Addon_model->validate_form($payload, TRUE);
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }
        $id = $this->Addon_model->create($payload);
        if (!$id) return $this->_error('Could not create add-on', 500, ['db' => $this->db->error()]);
        $this->_json(['success' => TRUE, 'addon' => $this->Addon_model->get($id)], 201);
    }

    /** POST /admin/api/addons/:id */
    public function addons_update($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $existing = $this->Addon_model->get($id);
        if (!$existing) return $this->_error('Add-on not found', 404);
        $payload = $this->_collect_payload();
        $errors = $this->Addon_model->validate_form($payload, FALSE, $existing);
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }
        $ok = $this->Addon_model->update($id, $payload);
        if (!$ok) return $this->_error('Could not update add-on', 500);
        $this->_json(['success' => TRUE, 'addon' => $this->Addon_model->get($id)]);
    }

    /** POST /admin/api/addons/:id/delete */
    public function addons_delete($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Addon_model->delete($id);
        if (!$ok) return $this->_error('Could not delete add-on', 500);
        $this->_json(['success' => TRUE, 'id' => (int)$id]);
    }

    /** POST /admin/api/addons/:id/toggle */
    public function addons_toggle($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Addon_model->toggle_status($id);
        if (!$ok) return $this->_error('Could not toggle add-on', 500);
        $this->_json(['success' => TRUE, 'addon' => $this->Addon_model->get($id)]);
    }

    // ----------------- Surcharges -----------------

    /** GET /admin/api/surcharges */
    public function surcharges_index()
    {
        if (!$this->_require_login()) return;
        $this->_json(['success' => TRUE, 'surcharges' => $this->Surcharge_model->list_all()]);
    }

    /** POST /admin/api/surcharges */
    public function surcharges_create()
    {
        if (!$this->_require_login()) return;
        $payload = $this->_collect_payload();
        $errors = $this->Surcharge_model->validate_form($payload, TRUE);
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }
        $id = $this->Surcharge_model->create($payload);
        if (!$id) return $this->_error('Could not create surcharge', 500, ['db' => $this->db->error()]);
        $this->_json(['success' => TRUE, 'surcharge' => $this->Surcharge_model->get($id)], 201);
    }

    /** POST /admin/api/surcharges/:id */
    public function surcharges_update($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $existing = $this->Surcharge_model->get($id);
        if (!$existing) return $this->_error('Surcharge not found', 404);
        $payload = $this->_collect_payload();
        $errors = $this->Surcharge_model->validate_form($payload, FALSE, $existing);
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }
        $ok = $this->Surcharge_model->update($id, $payload);
        if (!$ok) return $this->_error('Could not update surcharge', 500);
        $this->_json(['success' => TRUE, 'surcharge' => $this->Surcharge_model->get($id)]);
    }

    /** POST /admin/api/surcharges/:id/delete */
    public function surcharges_delete($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Surcharge_model->delete($id);
        if (!$ok) return $this->_error('Could not delete surcharge', 500);
        $this->_json(['success' => TRUE, 'id' => (int)$id]);
    }

    /** POST /admin/api/surcharges/:id/toggle */
    public function surcharges_toggle($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $ok = $this->Surcharge_model->toggle_status($id);
        if (!$ok) return $this->_error('Could not toggle surcharge', 500);
        $this->_json(['success' => TRUE, 'surcharge' => $this->Surcharge_model->get($id)]);
    }

    // ----------------- Settings -----------------

    /** GET /admin/api/settings — global pricing settings (v16) */
    public function settings_index()
    {
        if (!$this->_require_login()) return;
        $this->_json(['success' => TRUE, 'settings' => $this->_format_settings_for_json($this->Settings_model->pricing_settings())]);
    }

    /**
     * Formatted STRINGs, not floats — this server's php.ini has
     * serialize_precision=100 (non-default; should be -1), so json_encode()
     * expands any float that isn't exactly representable in binary out to
     * ~100 digits regardless of round(). number_format() sidesteps the
     * float serializer — same fix as Api::reservation_update()'s amount.
     * Garage lat/lng keep full precision (matching settings_update()'s own
     * validation precision below); every other pricing knob is 2 decimals.
     */
    protected function _format_settings_for_json(array $settings)
    {
        $out = [];
        foreach ($settings as $k => $v) {
            $decimals = in_array($k, ['pricing_garage_lat', 'pricing_garage_lng'], TRUE) ? 14 : 2;
            $out[$k] = number_format((float)$v, $decimals, '.', '');
        }
        return $out;
    }

    /**
     * POST /admin/api/settings/save — the pricing_* keys from
     * Settings_model::pricing_settings(), all required numerics (with a
     * 0-100 cap on the gratuity percent, matching how Vehicle_model caps
     * percent fields).
     */
    public function settings_update()
    {
        if (!$this->_require_login()) return;
        $payload = $this->_collect_payload();

        $fields = [
            'pricing_local_service_radius_miles',
            'pricing_regional_travel_fee_per_mile',
            'pricing_long_distance_multiplier',
            'pricing_worldwide_multiplier',
            'pricing_local_hourly_minimum_hours',
            'pricing_regional_hourly_minimum_hours',
            'pricing_worldwide_hourly_minimum_hours',
            'pricing_default_gratuity_pct',
            'pricing_garage_lat',
            'pricing_garage_lng',
            'pricing_worldwide_quote_threshold_miles',
            'pricing_child_seat_fee',
        ];

        $errors = [];
        $clean = [];
        foreach ($fields as $f) {
            $v = $payload[$f] ?? NULL;
            if ($v === NULL || $v === '' || !is_numeric($v)) {
                $errors[$f] = 'Must be a number.';
                continue;
            }
            // Garage lat/lng can be negative; every other pricing knob must be >= 0.
            if (!in_array($f, ['pricing_garage_lat', 'pricing_garage_lng'], TRUE) && (float)$v < 0) {
                $errors[$f] = 'Must be zero or greater.';
                continue;
            }
            if ($f === 'pricing_default_gratuity_pct' && (float)$v > 100) {
                $errors[$f] = 'Must be 100 or less (it\'s a percentage).';
                continue;
            }
            $clean[$f] = number_format((float)$v, ($f === 'pricing_garage_lat' || $f === 'pricing_garage_lng') ? 14 : 2, '.', '');
        }
        if (!empty($errors)) {
            return $this->_error('Validation failed', 422, ['fields' => $errors]);
        }

        $this->Settings_model->set_many($clean);
        $this->_json(['success' => TRUE, 'settings' => $this->_format_settings_for_json($this->Settings_model->pricing_settings())]);
    }

    // ----------------- Reservations -----------------

    /** GET /admin/api/reservations?status=&q=&page=&per_page= — see Booking_model::_apply_search_filter() */
    public function reservations_index()
    {
        if (!$this->_require_login()) return;
        $status  = trim((string)$this->input->get('status'));
        $search  = trim((string)$this->input->get('q'));
        $filters = [];
        if ($status !== '') $filters['status'] = $status;
        if ($search !== '') $filters['search'] = $search;

        $perPage = max(1, min(200, (int)($this->input->get('per_page') ?: 50)));
        $total   = $this->Booking_model->count_all($filters);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page    = max(1, min($totalPages, (int)($this->input->get('page') ?: 1)));
        $offset  = ($page - 1) * $perPage;

        $this->_json([
            'success'      => TRUE,
            'reservations' => $this->Booking_model->list_all($filters, $perPage, $offset),
            'pagination'   => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    /** GET /admin/api/reservations/:id */
    public function reservations_get($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $row = $this->Booking_model->get_booking($id);
        if (!$row) return $this->_error('Reservation not found', 404);
        $this->_json(['success' => TRUE, 'reservation' => $row]);
    }

    /**
     * POST /admin/api/reservations/:id/accept
     * Captures the held funds, marks the reservation paid, bumps promo
     * usage, and sends the customer's confirmation email.
     */
    public function reservations_accept($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $booking = $this->Booking_model->get_booking($id);
        if (!$booking) return $this->_error('Reservation not found', 404);
        if (empty($booking['paypal_auth_transaction_id'])) {
            return $this->_error('This reservation has no authorized payment to capture.', 422);
        }
        if ($booking['status'] !== 'awaiting_approval') {
            return $this->_error('Only reservations awaiting approval can be accepted (current status: ' . $booking['status'] . ').', 422);
        }

        try {
            $result = $this->paypal->capture($booking['paypal_auth_transaction_id'], $booking['amount']);
        } catch (Exception $e) {
            log_message('error', '[Admin_api] reservations_accept capture: ' . $e->getMessage());
            return $this->_error('PayPal capture failed', 502, ['detail' => $e->getMessage()]);
        }

        $captureId = $result['TRANSACTIONID'] ?? NULL;
        $this->Booking_model->record_payment($id, $captureId, 'capture', $result['PAYMENTSTATUS'] ?? ($result['ACK'] ?? 'Success'), [
            'amount'   => $booking['amount'],
            'currency' => 'USD',
            'result'   => $result,
        ]);
        if ($captureId) $this->Booking_model->save_paypal_capture($id, $captureId);
        $this->Booking_model->set_approval($id, 'paid', $this->session->userdata('admin_username'));

        if (!empty($booking['promo_code'])) {
            $this->Promo_model->record_booking_use($booking['promo_code']);
        }
        // No second arg here — CI_Config::item($item, $index) only looks
        // inside $this->config[$index] when the config file was loaded
        // with $use_sections = TRUE (load($file, TRUE)), which this app
        // never does; config files here are always loaded flat. Passing
        // 'kafeh' (the config file's old name, before it was renamed to
        // crmsync.php) meant this always evaluated to NULL/false —
        // _send_confirmation() has never actually run.
        if ($this->config->item('send_confirmation_email')) {
            $this->_send_confirmation($booking);
        }

        // Re-fetch rather than reuse $booking — need the fresh
        // capture id/status just saved above, plus stops/return_leg for
        // the legacy portal push. Never blocks the response either way
        // (see Legacy_reservations::sync()'s own docblock).
        $freshBooking = $this->Booking_model->get_booking($id);
        $this->legacy_reservations->sync($freshBooking);

        $this->_json(['success' => TRUE, 'reservation' => $freshBooking]);
    }

    /**
     * POST /admin/api/reservations/:id/reject
     * Releases the authorization hold — nothing is charged — and marks
     * the reservation cancelled.
     */
    public function reservations_reject($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $booking = $this->Booking_model->get_booking($id);
        if (!$booking) return $this->_error('Reservation not found', 404);
        if (empty($booking['paypal_auth_transaction_id'])) {
            return $this->_error('This reservation has no authorized payment to cancel.', 422);
        }
        if ($booking['status'] !== 'awaiting_approval') {
            return $this->_error('Only reservations awaiting approval can be rejected (current status: ' . $booking['status'] . ').', 422);
        }

        try {
            $result = $this->paypal->voidTransaction($booking['paypal_auth_transaction_id']);
        } catch (Exception $e) {
            log_message('error', '[Admin_api] reservations_reject void: ' . $e->getMessage());
            return $this->_error('PayPal void failed', 502, ['detail' => $e->getMessage()]);
        }

        // DoVoid doesn't mint a new transaction id — log against the
        // authorization id it just released.
        $this->Booking_model->record_payment($id, $booking['paypal_auth_transaction_id'], 'cancel', $result['ACK'] ?? 'Success', [
            'amount'   => $booking['amount'],
            'currency' => 'USD',
            'result'   => $result,
        ]);
        $this->Booking_model->set_approval($id, 'cancelled', $this->session->userdata('admin_username'));

        $this->_json(['success' => TRUE, 'reservation' => $this->Booking_model->get_booking($id)]);
    }

    /**
     * POST /admin/api/reservations/:id/charge — { amount, description? }
     * NOTE: not currently supported. PayPal's Orders v2 redirect
     * checkout doesn't retain a chargeable payment method after the
     * order is captured (unlike the old classic-NVP Reference
     * Transactions this used to rely on), so Paypal::chargeReference()
     * always throws — this endpoint exists so the admin UI gets a clear
     * error instead of a broken button. See backend/application/libraries/Paypal.php.
     */
    public function reservations_charge($id = NULL)
    {
        if (!$this->_require_login()) return;
        if (!$id) return $this->_error('ID required', 400);
        $booking = $this->Booking_model->get_booking($id);
        if (!$booking) return $this->_error('Reservation not found', 404);
        if ($booking['status'] !== 'paid' || empty($booking['paypal_capture_transaction_id'])) {
            return $this->_error('This reservation must be accepted (paid) before it can be charged again.', 422);
        }

        $payload = $this->_collect_payload();
        $amount = $payload['amount'] ?? NULL;
        if (!is_numeric($amount) || (float)$amount <= 0) {
            return $this->_error('Validation failed', 422, ['fields' => ['amount' => 'Enter an amount greater than 0.']]);
        }
        $description = trim((string)($payload['description'] ?? '')) ?: ('Additional charge — booking ' . $id);

        try {
            $result = $this->paypal->chargeReference($booking['paypal_capture_transaction_id'], $amount, $description);
        } catch (Exception $e) {
            log_message('error', '[Admin_api] reservations_charge: ' . $e->getMessage());
            return $this->_error('PayPal charge failed', 502, ['detail' => $e->getMessage()]);
        }

        $this->Booking_model->record_payment($id, $result['TRANSACTIONID'] ?? NULL, 'additional_charge', $result['PAYMENTSTATUS'] ?? ($result['ACK'] ?? 'Success'), [
            'amount'      => $amount,
            'currency'    => 'USD',
            'description' => $description,
            'result'      => $result,
        ]);

        $this->_json(['success' => TRUE, 'reservation' => $this->Booking_model->get_booking($id)]);
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

    /** Best-effort confirmation email, sent once a reservation is accepted — sent via SMTP (see Mailer library), not mail(). */
    protected function _send_confirmation($booking)
    {
        $to      = $booking['email'];
        $subject = 'Booking ' . $booking['booking_id'] . ' Confirmed';
        $body    =
            "Hi {$booking['first_name']},\n\n" .
            "Your booking is confirmed.\n\n" .
            "Booking ID: {$booking['booking_id']}\n" .
            "Service:    {$booking['service_type']}\n" .
            "Pickup:     {$booking['pickup_date']} at {$booking['pickup_time']}\n" .
            "From:       {$booking['pickup']}\n" .
            "To:         {$booking['dropoff']}\n" .
            "Vehicle:    {$booking['vehicle_name']}\n" .
            "Amount:     \${$booking['amount']}\n" .
            (!empty($booking['promo_code'])
                ? "Promo:      {$booking['promo_code']} (-\${$booking['discount_amount']})\n"
                : "") .
            "\nThank you for choosing our chauffeur service.\n";

        $this->mailer->send($to, $subject, $body);
    }

    /**
     * Returns TRUE when logged in. Callers MUST check the return value —
     * `_error()` only buffers a response body, it doesn't halt execution,
     * so `$this->_require_login();` alone (ignoring the return value) lets
     * the action's own later `_json()` call silently overwrite the 401.
     */
    protected function _require_login()
    {
        if (!$this->session->userdata('logged_in')) {
            $this->_error('Not authenticated', 401);
            return FALSE;
        }
        return TRUE;
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