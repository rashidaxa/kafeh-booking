<?php
$reservations  = isset($reservations) ? $reservations : [];
$status_filter = isset($status_filter) ? $status_filter : '';
$pagination    = isset($pagination) ? $pagination : ['page' => 1, 'per_page' => count($reservations), 'total' => count($reservations), 'total_pages' => 1];

$statuses = [
    ''                  => 'All',
    'awaiting_approval' => 'Awaiting approval',
    'paid'              => 'Paid',
    'cancelled'         => 'Cancelled',
    'payment_failed'    => 'Payment failed',
    'pending'           => 'Pending',
];

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
if (!function_exists('kfb_reservations_page_url')) {
    function kfb_reservations_page_url($status, $page)
    {
        $params = [];
        if ($status !== '') $params['status'] = $status;
        if ($page > 1) $params['page'] = $page;
        return site_url('admin/reservations') . (!empty($params) ? '?' . http_build_query($params) : '');
    }
}
?>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>All reservations</h2>
    <nav class="kfb-status-filter">
      <?php foreach ($statuses as $val => $label): ?>
        <a href="<?= kfb_reservations_page_url($val, 1) ?>"
           class="kfb-btn kfb-btn--ghost kfb-btn--sm <?= $status_filter === $val ? 'is-active' : '' ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <?php if (empty($reservations)): ?>
    <p class="kfb-empty">No reservations<?= $status_filter !== '' ? ' with status “' . htmlspecialchars($status_filter) . '”' : '' ?> yet.</p>
  <?php else: ?>
    <div class="kfb-table-scroll">
      <table class="kfb-table">
        <thead>
          <tr>
            <th>Booking</th>
            <th>Customer</th>
            <th>Pick-up</th>
            <th>Amount</th>
            <th>Card</th>
            <th>Status</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reservations as $r): [$badge, $label] = kfb_reservation_badge($r['status']); ?>
            <tr>
              <td><code class="kfb-mono"><?= htmlspecialchars($r['booking_id']) ?></code></td>
              <td>
                <?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?: '—' ?>
                <br><small class="kfb-hint"><?= htmlspecialchars($r['email']) ?></small>
              </td>
              <td><?= htmlspecialchars($r['pickup_date']) ?> <small class="kfb-hint"><?= htmlspecialchars(substr((string)$r['pickup_time'], 0, 5)) ?></small></td>
              <td>$<?= number_format((float)$r['amount'], 2) ?></td>
              <td>
                <?php if ($r['card_brand']): ?>
                  <?= htmlspecialchars(ucfirst($r['card_brand'])) ?> •••• <?= htmlspecialchars($r['card_last4']) ?>
                <?php else: ?>
                  <span class="kfb-hint">—</span>
                <?php endif; ?>
              </td>
              <td><span class="kfb-badge kfb-badge--<?= $badge ?>"><?= htmlspecialchars($label) ?></span></td>
              <td><small class="kfb-hint"><?= htmlspecialchars($r['created_at']) ?></small></td>
              <td><a href="<?= site_url('admin/reservations/' . $r['booking_id']) ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">View →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ((int)$pagination['total_pages'] > 1): ?>
      <?php
        $rangeStart = (($pagination['page'] - 1) * $pagination['per_page']) + 1;
        $rangeEnd   = min($pagination['total'], $pagination['page'] * $pagination['per_page']);
      ?>
      <nav class="kfb-pagination">
        <span class="kfb-pagination-summary">
          Showing <?= number_format($rangeStart) ?>–<?= number_format($rangeEnd) ?> of <?= number_format((int)$pagination['total']) ?>
        </span>
        <div class="kfb-pagination-nav">
          <?php if ($pagination['page'] > 1): ?>
            <a class="kfb-btn kfb-btn--ghost kfb-btn--sm" href="<?= kfb_reservations_page_url($status_filter, $pagination['page'] - 1) ?>">← Prev</a>
          <?php else: ?>
            <span class="kfb-btn kfb-btn--ghost kfb-btn--sm is-disabled">← Prev</span>
          <?php endif; ?>
          <span class="kfb-pagination-page">Page <?= number_format((int)$pagination['page']) ?> of <?= number_format((int)$pagination['total_pages']) ?></span>
          <?php if ($pagination['page'] < $pagination['total_pages']): ?>
            <a class="kfb-btn kfb-btn--ghost kfb-btn--sm" href="<?= kfb_reservations_page_url($status_filter, $pagination['page'] + 1) ?>">Next →</a>
          <?php else: ?>
            <span class="kfb-btn kfb-btn--ghost kfb-btn--sm is-disabled">Next →</span>
          <?php endif; ?>
        </div>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
