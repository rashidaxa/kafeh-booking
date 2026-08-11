<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Booking — REST routes
 *
 * Drop these into your application/config/routes.php (or merge if you
 * already have a routes file).
 */

$route['api/health']                 = 'api/health';
$route['api/fleet']                  = 'api/fleet';
$route['api/addons']                 = 'api/addons';
$route['api/flights/validate']       = 'api/flights_validate';
$route['api/reservation']            = 'api/reservation_create';
$route['api/reservation/(:any)']     = 'api/reservation_get/$1';
$route['api/reservation/sign']       = 'api/reservation_sign';
$route['api/customers/register']     = 'api/customer_register';
$route['api/paypal/create-order']    = 'api/paypal_create_order';
$route['api/paypal/capture-order/(:any)'] = 'api/paypal_capture_order/$1';
$route['api/promo/validate']         = 'api/promo_validate';
