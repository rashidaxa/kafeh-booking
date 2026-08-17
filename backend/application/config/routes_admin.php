<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API Admin Portal — routes
 *
 * Convention (per CI3 — routes don't see the HTTP method, so we use
 * distinct URL paths to separate GET vs POST):
 *
 *   GET  /path           → handler_get
 *   POST /path/save      → do_handler    (HTML form submit)
 *
 *   GET  /api/path              → handler
 *   POST /api/path/save         → handler_create  (form-driven API)
 *   POST /api/path/:id/save     → handler_update  (form-driven API)
 *   POST /api/path/:id/<action> → handler_<action>  (semantic actions)
 *
 * ---- Auth ----
 *   GET  /admin/login           → Auth::login_get        (renders form)
 *   POST /admin/login/save      → Auth::do_login         (form / JSON submit)
 *   GET  /admin/logout          → Auth::logout_get
 *   GET  /admin/setup           → Auth::setup_get        (renders first-admin form)
 *   POST /admin/setup/save      → Auth::do_setup
 *
 * ---- Pages ----
 *   GET  /admin                 → Admin::index
 *   GET  /admin/vehicles        → Admin::vehicles
 *   GET  /admin/vehicles/new    → Admin::vehicle_new
 *   GET  /admin/vehicles/:id    → Admin::vehicle_edit/:id
 *
 * ---- JSON API ----
 *   GET  /admin/api/me                       → Admin_api::me
 *   GET  /admin/api/vehicles                 → Admin_api::vehicles_index
 *   POST /admin/api/vehicles/save            → Admin_api::vehicles_create
 *   POST /admin/api/vehicles/:id/save        → Admin_api::vehicles_update/:id
 *   POST /admin/api/vehicles/:id/delete      → Admin_api::vehicles_delete/:id
 *   POST /admin/api/vehicles/:id/toggle      → Admin_api::vehicles_toggle/:id
 *   GET  /admin/api/reservations             → Admin_api::reservations_index
 *   GET  /admin/api/reservations/:id         → Admin_api::reservations_get/:id
 *   POST /admin/api/reservations/:id/accept  → Admin_api::reservations_accept/:id
 *   POST /admin/api/reservations/:id/reject  → Admin_api::reservations_reject/:id
 *   POST /admin/api/reservations/:id/charge  → Admin_api::reservations_charge/:id
 */

// ---- Auth ----
$route['admin/login']                          = 'auth/login_get';
$route['admin/login/save']                     = 'auth/do_login';
$route['admin/logout']                         = 'auth/logout_get';
$route['admin/setup']                          = 'auth/setup_get';
$route['admin/setup/save']                     = 'auth/do_setup';

// ---- Pages ----
$route['admin']                                = 'admin/index';
$route['admin/vehicles']                       = 'admin/vehicles';
$route['admin/vehicles/new']                   = 'admin/vehicle_new';
$route['admin/vehicles/(:num)']                = 'admin/vehicle_edit/$1';
$route['admin/promos']                         = 'admin/promos';
$route['admin/promos/new']                     = 'admin/promo_new';
$route['admin/promos/(:num)']                  = 'admin/promo_edit/$1';
$route['admin/addons']                         = 'admin/addons';
$route['admin/addons/new']                     = 'admin/addon_new';
$route['admin/addons/(:num)']                  = 'admin/addon_edit/$1';
$route['admin/settings']                       = 'admin/settings';
$route['admin/reservations']                   = 'admin/reservations';
// booking_id is not numeric (e.g. KFB-AB12CD), so this uses (:any) — it
// must stay the LAST reservations route since :any is greedy.
$route['admin/reservations/(:any)']            = 'admin/reservation_detail/$1';

// ---- JSON API ----
$route['admin/api/me']                         = 'admin_api/me';
$route['admin/api/vehicles']                   = 'admin_api/vehicles_index';
$route['admin/api/vehicles/save']              = 'admin_api/vehicles_create';
$route['admin/api/vehicles/(:num)/save']       = 'admin_api/vehicles_update/$1';
$route['admin/api/vehicles/(:num)/delete']     = 'admin_api/vehicles_delete/$1';
$route['admin/api/vehicles/(:num)/toggle']     = 'admin_api/vehicles_toggle/$1';
$route['admin/api/promos']                     = 'admin_api/promos_index';
$route['admin/api/promos/save']                = 'admin_api/promos_create';
$route['admin/api/promos/(:num)/save']         = 'admin_api/promos_update/$1';
$route['admin/api/promos/(:num)/delete']       = 'admin_api/promos_delete/$1';
$route['admin/api/promos/(:num)/toggle']       = 'admin_api/promos_toggle/$1';
$route['admin/api/addons']                     = 'admin_api/addons_index';
$route['admin/api/addons/save']                = 'admin_api/addons_create';
$route['admin/api/addons/(:num)/save']         = 'admin_api/addons_update/$1';
$route['admin/api/addons/(:num)/delete']       = 'admin_api/addons_delete/$1';
$route['admin/api/addons/(:num)/toggle']       = 'admin_api/addons_toggle/$1';
$route['admin/api/settings']                   = 'admin_api/settings_index';
$route['admin/api/settings/save']              = 'admin_api/settings_update';
$route['admin/api/reservations']               = 'admin_api/reservations_index';
// Same (:any) ordering caveat as above — suffixed action routes must come
// before the bare get-by-id route.
$route['admin/api/reservations/(:any)/accept'] = 'admin_api/reservations_accept/$1';
$route['admin/api/reservations/(:any)/reject'] = 'admin_api/reservations_reject/$1';
$route['admin/api/reservations/(:any)/charge'] = 'admin_api/reservations_charge/$1';
$route['admin/api/reservations/(:any)']        = 'admin_api/reservations_get/$1';