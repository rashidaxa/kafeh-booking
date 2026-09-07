<?php
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
$reservation_stats = isset($reservation_stats) ? $reservation_stats : ['total' => 0, 'awaiting_approval' => 0, 'paid' => 0, 'cancelled' => 0];
$recent_reservations = isset($recent_reservations) ? $recent_reservations : [];
?>

<h2 class="kfb-section-heading">Reservations</h2>
<section class="kfb-stats">
  <div class="kfb-stat">
    <span class="kfb-stat-label">Total reservations</span>
    <strong class="kfb-stat-value"><?= (int)$reservation_stats['total'] ?></strong>
    <a href="<?= site_url('admin/reservations') ?>" class="kfb-stat-link">View all →</a>
  </div>
  <div class="kfb-stat <?= (int)$reservation_stats['awaiting_approval'] > 0 ? 'kfb-stat--warn' : '' ?>">
    <span class="kfb-stat-label">Awaiting approval</span>
    <strong class="kfb-stat-value"><?= (int)$reservation_stats['awaiting_approval'] ?></strong>
    <a href="<?= site_url('admin/reservations') ?>?status=awaiting_approval" class="kfb-stat-link">Review →</a>
  </div>
  <div class="kfb-stat">
    <span class="kfb-stat-label">Paid</span>
    <strong class="kfb-stat-value"><?= (int)$reservation_stats['paid'] ?></strong>
    <span class="kfb-stat-sub">accepted &amp; captured</span>
  </div>
  <div class="kfb-stat">
    <span class="kfb-stat-label">Cancelled</span>
    <strong class="kfb-stat-value"><?= (int)$reservation_stats['cancelled'] ?></strong>
    <span class="kfb-stat-sub">rejected or voided</span>
  </div>
</section>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>Recent reservations</h2>
    <a href="<?= site_url('admin/reservations') ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">View all →</a>
  </header>

  <?php if (empty($recent_reservations)): ?>
    <p class="kfb-empty">No reservations yet.</p>
  <?php else: ?>
    <div class="kfb-table-scroll">
      <table class="kfb-table">
        <thead>
          <tr>
            <th>Booking</th>
            <th>Customer</th>
            <th>Pick-up</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent_reservations as $r): [$badge, $label] = kfb_reservation_badge($r['status']); ?>
            <tr>
              <td><code class="kfb-mono"><?= htmlspecialchars($r['booking_id']) ?></code></td>
              <td>
                <?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?: '—' ?>
                <br><small class="kfb-hint"><?= htmlspecialchars($r['email']) ?></small>
              </td>
              <td><?= htmlspecialchars($r['pickup_date']) ?> <small class="kfb-hint"><?= htmlspecialchars(substr((string)$r['pickup_time'], 0, 5)) ?></small></td>
              <td>$<?= number_format((float)$r['amount'], 2) ?></td>
              <td><span class="kfb-badge kfb-badge--<?= $badge ?>"><?= htmlspecialchars($label) ?></span></td>
              <td><small class="kfb-hint"><?= htmlspecialchars($r['created_at']) ?></small></td>
              <td><a href="<?= site_url('admin/reservations/' . $r['booking_id']) ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">View →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<h2 class="kfb-section-heading">Vehicles</h2>
<section class="kfb-stats">
  <div class="kfb-stat">
    <span class="kfb-stat-label">Total vehicles</span>
    <strong class="kfb-stat-value"><?= (int)$stats['total_vehicles'] ?></strong>
    <a href="<?= site_url('admin/vehicles') ?>" class="kfb-stat-link">Manage →</a>
  </div>
  <div class="kfb-stat">
    <span class="kfb-stat-label">Enabled</span>
    <strong class="kfb-stat-value"><?= (int)$stats['enabled_vehicles'] ?></strong>
    <span class="kfb-stat-sub">visible to customers</span>
  </div>
  <div class="kfb-stat">
    <span class="kfb-stat-label">Disabled</span>
    <strong class="kfb-stat-value"><?= (int)$stats['disabled_vehicles'] ?></strong>
    <span class="kfb-stat-sub">hidden from booking widget</span>
  </div>
</section>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>Recent vehicles</h2>
    <a href="<?= site_url('admin/vehicles') ?>" class="kfb-btn kfb-btn--primary kfb-btn--sm">+ Add vehicle</a>
  </header>

  <?php if (empty($recent_vehicles)): ?>
    <p class="kfb-empty">No vehicles yet. <a href="<?= site_url('admin/vehicles') ?>">Create the first one →</a></p>
  <?php else: ?>
    <table class="kfb-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Passengers</th>
          <th>Local / hr</th>
          <th>Local / mi</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent_vehicles as $v): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($v['name']) ?></strong>
              <?php if ($v['code']): ?><small class="kfb-mono"> · <?= htmlspecialchars($v['code']) ?></small><?php endif; ?>
            </td>
            <td><?= (int)$v['min_passengers'] ?>–<?= (int)$v['max_passengers'] ?></td>
            <td>$<?= number_format((float)$v['local_hourly_rate'], 2) ?></td>
            <td>$<?= number_format((float)$v['local_per_mile_rate'], 2) ?></td>
            <td>
              <?php if ((int)$v['status'] === 1): ?>
                <span class="kfb-badge kfb-badge--ok">Enabled</span>
              <?php else: ?>
                <span class="kfb-badge kfb-badge--off">Disabled</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
