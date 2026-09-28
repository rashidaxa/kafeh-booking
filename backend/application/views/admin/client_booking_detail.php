<?php
$booking = isset($booking) ? $booking : [];

if (!function_exists('kfb_hm')) {
    /** HH:MM from a TIME/DATETIME column, or '—'. */
    function kfb_hm($t)
    {
        return $t ? substr((string)$t, 0, 5) : '—';
    }
}
if (!function_exists('kfb_or_dash')) {
    function kfb_or_dash($v)
    {
        return ($v === NULL || $v === '') ? '—' : htmlspecialchars((string)$v);
    }
}
if (!function_exists('kfb_leg_trip_html')) {
    /** Same pickup → (stops) → drop-off renderer used by reservation_detail.php — column names match. */
    function kfb_leg_trip_html(array $leg, array $stops = [])
    {
        ob_start();
        ?>
        <div class="kfb-detail-grid">
          <div><span class="kfb-hint">Pickup type</span><p><?= kfb_or_dash($leg['pickup_loc_type'] ?? NULL) ?></p></div>
          <div class="kfb-field--full"><span class="kfb-hint">Pickup address</span><p><?= kfb_or_dash($leg['pickup'] ?? NULL) ?></p></div>
          <?php if ((($leg['pickup_loc_type'] ?? '') === 'Airport')): ?>
            <div><span class="kfb-hint">Airline</span><p><?= kfb_or_dash($leg['airline'] ?? NULL) ?></p></div>
            <div><span class="kfb-hint">Flight #</span><p><?= kfb_or_dash($leg['flight_number'] ?? NULL) ?></p></div>
            <div><span class="kfb-hint">Arrival time</span><p><?= htmlspecialchars(kfb_hm($leg['arrival_time'] ?? NULL)) ?></p></div>
            <div><span class="kfb-hint">Meet type</span><p><?= kfb_or_dash($leg['pickup_type_detail'] ?? NULL) ?></p></div>
            <?php if (($leg['pickup_type_detail'] ?? '') === 'Private Terminal (FBO)'): ?>
              <div><span class="kfb-hint">Tail number</span><p><?= kfb_or_dash($leg['tail_number'] ?? NULL) ?></p></div>
            <?php endif; ?>
            <?php if (!empty($leg['pickup_point'])): ?>
              <div><span class="kfb-hint">Pickup point</span><p><?= kfb_or_dash($leg['pickup_point']) ?></p></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if (!empty($stops)): ?>
          <div style="margin-top:14px">
            <span class="kfb-hint">Stops</span>
            <ol style="margin:6px 0 0; padding-left:20px">
              <?php foreach ($stops as $s): ?><li><?= kfb_or_dash($s['address'] ?? NULL) ?></li><?php endforeach; ?>
            </ol>
          </div>
        <?php endif; ?>

        <div class="kfb-detail-grid" style="margin-top:14px">
          <div><span class="kfb-hint">Drop-off type</span><p><?= kfb_or_dash($leg['dropoff_loc_type'] ?? NULL) ?></p></div>
          <div class="kfb-field--full"><span class="kfb-hint">Drop-off address</span><p><?= kfb_or_dash($leg['dropoff'] ?? NULL) ?></p></div>
          <?php if (($leg['dropoff_loc_type'] ?? '') === 'Airport'): ?>
            <div><span class="kfb-hint">Airline</span><p><?= kfb_or_dash($leg['dropoff_airline'] ?? NULL) ?></p></div>
            <div><span class="kfb-hint">Flight #</span><p><?= kfb_or_dash($leg['dropoff_flight_number'] ?? NULL) ?></p></div>
            <div><span class="kfb-hint">Arrival time</span><p><?= htmlspecialchars(kfb_hm($leg['dropoff_arrival_time'] ?? NULL)) ?></p></div>
            <div><span class="kfb-hint">Meet type</span><p><?= kfb_or_dash($leg['dropoff_type_detail'] ?? NULL) ?></p></div>
            <?php if (($leg['dropoff_type_detail'] ?? '') === 'Private Terminal (FBO)'): ?>
              <div><span class="kfb-hint">Tail number</span><p><?= kfb_or_dash($leg['dropoff_tail_number'] ?? NULL) ?></p></div>
            <?php endif; ?>
            <?php if (!empty($leg['dropoff_pickup_point'])): ?>
              <div><span class="kfb-hint">Drop-off point</span><p><?= kfb_or_dash($leg['dropoff_pickup_point']) ?></p></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

$kfbChildSeatLabels = [
    'rear_facing'    => 'Rear-Facing Seat (Infant)',
    'forward_facing' => 'Forward-Facing Seat (Toddler)',
    'booster'        => 'Booster Seat',
];

$childSeatsBreakdown = [];
if (!empty($booking['child_seats_breakdown'])) {
    $decoded = json_decode($booking['child_seats_breakdown'], TRUE);
    if (is_array($decoded)) $childSeatsBreakdown = $decoded;
}

$hasBillingInfo = !empty($booking['cardHolderName']) || !empty($booking['cardNumber']) || !empty($booking['cardExpiry']) || !empty($booking['cvv']) || !empty($booking['cardBillingAddress']);
?>

<a href="<?= site_url('admin/client-bookings') ?>" class="kfb-link-back">← All client bookings</a>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>
      <code class="kfb-mono"><?= htmlspecialchars($booking['booking_id']) ?></code>
      <span class="kfb-badge kfb-badge--off">Client submitted</span>
    </h2>
    <div class="kfb-list-actions">
      <button type="button" class="kfb-btn kfb-btn--danger kfb-btn--sm" id="kfbDeleteReservationBtn"
              data-endpoint="<?= site_url('admin/api/client-bookings/' . $booking['booking_id'] . '/delete') ?>"
              data-redirect="<?= site_url('admin/client-bookings') ?>">
        Delete
      </button>
    </div>
  </header>

  <div class="kfb-detail-grid">
    <div>
      <span class="kfb-hint">Customer</span>
      <p><?= htmlspecialchars(trim($booking['first_name'] . ' ' . $booking['last_name'])) ?><br>
        <?= htmlspecialchars($booking['email']) ?><br>
        <?= htmlspecialchars($booking['phone']) ?></p>
    </div>
    <div>
      <span class="kfb-hint">Trip</span>
      <p>
        <?= htmlspecialchars($booking['service_type']) ?><br>
        <?= htmlspecialchars($booking['pickup_date']) ?> at <?= kfb_hm($booking['pickup_time'] ?? NULL) ?><br>
        <?= htmlspecialchars($booking['pickup']) ?> → <?= htmlspecialchars($booking['dropoff']) ?>
      </p>
    </div>
    <div>
      <span class="kfb-hint">Vehicle &amp; party</span>
      <p>
        <?= kfb_or_dash($booking['vehicle_name'] ?? NULL) ?><br>
        <?= (int)$booking['passengers'] ?> passengers · <?= (int)$booking['luggage'] ?> bags
        <?= (int)$booking['child_seats'] > 0 ? ' · ' . (int)$booking['child_seats'] . ' child seat(s)' : '' ?>
      </p>
    </div>
    <?php if (!empty($booking['hours_requested'])): ?>
      <div>
        <span class="kfb-hint">Hours requested</span>
        <p><?= number_format((float)$booking['hours_requested'], 1) ?> h</p>
      </div>
    <?php endif; ?>
    <?php if (!empty($booking['is_return_trip'])): ?>
      <div>
        <span class="kfb-hint">Return trip</span>
        <p><?= kfb_or_dash($booking['return_date'] ?? NULL) ?> at <?= kfb_hm($booking['return_time'] ?? NULL) ?></p>
      </div>
    <?php endif; ?>
    <div>
      <span class="kfb-hint">Submitted</span>
      <p><?= htmlspecialchars($booking['created_at']) ?><br>
        <small class="kfb-hint">IP <?= kfb_or_dash($booking['ip_address'] ?? NULL) ?></small></p>
    </div>
  </div>
</section>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>Pickup &amp; drop-off</h2></header>
  <?= kfb_leg_trip_html($booking, $booking['stops'] ?? []) ?>
</section>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>Passengers, luggage &amp; child seats</h2></header>
  <div class="kfb-detail-grid">
    <div><span class="kfb-hint">Passengers</span><p><?= (int)$booking['passengers'] ?></p></div>
    <div><span class="kfb-hint">Luggage</span><p><?= (int)$booking['luggage'] ?> bag(s)</p></div>
    <div>
      <span class="kfb-hint">Child seats</span>
      <p>
        <?php if ((int)$booking['child_seats'] > 0): ?>
          <?= (int)$booking['child_seats'] ?> total
          <?php if (!empty($childSeatsBreakdown)): ?>
            <br><small class="kfb-hint">
              <?php
              $parts = [];
              foreach ($childSeatsBreakdown as $key => $qty) {
                  $label = $kfbChildSeatLabels[$key] ?? ucfirst(str_replace('_', ' ', $key));
                  $parts[] = htmlspecialchars($label) . ' × ' . (int)$qty;
              }
              echo implode(', ', $parts);
              ?>
            </small>
          <?php endif; ?>
        <?php else: ?>
          None
        <?php endif; ?>
      </p>
    </div>
  </div>
</section>

<?php if ($hasBillingInfo): ?>
  <section class="kfb-card">
    <header class="kfb-card-head"><h2>Credit Card Information</h2></header>
    <p class="kfb-hint" style="margin: -4px 0 12px;">Kept on file only — nothing was charged from this submission.</p>
    <div class="kfb-detail-grid">
      <div><span class="kfb-hint">Card holder name</span><p><?= kfb_or_dash($booking['cardHolderName'] ?? NULL) ?></p></div>
      <div><span class="kfb-hint">Card number</span><p><?= kfb_or_dash($booking['cardNumber'] ?? NULL) ?></p></div>
      <div><span class="kfb-hint">Card expiry</span><p><?= kfb_or_dash($booking['cardExpiry'] ?? NULL) ?></p></div>
      <div><span class="kfb-hint">CVV</span><p><?= kfb_or_dash($booking['cvv'] ?? NULL) ?></p></div>
      <div class="kfb-field--full"><span class="kfb-hint">Card billing address</span><p><?= kfb_or_dash($booking['cardBillingAddress'] ?? NULL) ?></p></div>
    </div>
  </section>
<?php endif; ?>

<?php if (!empty($booking['addons'])): ?>
  <section class="kfb-card">
    <header class="kfb-card-head"><h2>Add-on services requested</h2></header>
    <div class="kfb-table-scroll">
      <table class="kfb-table">
        <thead><tr><th>Add-on</th><th>Qty</th></tr></thead>
        <tbody>
          <?php foreach ($booking['addons'] as $a): ?>
            <tr>
              <td><?= kfb_or_dash($a['addon_name'] ?? NULL) ?></td>
              <td><?= (int)($a['quantity'] ?? 1) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="kfb-hint" style="margin-top:8px">No pricing shown — this trip was already quoted by phone.</p>
  </section>
<?php endif; ?>

<?php if (!empty($booking['notes'])): ?>
  <section class="kfb-card">
    <header class="kfb-card-head"><h2>Special instructions</h2></header>
    <p><?= nl2br(htmlspecialchars($booking['notes'])) ?></p>
  </section>
<?php endif; ?>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>E-signature</h2></header>
  <?php if (!empty($booking['signature_data'])): ?>
    <div class="kfb-detail-grid" style="margin-bottom:12px">
      <div><span class="kfb-hint">Signed</span><p><?= kfb_or_dash($booking['signature_at'] ?? NULL) ?></p></div>
      <div><span class="kfb-hint">IP address</span><p><?= kfb_or_dash($booking['signature_ip'] ?? NULL) ?></p></div>
      <div><span class="kfb-hint">Terms version</span><p><?= kfb_or_dash($booking['terms_version'] ?? NULL) ?></p></div>
    </div>
    <img src="<?= htmlspecialchars($booking['signature_data']) ?>" alt="Customer signature"
         style="max-width:100%; width:340px; border:1px solid var(--kfb-border-strong); border-radius:6px; background:#fff">
  <?php else: ?>
    <p class="kfb-empty">No signature on file.</p>
  <?php endif; ?>
</section>
