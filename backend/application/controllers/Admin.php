<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API Admin Portal — page rendering
 *
 * All methods require an authenticated admin (session-checked at the
 * top of every action). Unauthenticated requests redirect to the
 * login page with a flash message.
 *
 * Routes:
 *   GET /admin                → dashboard (stats + recent vehicles)
 *   GET /admin/vehicles       → list + inline create/edit
 *   GET /admin/vehicles/new   → dedicated create form (also reachable as a panel)
 *   GET /admin/vehicles/:id   → dedicated edit form
 */

#[\AllowDynamicProperties]
class Admin extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model(['Admin_model', 'Vehicle_model', 'Promo_model', 'Addon_model', 'Surcharge_model', 'Settings_model', 'Booking_model', 'Client_booking_model', 'Company_model', 'Customer_model', 'Terms_model']);
        $this->load->library(['session', 'customer_mail']);
        $this->load->helper(['url', 'form', 'kfb_format']);
        $this->_require_login();
    }

    /** GET /admin — dashboard */
    public function index()
    {
        $vehicles = $this->Vehicle_model->list_all();
        $enabled  = array_filter($vehicles, function ($v) { return (int)$v['status'] === 1; });

        $reservationCounts = $this->Booking_model->count_by_status();
        $recentReservations = $this->Booking_model->list_all([], 8, 0);

        $data = [
            'page_title' => 'Dashboard',
            'admin'      => $this->_current_admin(),
            'stats'      => [
                'total_vehicles'    => count($vehicles),
                'enabled_vehicles'  => count($enabled),
                'disabled_vehicles' => count($vehicles) - count($enabled),
            ],
            'reservation_stats' => [
                'total'             => $reservationCounts['total'] ?? 0,
                'awaiting_approval' => $reservationCounts['awaiting_approval'] ?? 0,
                'paid'              => $reservationCounts['paid'] ?? 0,
                'cancelled'         => $reservationCounts['cancelled'] ?? 0,
            ],
            // Every vehicle, not just the first 5 — the dashboard card
            // scrolls (see .kfb-table-scroll-v) instead of truncating.
            'recent_vehicles'      => $vehicles,
            'recent_reservations'  => $recentReservations,
            'flash'                => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/dashboard', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** GET /admin/vehicles — list + edit panel in one page (AJAX-driven) */
    public function vehicles()
    {
        $data = [
            'page_title' => 'Vehicles',
            'admin'      => $this->_current_admin(),
            'vehicles'   => $this->Vehicle_model->list_all(),
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/vehicles', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** GET /admin/vehicles/new — blank form (optional page) */
    public function vehicle_new()
    {
        return $this->vehicles();
    }

    /** GET /admin/vehicles/:id — pre-filled edit form (optional page) */
    public function vehicle_edit($id = NULL)
    {
        if (!$id) return redirect('admin/vehicles');
        $vehicle = $this->Vehicle_model->get($id);
        if (!$vehicle) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'Vehicle not found.']);
            return redirect('admin/vehicles');
        }
        $data = [
            'page_title' => 'Edit Vehicle',
            'admin'      => $this->_current_admin(),
            'vehicles'   => $this->Vehicle_model->list_all(),
            'editing'    => $vehicle,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/vehicles', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** POST /admin/settings/payments — the global PayPal on/off switch */
    public function settings_payments()
    {
        $on = $this->input->post('paypal_enabled') === '1';
        $this->Settings_model->set('paypal_enabled', $on ? '1' : '0');
        $this->session->set_flashdata('flash', ['type' => 'success', 'message' => $on
            ? 'PayPal is ON. New reservations go through PayPal authorization, as before.'
            : 'PayPal is OFF. New reservations skip PayPal and are created as awaiting approval; you accept or reject them with no payment step.']);
        return redirect('admin/settings');
    }

    // ----------------- Terms & Conditions -----------------

    /** GET /admin/terms — rich-text editor for the widget's Terms & Conditions popup */
    public function terms()
    {
        $data = [
            'page_title' => 'Terms & Conditions',
            'admin'      => $this->_current_admin(),
            'terms'      => $this->Terms_model->get(),
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/terms', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** POST /admin/terms/save */
    public function terms_save()
    {
        $admin = $this->_current_admin();
        $saved = $this->Terms_model->save((string)$this->input->post('html', FALSE), $admin['username'] ?? NULL);
        $this->session->set_flashdata('flash', $saved === NULL
            ? ['type' => 'error', 'message' => 'The terms cannot be empty — nothing was saved.']
            : ['type' => 'success', 'message' => 'Terms & Conditions saved. The booking widget shows the new text straight away.']);
        return redirect('admin/terms');
    }

    // ----------------- Users (customers) -----------------

    /** GET /admin/users — all customers across companies; ?q= ?company= ?status= ?verified= ?page= */
    public function users()
    {
        $filters = [
            'search'     => trim((string)$this->input->get('q')),
            'company_id' => (int)$this->input->get('company'),
            'status'     => trim((string)$this->input->get('status')),
            'verified'   => trim((string)$this->input->get('verified')),
        ];
        $perPage    = 50;
        $total      = $this->Customer_model->admin_count($filters);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = max(1, min($totalPages, (int)$this->input->get('page')));

        $data = [
            'page_title' => 'Users',
            'admin'      => $this->_current_admin(),
            'users'      => $this->Customer_model->admin_list($filters, $perPage, ($page - 1) * $perPage),
            'filters'    => $filters,
            'companies'  => $this->Company_model->list_all(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/users', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** GET /admin/users/:id — profile, account actions, saved cards (brand + last 4 only), reservations */
    public function user_detail($id = NULL)
    {
        $user = $this->Customer_model->admin_get($id);
        if (!$user) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'User not found.']);
            return redirect('admin/users');
        }
        $data = [
            'page_title'   => 'User ' . trim($user['first_name'] . ' ' . $user['last_name']),
            'admin'        => $this->_current_admin(),
            'user'         => $user,
            'cards'        => $this->Customer_model->list_cards($user['id']),
            'reservations' => $this->Booking_model->list_for_customer($user['id']),
            'flash'        => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/user_detail', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** POST /admin/users/:id/save — form=profile (name, phone) or form=email */
    public function user_save($id = NULL)
    {
        $user = $this->Customer_model->admin_get($id);
        if (!$user) return $this->_user_flash('admin/users', 'error', 'User not found.');
        $back = 'admin/users/' . (int)$user['id'];

        if ($this->input->post('form') === 'email') {
            $res = $this->Customer_model->admin_change_email($user['id'], (string)$this->input->post('email'));
            if ($res === 'invalid') return $this->_user_flash($back, 'error', 'Enter a valid email address.');
            if ($res === 'same')    return $this->_user_flash($back, 'info', 'That is already the account email.');
            if ($res === 'taken')   return $this->_user_flash($back, 'error', 'Another account of this company already uses that email.');
            $fresh = $this->Customer_model->get($user['id']);
            $this->customer_mail->send_email_changed($fresh, $res, $fresh['email'], $this->Company_model->get($user['company_id']));
            return $this->_user_flash($back, 'success', 'Email changed to ' . $fresh['email'] . '. It is unverified — the customer verifies it at their next login — and they were notified by email. Their sessions were ended.');
        }

        $first = trim((string)$this->input->post('first_name'));
        $last  = trim((string)$this->input->post('last_name'));
        if ($first === '' || $last === '') return $this->_user_flash($back, 'error', 'First and last name are required.');
        $this->Customer_model->admin_update_profile($user['id'], $first, $last, (string)$this->input->post('phone'));
        return $this->_user_flash($back, 'success', 'Profile updated.');
    }

    /** POST /admin/users/:id/action — action = disable|enable|mark_verified|resend_code|send_reset_link|set_password|logout_all|delete */
    public function user_action($id = NULL)
    {
        $user = $this->Customer_model->admin_get($id);
        if (!$user) return $this->_user_flash('admin/users', 'error', 'User not found.');
        $back   = 'admin/users/' . (int)$user['id'];
        $action = (string)$this->input->post('action');

        switch ($action) {
            case 'disable':
                $this->Customer_model->admin_set_status($user['id'], 'disabled');
                return $this->_user_flash($back, 'success', 'Account disabled. They cannot log in and their sessions were ended.');
            case 'enable':
                $this->Customer_model->admin_set_status($user['id'], 'active');
                return $this->_user_flash($back, 'success', 'Account enabled.');
            case 'mark_verified':
                $this->Customer_model->admin_mark_verified($user['id']);
                return $this->_user_flash($back, 'success', 'Email marked as verified.');
            case 'logout_all':
                $this->Customer_model->revoke_all_tokens($user['id']);
                return $this->_user_flash($back, 'success', 'All active sessions ended.');
            case 'resend_code':
                if (!(int)$user['has_account']) return $this->_user_flash($back, 'error', 'This is a guest record with no account, so there is nothing to verify.');
                if (!empty($user['email_verified_at'])) return $this->_user_flash($back, 'info', 'This email is already verified.');
                $otp = $this->Customer_model->create_email_otp($user['id']);
                $this->customer_mail->send_verification($user, $otp);
                return $this->_user_flash($back, 'success', 'A new verification code was emailed to ' . $user['email'] . '.');
            case 'send_reset_link':
                $base = trim((string)($user['widget_page_url'] ?? ''));
                if (!preg_match('#^https?://#i', $base)) return $this->_user_flash($back, 'error', 'Set this company\'s "Widget page URL" under Companies first — the reset link opens that page.');
                $reset = $this->Customer_model->admin_create_password_reset($user['id']);
                if (!$reset) return $this->_user_flash($back, 'error', 'This is a guest record with no account password to reset.');
                list($customer, $raw) = $reset;
                $link = $base . ((strpos($base, '?') === FALSE) ? '?' : '&') . 'kfb_reset_token=' . rawurlencode($raw);
                $this->customer_mail->send_password_reset($customer, $link);
                return $this->_user_flash($back, 'success', 'A password-reset link (valid 1 hour) was emailed to ' . $user['email'] . '.');
            case 'set_password':
                $pw = (string)$this->input->post('new_password');
                if (strlen($pw) < 6) return $this->_user_flash($back, 'error', 'The password must be at least 6 characters.');
                $this->Customer_model->admin_set_password($user['id'], $pw);
                return $this->_user_flash($back, 'success', 'Password set. The customer\'s sessions were ended — tell them the new password.');
            case 'delete':
                $kept = $this->Customer_model->admin_delete($user['id']);
                if ($kept === FALSE) return $this->_user_flash($back, 'error', 'Could not delete the user.');
                return $this->_user_flash('admin/users', 'success', 'User deleted.' . ($kept ? " {$kept} reservation(s) were kept as guest records." : ''));
        }
        return $this->_user_flash($back, 'error', 'Unknown action.');
    }

    protected function _user_flash($to, $type, $message)
    {
        $this->session->set_flashdata('flash', ['type' => $type, 'message' => $message]);
        return redirect($to);
    }

    // ----------------- Companies -----------------

    /** GET /admin/companies — list + create/edit form */
    public function companies()
    {
        $this->_render_companies(NULL);
    }

    public function company_new()
    {
        $this->_render_companies(NULL);
    }

    /** GET /admin/companies/:id */
    public function company_edit($id = NULL)
    {
        $company = $this->Company_model->get($id);
        if (!$company) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'Company not found.']);
            return redirect('admin/companies');
        }
        $company['domains'] = $this->Company_model->domains_for($company['id']);
        $this->_render_companies($company);
    }

    /** POST /admin/companies/save — create or update (hidden `id`), plain multipart form post */
    public function company_save()
    {
        $id = (int)$this->input->post('id');
        $existing = $id ? $this->Company_model->get($id) : NULL;
        if ($id && !$existing) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'Company not found.']);
            return redirect('admin/companies');
        }

        $post    = $this->input->post(NULL, TRUE) ?: [];
        $domains = preg_split('/[\s,;]+/', (string)($post['domains'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        $logoError = NULL;
        $logo = $this->Company_model->handle_logo_upload('logo', $logoError);

        $errors = [];
        $savedId = NULL;
        if ($logoError) {
            $errors['logo'] = $logoError;
        } else {
            $savedId = $this->Company_model->save($id ?: NULL, $post, $domains, $logo, $errors);
        }

        if ($savedId) {
            // Replacing the logo: drop the old file only after the new one is saved.
            if ($logo && $existing && !empty($existing['logo'])) $this->Company_model->delete_logo_file($existing['logo']);
            $this->session->set_flashdata('flash', ['type' => 'success', 'message' => 'Company saved.']);
            return redirect('admin/companies/' . $savedId);
        }

        // Validation failed — clean up a just-uploaded logo and redisplay with the posted values.
        if ($logo) $this->Company_model->delete_logo_file($logo);
        $company = array_merge($existing ?: [], $post, ['id' => $id ?: NULL, 'domains' => $domains, 'logo' => $existing['logo'] ?? NULL]);
        $this->_render_companies($company, $errors);
    }

    /** POST /admin/companies/:id/remove-logo */
    public function company_remove_logo($id = NULL)
    {
        if ($this->Company_model->get($id)) {
            $this->Company_model->remove_logo($id);
            $this->session->set_flashdata('flash', ['type' => 'success', 'message' => 'Logo removed.']);
        }
        return redirect('admin/companies/' . (int)$id);
    }

    protected function _render_companies($editing, array $errors = [])
    {
        $data = [
            'page_title' => $editing && !empty($editing['id']) ? 'Edit Company' : 'Companies',
            'admin'      => $this->_current_admin(),
            'companies'  => $this->Company_model->list_all(),
            'editing'    => $editing,
            'errors'     => $errors,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/companies', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    // ----------------- Promo codes -----------------

    /** GET /admin/promos — list + create/edit form */
    public function promos()
    {
        $data = [
            'page_title' => 'Promo Codes',
            'admin'      => $this->_current_admin(),
            'promos'     => $this->Promo_model->list_all(),
            'editing'    => NULL,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/promos', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** GET /admin/promos/new — blank create form */
    public function promo_new()
    {
        return $this->promos();
    }

    /** GET /admin/promos/:id — pre-filled edit form */
    public function promo_edit($id = NULL)
    {
        if (!$id) return redirect('admin/promos');
        $promo = $this->Promo_model->get($id);
        if (!$promo) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'Promo code not found.']);
            return redirect('admin/promos');
        }
        $data = [
            'page_title' => 'Edit Promo Code',
            'admin'      => $this->_current_admin(),
            'promos'     => $this->Promo_model->list_all(),
            'editing'    => $promo,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/promos', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    // ----------------- Add-ons -----------------

    /** GET /admin/addons — list + create/edit form */
    public function addons()
    {
        $data = [
            'page_title' => 'Add-On Services',
            'admin'      => $this->_current_admin(),
            'addons'     => $this->Addon_model->list_all(),
            'editing'    => NULL,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/addons', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** GET /admin/addons/new — blank create form */
    public function addon_new()
    {
        return $this->addons();
    }

    /** GET /admin/addons/:id — pre-filled edit form */
    public function addon_edit($id = NULL)
    {
        if (!$id) return redirect('admin/addons');
        $addon = $this->Addon_model->get($id);
        if (!$addon) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'Add-on not found.']);
            return redirect('admin/addons');
        }
        $data = [
            'page_title' => 'Edit Add-On',
            'admin'      => $this->_current_admin(),
            'addons'     => $this->Addon_model->list_all(),
            'editing'    => $addon,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/addons', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    // ----------------- Settings -----------------

    /** GET /admin/settings — global settings (Meet & Greet fees) */
    public function settings()
    {
        $data = [
            'page_title' => 'Pricing Settings',
            'admin'      => $this->_current_admin(),
            'settings'   => $this->Settings_model->pricing_settings(),
            'paypal_enabled' => $this->Settings_model->paypal_enabled(),
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/settings', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    // ----------------- Surcharges -----------------

    /** GET /admin/surcharges — list + inline create/edit */
    public function surcharges()
    {
        $data = [
            'page_title' => 'Surcharges',
            'admin'      => $this->_current_admin(),
            'surcharges' => $this->Surcharge_model->list_all(),
            'editing'    => NULL,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/surcharges', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** GET /admin/surcharges/new — blank create form */
    public function surcharge_new()
    {
        return $this->surcharges();
    }

    /** GET /admin/surcharges/:id — pre-filled edit form */
    public function surcharge_edit($id = NULL)
    {
        if (!$id) return redirect('admin/surcharges');
        $surcharge = $this->Surcharge_model->get($id);
        if (!$surcharge) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'Surcharge not found.']);
            return redirect('admin/surcharges');
        }
        $data = [
            'page_title' => 'Edit Surcharge',
            'admin'      => $this->_current_admin(),
            'surcharges' => $this->Surcharge_model->list_all(),
            'editing'    => $surcharge,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/surcharges', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    // ----------------- Reservations -----------------

    /**
     * GET /admin/reservations — list, optionally filtered by ?status=,
     * free-text searched via ?q= (combines with status — see
     * Booking_model::_apply_search_filter()), paginated via ?page=
     */
    public function reservations()
    {
        $status  = trim((string)$this->input->get('status'));
        $search  = trim((string)$this->input->get('q'));
        $company = (int)$this->input->get('company');
        $filters = [];
        if ($status !== '') $filters['status'] = $status;
        if ($search !== '') $filters['search'] = $search;
        if ($company)       $filters['company_id'] = $company;

        $perPage = 50;
        $total   = $this->Booking_model->count_all($filters);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page    = max(1, min($totalPages, (int)$this->input->get('page')));
        $offset  = ($page - 1) * $perPage;

        $data = [
            'page_title'   => 'Reservations',
            'admin'        => $this->_current_admin(),
            'reservations' => $this->Booking_model->list_all($filters, $perPage, $offset),
            'status_filter'=> $status,
            'search_query' => $search,
            'company_filter' => $company,
            'companies'    => $this->Company_model->list_all(),
            'pagination'   => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
            'flash'        => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/reservations', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** GET /admin/reservations/:id — trip detail, payment history, accept/reject/charge */
    public function reservation_detail($id = NULL)
    {
        if (!$id) return redirect('admin/reservations');
        $booking = $this->Booking_model->get_booking($id);
        if (!$booking) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'Reservation not found.']);
            return redirect('admin/reservations');
        }
        // Estimated drop-off clock time for each leg — same calculation as the
        // customer confirmation email and the legacy portal entry.
        $this->load->library('legacy_reservations');
        $booking['dropoff_time_calc'] = $this->legacy_reservations->dropoff_time($booking, $booking);
        if (!empty($booking['return_leg'])) {
            $booking['return_leg']['service_type']    = $booking['service_type'] ?? NULL;
            $booking['return_leg']['hours_requested'] = $booking['hours_requested'] ?? NULL;
            $booking['return_leg']['dropoff_time_calc'] = $this->legacy_reservations->dropoff_time($booking, $booking['return_leg']);
        }
        $data = [
            'page_title' => 'Reservation ' . $booking['booking_id'],
            'admin'      => $this->_current_admin(),
            'booking'    => $booking,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/reservation_detail', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /**
     * GET /admin/client-bookings — unpriced submissions from
     * reservation-detail.html, reviewed manually (no accept/reject,
     * no status). See Client_booking_model.
     */
    public function client_bookings()
    {
        $search  = trim((string)$this->input->get('q'));
        $filters = [];
        if ($search !== '') $filters['search'] = $search;

        $perPage = 50;
        $total   = $this->Client_booking_model->count_all($filters);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page    = max(1, min($totalPages, (int)$this->input->get('page')));
        $offset  = ($page - 1) * $perPage;

        $data = [
            'page_title'      => 'Client Bookings',
            'admin'           => $this->_current_admin(),
            'client_bookings' => $this->Client_booking_model->list_all($filters, $perPage, $offset),
            'search_query'    => $search,
            'pagination'      => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
            'flash'           => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/client_bookings', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    /** GET /admin/client-bookings/:id — trip detail + delete */
    public function client_booking_detail($id = NULL)
    {
        if (!$id) return redirect('admin/client-bookings');
        $booking = $this->Client_booking_model->get_booking($id);
        if (!$booking) {
            $this->session->set_flashdata('flash', ['type' => 'error', 'message' => 'Client booking not found.']);
            return redirect('admin/client-bookings');
        }
        $data = [
            'page_title' => 'Client Booking ' . $booking['booking_id'],
            'admin'      => $this->_current_admin(),
            'booking'    => $booking,
            'flash'      => $this->session->flashdata('flash'),
        ];
        $this->load->view('admin/_layout_header', $data);
        $this->load->view('admin/client_booking_detail', $data);
        $this->load->view('admin/_layout_footer', $data);
    }

    // ----------------- helpers -----------------

    protected function _require_login()
    {
        if (!$this->session->userdata('logged_in')) {
            $this->session->set_flashdata('flash', [
                'type'    => 'info',
                'message' => 'Please log in to continue.',
            ]);
            redirect('admin/login');
        }
    }

    protected function _current_admin()
    {
        $id = $this->session->userdata('admin_id');
        if (!$id) return NULL;
        return $this->Admin_model->get_by_id($id);
    }
}