<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API — top-level configuration
 */

$config['send_confirmation_email'] = TRUE;
$config['allowed_origins']         = ['*'];  // tighten in production

/*
| Legacy reservations management portal (localhost/bookingOld) sync.
| Whenever an admin approves a reservation, Legacy_reservations::sync()
| pushes it into that system's `reservations` table via a server-to-
| server POST, authenticated with the shared secret below — must match
| bookingOld's application/config/sync.php exactly.
*/
$config['legacy_sync_enabled']  = TRUE;
$config['legacy_sync_base_url'] = 'http://localhost/bookingOld/index.php';
$config['legacy_sync_api_key']  = '47fd1fe4b1b2c212241fc627f01d9fc9c62f93a6a2a7810726f572ab4455ba9e';
