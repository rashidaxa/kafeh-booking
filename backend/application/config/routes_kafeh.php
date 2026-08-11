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
$route['api/settings']               = 'api/settings';
$route['api/addons']                 = 'api/addons';
$route['api/flights/validate']       = 'api/flights_validate';
$route['api/reservation']            = 'api/reservation_create';
// More specific routes must come before the (:any) wildcard below —
// CI3 matches routes in declaration order, so reservation/sign would
// otherwise be swallowed by reservation/(:any) and never reached.
$route['api/reservation/sign']       = 'api/reservation_sign';
$route['api/reservation/(:any)']     = 'api/reservation_get/$1';
$route['api/customers/register']     = 'api/customer_register';
$route['api/stripe/create-intent']   = 'api/stripe_create_intent';
$route['api/stripe/finalize']        = 'api/stripe_finalize';
$route['api/promo/validate']         = 'api/promo_validate';
