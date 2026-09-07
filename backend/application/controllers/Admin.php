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

class Admin extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model(['Admin_model', 'Vehicle_model', 'Promo_model', 'Addon_model', 'Surcharge_model', 'Settings_model', 'Booking_model']);
        $this->load->library('session');
        $this->load->helper(['url', 'form']);
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
            'recent_vehicles'      => array_slice($vehicles, 0, 5),
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
        $filters = [];
        if ($status !== '') $filters['status'] = $status;
        if ($search !== '') $filters['search'] = $search;

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