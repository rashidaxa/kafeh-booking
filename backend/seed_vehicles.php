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
// Each row mirrors what the Booking_model::get_fleet() static list used
// to return, plus region-scoped rates so the widget's priceBreakdown()
// produces non-zero prices for any region.
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

        // Hourly rates
        'hourly_chicago'   => 75.00,
        'hourly_america'   => 95.00,
        'hourly_worldwide' => 120.00,

        // Per-km rates
        'per_km_chicago'   => 2.50,
        'per_km_america'   => 3.20,
        'per_km_worldwide' => 4.00,

        // Surcharges (currency units)
        'surcharge_chicago'   => 5.00,
        'surcharge_america'   => 8.00,
        'surcharge_worldwide' => 12.00,

        // Gratuity
        'gratuity_chicago'   => 15.00,
        'gratuity_america'   => 18.00,
        'gratuity_worldwide' => 25.00,

        // Waiting per minute
        'waiting_chicago'   => 0.75,
        'waiting_america'   => 1.00,
        'waiting_worldwide' => 1.50,

        // Child-seat surcharge (flat fee per child seat)
        'child_seat_chicago'   => 10.00,
        'child_seat_america'   => 12.00,
        'child_seat_worldwide' => 15.00,
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

        'hourly_chicago'   => 95.00,
        'hourly_america'   => 120.00,
        'hourly_worldwide' => 150.00,

        'per_km_chicago'   => 3.50,
        'per_km_america'   => 4.20,
        'per_km_worldwide' => 5.20,

        'surcharge_chicago'   => 7.00,
        'surcharge_america'   => 10.00,
        'surcharge_worldwide' => 15.00,

        'gratuity_chicago'   => 18.00,
        'gratuity_america'   => 22.00,
        'gratuity_worldwide' => 30.00,

        'waiting_chicago'   => 1.00,
        'waiting_america'   => 1.25,
        'waiting_worldwide' => 1.75,

        'child_seat_chicago'   => 10.00,
        'child_seat_america'   => 12.00,
        'child_seat_worldwide' => 15.00,
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

        'hourly_chicago'   => 130.00,
        'hourly_america'   => 165.00,
        'hourly_worldwide' => 210.00,

        'per_km_chicago'   => 4.20,
        'per_km_america'   => 5.10,
        'per_km_worldwide' => 6.40,

        'surcharge_chicago'   => 9.00,
        'surcharge_america'   => 13.00,
        'surcharge_worldwide' => 18.00,

        'gratuity_chicago'   => 22.00,
        'gratuity_america'   => 28.00,
        'gratuity_worldwide' => 38.00,

        'waiting_chicago'   => 1.25,
        'waiting_america'   => 1.50,
        'waiting_worldwide' => 2.00,

        'child_seat_chicago'   => 10.00,
        'child_seat_america'   => 12.00,
        'child_seat_worldwide' => 15.00,
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

        'hourly_chicago'   => 175.00,
        'hourly_america'   => 220.00,
        'hourly_worldwide' => 280.00,

        'per_km_chicago'   => 5.00,
        'per_km_america'   => 6.00,
        'per_km_worldwide' => 7.50,

        'surcharge_chicago'   => 12.00,
        'surcharge_america'   => 18.00,
        'surcharge_worldwide' => 25.00,

        'gratuity_chicago'   => 28.00,
        'gratuity_america'   => 35.00,
        'gratuity_worldwide' => 45.00,

        'waiting_chicago'   => 1.50,
        'waiting_america'   => 1.75,
        'waiting_worldwide' => 2.50,

        'child_seat_chicago'   => 10.00,
        'child_seat_america'   => 12.00,
        'child_seat_worldwide' => 15.00,
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