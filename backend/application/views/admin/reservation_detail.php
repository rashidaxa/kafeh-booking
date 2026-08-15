<?php
$booking = isset($booking) ? $booking : [];
if (!function_exists('kfb_reservation_badge')) {
    function kfb_reservation_badge($status)
    {
        switch ($status) {
            case 'paid':               return ['ok', 'Paid'];
            case 'awaiting_approval':  return ['warn', 'Awaiting approval'];
            case 'cancelled':          return ['danger', 'Cancelled'];
            case 'payment_failed':     return ['danger', 'Payment failed'];
            default:                   return ['off', ucfirst(str_replace('_', ' ', $status))];
        }
    }
}
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
    /**
     * Renders the full pickup → (stops) → drop-off detail for one leg.
     * Works for both the outbound booking row and a return-leg row — both
     * are full kfb_bookings rows with identical column names.
     */
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

[$badge, $badgeLabel] = kfb_reservation_badge($booking['status'] ?? '');
$hasCard        = !empty($booking['card_brand']) && !empty($booking['card_last4']);
$awaitingReview = ($booking['status'] ?? '') === 'awaiting_approval';
$isPaid         = ($booking['status'] ?? '') === 'paid';

$childSeatsBreakdown = [];
if (!empty($booking['child_seats_breakdown'])) {
    $decoded = json_decode($booking['child_seats_breakdown'], TRUE);
    if (is_array($decoded)) $childSeatsBreakdown = $decoded;
}

$addons = [];
if (!empty($booking['addons_json'])) {
    $decoded = json_decode($booking['addons_json'], TRUE);
    if (is_array($decoded)) $addons = $decoded;
}
?>

<a href="<?= site_url('admin/reservations') ?>" class="kfb-link-back">← All reservations</a>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>
      <code class="kfb-mono"><?= htmlspecialchars($booking['booking_id']) ?></code>
      <span class="kfb-badge kfb-badge--<?= $badge ?>"><?= htmlspecialchars($badgeLabel) ?></span>
    </h2>
    <?php if ($awaitingReview): ?>
      <div class="kfb-list-actions">
        <button type="button" class="kfb-btn kfb-btn--primary kfb-btn--sm" id="kfbAcceptBtn"
                data-endpoint="<?= site_url('admin/api/reservations/' . $booking['booking_id'] . '/accept') ?>">
          Accept — capture $<?= number_format((float)$booking['amount'], 2) ?>
        </button>
        <button type="button" class="kfb-btn kfb-btn--danger kfb-btn--sm" id="kfbRejectBtn"
                data-endpoint="<?= site_url('admin/api/reservations/' . $booking['booking_id'] . '/reject') ?>">
          Reject — release hold
        </button>
      </div>
    <?php endif; ?>
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
        <?= htmlspecialchars($booking['vehicle_name']) ?><br>
        <?= (int)$booking['passengers'] ?> passengers · <?= (int)$booking['luggage'] ?> bags
        <?= (int)$booking['child_seats'] > 0 ? ' · ' . (int)$booking['child_seats'] . ' child seat(s)' : '' ?>
      </p>
    </div>
    <div>
      <span class="kfb-hint">Amount</span>
      <p><strong>$<?= number_format((float)$booking['amount'], 2) ?></strong>
        <?php if ((float)$booking['discount_amount'] > 0): ?>
          <br><small class="kfb-hint">Promo <?= htmlspecialchars($booking['promo_code']) ?>: −$<?= number_format((float)$booking['discount_amount'], 2) ?></small>
        <?php endif; ?>
      </p>
    </div>
    <div>
      <span class="kfb-hint">Saved card</span>
      <p>
        <?php if ($hasCard): ?>
          <?= htmlspecialchars(ucfirst($booking['card_brand'])) ?> •••• <?= htmlspecialchars($booking['card_last4']) ?>
        <?php else: ?>
          <span class="kfb-hint">Paid via PayPal — no card details collected</span>
        <?php endif; ?>
      </p>
    </div>
    <?php if (!empty($booking['approved_at'])): ?>
      <div>
        <span class="kfb-hint">Decision</span>
        <p><?= htmlspecialchars($booking['approved_at']) ?> by <?= htmlspecialchars($booking['approved_by'] ?: '—') ?></p>
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
  <header class="kfb-card-head"><h2>Pickup &amp; drop-off — outbound</h2></header>
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

<?php if (!empty($addons)): ?>
  <section class="kfb-card">
    <header class="kfb-card-head"><h2>Add-on services</h2></header>
    <div class="kfb-table-scroll">
      <table class="kfb-table">
        <thead><tr><th>Add-on</th><th>Region</th><th>Qty</th><th>Unit price</th><th>Line total</th></tr></thead>
        <tbody>
          <?php foreach ($addons as $a): ?>
            <tr>
              <td><?= kfb_or_dash($a['name'] ?? NULL) ?></td>
              <td><?= kfb_or_dash($a['region'] ?? NULL) ?></td>
              <td><?= (int)($a['quantity'] ?? 1) ?></td>
              <td>$<?= number_format((float)($a['unit_price'] ?? 0), 2) ?></td>
              <td>$<?= number_format((float)($a['line_total'] ?? 0), 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="kfb-hint" style="margin-top:8px">Add-ons subtotal: $<?= number_format((float)$booking['addons_total'], 2) ?></p>
  </section>
<?php endif; ?>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>Pricing breakdown</h2></header>
  <div class="kfb-detail-grid">
    <div><span class="kfb-hint">Distance</span><p><?= number_format((float)$booking['distance_miles'], 1) ?> mi</p></div>
    <div><span class="kfb-hint">Duration</span><p><?= (int)$booking['duration_mins'] ?> min</p></div>
    <?php if ((float)$booking['min_fare_applied'] > 0): ?>
      <div><span class="kfb-hint">Minimum fare applied</span><p>$<?= number_format((float)$booking['min_fare_applied'], 2) ?></p></div>
    <?php endif; ?>
    <?php if ((float)$booking['addons_total'] > 0): ?>
      <div><span class="kfb-hint">Add-ons subtotal</span><p>$<?= number_format((float)$booking['addons_total'], 2) ?></p></div>
    <?php endif; ?>
    <?php if ((float)$booking['discount_amount'] > 0): ?>
      <div><span class="kfb-hint">Promo discount</span><p>−$<?= number_format((float)$booking['discount_amount'], 2) ?> (<?= kfb_or_dash($booking['promo_code'] ?? NULL) ?>)</p></div>
    <?php endif; ?>
    <div><span class="kfb-hint">Total charged</span><p><strong>$<?= number_format((float)$booking['amount'], 2) ?></strong></p></div>
  </div>
</section>

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
    <p class="kfb-empty">No signature on file — only required for bookings $500 and over.</p>
  <?php endif; ?>
</section>

<?php if (!empty($booking['return_leg'])): $rl = $booking['return_leg']; [$rlBadge, $rlLabel] = kfb_reservation_badge($rl['status']); ?>
  <section class="kfb-card">
    <header class="kfb-card-head">
      <h2>Pickup &amp; drop-off — return leg <span class="kfb-badge kfb-badge--<?= $rlBadge ?>"><?= htmlspecialchars($rlLabel) ?></span></h2>
    </header>
    <div class="kfb-detail-grid" style="margin-bottom:14px">
      <div><span class="kfb-hint">Date</span><p><?= kfb_or_dash($rl['pickup_date'] ?? NULL) ?></p></div>
      <div><span class="kfb-hint">Time</span><p><?= kfb_hm($rl['pickup_time'] ?? NULL) ?></p></div>
      <div><span class="kfb-hint">Passengers</span><p><?= (int)($rl['passengers'] ?? 0) ?> · <?= (int)($rl['luggage'] ?? 0) ?> bags</p></div>
    </div>
    <?= kfb_leg_trip_html($rl) ?>
    <p class="kfb-hint" style="margin-top:14px">Charged and captured together with the outbound leg above — no separate payment.</p>
  </section>
<?php endif; ?>

<?php if ($isPaid): ?>
  <section class="kfb-card">
    <header class="kfb-card-head">
      <h2>Additional charges</h2>
    </header>
    <p class="kfb-hint" style="margin: -4px 0 0;">
      Not available for PayPal's redirect checkout — no chargeable payment method is retained
      once the customer approves and the payment is captured. For extra charges discovered after
      the trip (e.g. waiting time), send the customer a new payment request or process it
      manually from the PayPal dashboard.
    </p>
  </section>
<?php endif; ?>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>Payment history</h2>
  </header>
  <?php if (!empty($booking['paypal_order_id']) || !empty($booking['paypal_auth_transaction_id']) || !empty($booking['paypal_capture_transaction_id'])): ?>
    <p class="kfb-hint" style="margin: -4px 0 12px;">
      <?php if (!empty($booking['paypal_order_id'])): ?>Order <code class="kfb-mono"><?= htmlspecialchars($booking['paypal_order_id']) ?></code><?php endif; ?>
      <?php if (!empty($booking['paypal_auth_transaction_id'])): ?> · Authorization <code class="kfb-mono"><?= htmlspecialchars($booking['paypal_auth_transaction_id']) ?></code><?php endif; ?>
      <?php if (!empty($booking['paypal_capture_transaction_id'])): ?> · Capture <code class="kfb-mono"><?= htmlspecialchars($booking['paypal_capture_transaction_id']) ?></code><?php endif; ?>
    </p>
  <?php endif; ?>
  <?php if (empty($booking['payments'])): ?>
    <p class="kfb-empty">No payment events yet.</p>
  <?php else: ?>
    <div class="kfb-table-scroll">
      <table class="kfb-table">
        <thead>
          <tr><th>When</th><th>Event</th><th>PayPal status</th><th>Amount</th></tr>
        </thead>
        <tbody>
          <?php foreach ($booking['payments'] as $p): ?>
            <tr>
              <td><small class="kfb-hint"><?= htmlspecialchars($p['created_at']) ?></small></td>
              <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $p['event']))) ?></td>
              <td><code class="kfb-mono"><?= htmlspecialchars($p['status']) ?></code></td>
              <td>$<?= number_format((float)$p['amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
