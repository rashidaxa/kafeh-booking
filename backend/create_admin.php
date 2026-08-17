#!/usr/bin/env php
<?php
/**
 * Booking API Admin Portal — CLI admin seeder
 *
 * Usage:
 *   php create_admin.php <username> <password> [display_name] [email]
 *
 * Example:
 *   php create_admin.php admin 'Sup3rSecret!' "Site Admin" admin@example.com
 *
 * If an admin with that username already exists, the script exits
 * with code 1 and prints a message.
 *
 * Run from inside the `backend/` directory (or pass the path via
 * the BACKEND_DIR environment variable).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(2);
}

if ($_SERVER['argc'] < 3) {
    fwrite(STDERR, "Usage: php create_admin.php <username> <password> [display_name] [email]\n");
    exit(2);
}

$backend_dir = getenv('BACKEND_DIR') ?: __DIR__;

// --- CodeIgniter 3 bootstrap (CLI mode) ----------------------------
define('BASEPATH',  $backend_dir . DIRECTORY_SEPARATOR . 'system'  . DIRECTORY_SEPARATOR);
define('APPPATH',   $backend_dir . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
define('VIEWPATH',  APPPATH . 'views' . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', getenv('CI_ENV') ?: 'development');

// CodeIgniter.php defines a bunch of constants and pulls in core.
// We then boot the framework "the long way" via the system/core files.
require BASEPATH . 'core/CodeIgniter.php';

/* @var CI_Controller $CI */
$CI =& get_instance();
$CI->load->model('Admin_model');

$username     = $_SERVER['argv'][1];
$password     = $_SERVER['argv'][2];
$display_name = $_SERVER['argv'][3] ?? NULL;
$email        = $_SERVER['argv'][4] ?? NULL;

$existing = $CI->Admin_model->find_by_username($username);
if ($existing) {
    fwrite(STDOUT, "Admin '$username' already exists (id={$existing['id']}).\n");
    exit(1);
}

$id = $CI->Admin_model->create_admin($username, $password, $display_name, $email);
if ($id) {
    fwrite(STDOUT, "✓ Created admin '$username' (id=$id).\n");
    fwrite(STDOUT, "  Sign in at: " . base_url('admin/login') . "\n");
    exit(0);
}

fwrite(STDERR, "✗ Failed to create admin (DB error).\n");
exit(1);