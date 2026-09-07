<?php
/**
 * Seed vehicles into kfb_vehicles via the same DB connection the
 * backend uses. Idempotent: skips vehicles whose `code` already
 * exists. Safe to re-run.
 *
 * Run:  php seed_vehicles.php
 *       (from inside backend/)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(2); }

$backend_dir = getenv('BACKEND_DIR') ?: __DIR__;

// Bootstrap CodeIgniter so we get the configured $db connection.
define('BASEPATH',    $backend_dir . DIRECTORY_SEPARATOR . 'system'    . DIRECTORY_SEPARATOR);
define('APPPATH',     $backend_dir . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
define('VIEWPATH',    APPPATH . 'views' . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', getenv('CI_ENV') ?: 'development');
require BASEPATH . 'core/CodeIgniter.php';

/* @var CI_Controller $CI */
$CI =& get_instance();
$CI->load->model('Vehicle_model');
$CI->load->database();

// ---- Default fleet ----
// Each row mirrors what Booking_model::get_fleet() returns — a single
// local $/mile rate, $/hour rate, and point-to-point minimum (v16).
// Regional/long-distance/worldwide prices are derived from these via the
// global multipliers in Pricing_engine, not stored per vehicle.
$vehicles = [
    [
        'code'    => 'sedan',
        'name'    => 'Luxury Sedan',
        'desc'    => 'Mercedes E-Class / BMW 5 Series — ideal for 1–3 passengers.',
        'emoji'   => '🚘',
        'image'   => NULL,
        'sort_order' => 1,
        'min_passengers' => 1,
        'max_passengers' => 3,
        'luggage_capacity' => 3,
        'local_per_mile_rate' => 4.02,
        'local_hourly_rate'   => 75.00,
        'local_min_fare'      => 119.00,
    ],
    [
        'code'    => 'suv',
        'name'    => 'Premium SUV',
        'desc'    => 'Cadillac Escalade / Chevy Suburban — roomy, elegant, 6 passengers.',
        'emoji'   => '🚙',
        'image'   => NULL,
        'sort_order' => 2,
        'min_passengers' => 1,
        'max_passengers' => 6,
        'luggage_capacity' => 6,
        'local_per_mile_rate' => 5.63,
        'local_hourly_rate'   => 95.00,
        'local_min_fare'      => 139.00,
    ],
    [
        'code'    => 'sprinter',
        'name'    => 'Luxury Sprinter',
        'desc'    => 'Executive van — perfect for groups up to 12 passengers.',
        'emoji'   => '🚐',
        'image'   => NULL,
        'sort_order' => 3,
        'min_passengers' => 4,
        'max_passengers' => 12,
        'luggage_capacity' => 10,
        'local_per_mile_rate' => 6.76,
        'local_hourly_rate'   => 130.00,
        'local_min_fare'      => 159.00,
    ],
    [
        'code'    => 'limo',
        'name'    => 'Stretch Limousine',
        'desc'    => 'Lincoln Stretch — weddings, proms, VIP nights out.',
        'emoji'   => '🏁',
        'image'   => NULL,
        'sort_order' => 4,
        'min_passengers' => 4,
        'max_passengers' => 10,
        'luggage_capacity' => 6,
        'local_per_mile_rate' => 8.05,
        'local_hourly_rate'   => 175.00,
        'local_min_fare'      => 199.00,
    ],
];

$inserted = 0;
$skipped  = 0;
$failed   = 0;

foreach ($vehicles as $v) {
    // Skip if `code` already exists — keeps the script idempotent.
    $existing = $v['code']
        ? $CI->db->get_where('kfb_vehicles', ['code' => $v['code']])->row_array()
        : NULL;
    if ($existing) {
        fwrite(STDOUT, "  · " . $v['code'] . " (" . $v['name'] . ") — already exists, skipped\n");
        $skipped++;
        continue;
    }

    $payload = $v + ['status' => 1];
    $errors = $CI->Vehicle_model->validate($payload);
    if (!empty($errors)) {
        fwrite(STDERR, "  ! " . $v['code'] . " validation failed: " . json_encode($errors) . "\n");
        $failed++;
        continue;
    }

    $id = $CI->Vehicle_model->create($payload);
    if ($id) {
        fwrite(STDOUT, "  ✓ " . $v['code'] . " (" . $v['name'] . ") inserted — id=$id\n");
        $inserted++;
    } else {
        fwrite(STDERR, "  ! " . $v['code'] . " insert failed: " . json_encode($CI->db->error()) . "\n");
        $failed++;
    }
}

fwrite(STDOUT, "\nDone. inserted=$inserted, skipped=$skipped, failed=$failed\n");
exit($failed > 0 ? 1 : 0);