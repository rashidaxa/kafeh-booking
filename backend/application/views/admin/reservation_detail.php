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
[$badge, $badgeLabel] = kfb_reservation_badge($booking['status'] ?? '');
$hasCard        = !empty($booking['stripe_customer_id']) && !empty($booking['stripe_payment_method_id']);
$awaitingReview = ($booking['status'] ?? '') === 'awaiting_approval';
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
        <?= htmlspecialchars($booking['pickup_date']) ?> at <?= htmlspecialchars(substr((string)$booking['pickup_time'], 0, 5)) ?><br>
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
          <span class="kfb-hint">No card on file</span>
        <?php endif; ?>
      </p>
    </div>
    <?php if (!empty($booking['approved_at'])): ?>
      <div>
        <span class="kfb-hint">Decision</span>
        <p><?= htmlspecialchars($booking['approved_at']) ?> by <?= htmlspecialchars($booking['approved_by'] ?: '—') ?></p>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($booking['notes'])): ?>
    <p class="kfb-hint" style="margin-top:12px">Notes: <?= htmlspecialchars($booking['notes']) ?></p>
  <?php endif; ?>
</section>

<?php if (!empty($booking['return_leg'])): $rl = $booking['return_leg']; [$rlBadge, $rlLabel] = kfb_reservation_badge($rl['status']); ?>
  <section class="kfb-card">
    <header class="kfb-card-head">
      <h2>Return leg <span class="kfb-badge kfb-badge--<?= $rlBadge ?>"><?= htmlspecialchars($rlLabel) ?></span></h2>
    </header>
    <p>
      <?= htmlspecialchars($rl['pickup_date']) ?> at <?= htmlspecialchars(substr((string)$rl['pickup_time'], 0, 5)) ?><br>
      <?= htmlspecialchars($rl['pickup']) ?> → <?= htmlspecialchars($rl['dropoff']) ?>
    </p>
    <p class="kfb-hint">Charged and captured together with the outbound leg above — no separate payment.</p>
  </section>
<?php endif; ?>

<?php if ($hasCard): ?>
  <section class="kfb-card">
    <header class="kfb-card-head">
      <h2>Charge additional amount</h2>
    </header>
    <p class="kfb-hint" style="margin: -4px 0 16px;">
      Bills the customer's saved card again — e.g. extra waiting time discovered after the trip.
      Works regardless of the reservation's current status.
    </p>
    <form id="kfbChargeForm" class="kfb-form kfb-form--grid"
          action="<?= site_url('admin/api/reservations/' . $booking['booking_id'] . '/charge') ?>"
          method="post" onsubmit="return false;">
      <label class="kfb-field">
        <span>Amount <em>*</em></span>
        <div class="kfb-money">
          <span class="kfb-money-prefix">$</span>
          <input type="number" name="amount" required min="0.01" step="0.01" placeholder="0.00">
        </div>
      </label>
      <label class="kfb-field kfb-field--full">
        <span>Description</span>
        <input type="text" name="description" maxlength="255" placeholder="e.g. Extra waiting time (45 min)">
      </label>
      <div class="kfb-form-actions">
        <button type="submit" class="kfb-btn kfb-btn--primary">Charge card</button>
      </div>
      <div class="kfb-form-errors" id="kfbFormErrors" hidden></div>
    </form>
  </section>
<?php endif; ?>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>Payment history</h2>
  </header>
  <?php if (empty($booking['payments'])): ?>
    <p class="kfb-empty">No payment events yet.</p>
  <?php else: ?>
    <div class="kfb-table-scroll">
      <table class="kfb-table">
        <thead>
          <tr><th>When</th><th>Event</th><th>Stripe status</th><th>Amount</th></tr>
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
