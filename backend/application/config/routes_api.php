<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API — REST routes
 *
 * Drop these into your application/config/routes.php (or merge if you
 * already have a routes file).
 */

$route['api/health']                 = 'api/health';
$route['api/fleet']                  = 'api/fleet';
$route['api/settings']               = 'api/settings';
$route['api/pricing/quote']          = 'api/pricing_quote';
$route['api/surcharges']             = 'api/surcharges';
$route['api/addons']                 = 'api/addons';
$route['api/flights/validate']       = 'api/flights_validate';
$route['api/reservation']            = 'api/reservation_create';
// More specific routes must come before the (:any) wildcard below —
// CI3 matches routes in declaration order, so reservation/sign would
// otherwise be swallowed by reservation/(:any) and never reached.
$route['api/reservation/sign']       = 'api/reservation_sign';
$route['api/reservation/(:any)/update'] = 'api/reservation_update/$1';
$route['api/reservation/(:any)']     = 'api/reservation_get/$1';
$route['api/customers/register']        = 'api/customer_register';
$route['api/customers/verify-email']    = 'api/customer_verify_email';
$route['api/customers/resend-otp']      = 'api/customer_resend_otp';
$route['api/customers/login']           = 'api/customer_login';
$route['api/customers/logout']          = 'api/customer_logout';
$route['api/customers/me']              = 'api/customer_me';
$route['api/customers/reservations']    = 'api/customer_reservations';
$route['api/customers/forgot-password'] = 'api/customer_forgot_password';
$route['api/customers/reset-password']  = 'api/customer_reset_password';
$route['api/customers/update']          = 'api/customer_update_profile';
$route['api/customers/change-password'] = 'api/customer_change_password';
// Saved cards — more specific routes before the plain /cards route.
// GET (list) and POST (add) share one URL. NOT verb-routed via CI3's
// $route[...]['get']/['post'] arrays — an OPTIONS preflight matches
// neither key, so it 404s before the controller's CORS handling ever
// runs, and the browser blocks the real request. Every other route in
// this file matches ANY verb so the controller constructor's CORS
// headers apply to the preflight too; customer_cards() below follows
// that same pattern and dispatches on $this->input->method() itself.
$route['api/customers/cards/(:any)/default'] = 'api/customer_cards_set_default/$1';
$route['api/customers/cards/(:any)/delete']  = 'api/customer_cards_delete/$1';
$route['api/customers/cards']                = 'api/customer_cards';
$route['api/paypal/create-order']    = 'api/paypal_create_order';
$route['api/paypal/return']          = 'api/paypal_return';
$route['api/paypal/cancel']          = 'api/paypal_cancel';
$route['api/promo/validate']         = 'api/promo_validate';
