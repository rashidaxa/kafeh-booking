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
$route['api/customers/login']           = 'api/customer_login';
$route['api/customers/logout']          = 'api/customer_logout';
$route['api/customers/me']              = 'api/customer_me';
$route['api/customers/reservations']    = 'api/customer_reservations';
$route['api/customers/forgot-password'] = 'api/customer_forgot_password';
$route['api/customers/reset-password']  = 'api/customer_reset_password';
$route['api/customers/update']          = 'api/customer_update_profile';
$route['api/customers/change-password'] = 'api/customer_change_password';
$route['api/paypal/create-order']    = 'api/paypal_create_order';
$route['api/paypal/return']          = 'api/paypal_return';
$route['api/paypal/cancel']          = 'api/paypal_cancel';
$route['api/promo/validate']         = 'api/promo_validate';
