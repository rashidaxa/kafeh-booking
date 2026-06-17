<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Admin Portal — page rendering
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
        $this->load->model(['Admin_model', 'Vehicle_model']);
        $this->load->library('session');
        $this->load->helper(['url', 'form']);
        $this->_require_login();
    }

    /** GET /admin — dashboard */
    public function index()
    {
        $vehicles = $this->Vehicle_model->list_all();
        $enabled  = array_filter($vehicles, function ($v) { return (int)$v['status'] === 1; });
        $data = [
            'page_title' => 'Dashboard',
            'admin'      => $this->_current_admin(),
            'stats'      => [
                'total_vehicles'    => count($vehicles),
                'enabled_vehicles'  => count($enabled),
                'disabled_vehicles' => count($vehicles) - count($enabled),
            ],
            'recent_vehicles' => array_slice($vehicles, 0, 5),
            'flash'          => $this->session->flashdata('flash'),
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