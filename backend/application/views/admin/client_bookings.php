<?php
$client_bookings = isset($client_bookings) ? $client_bookings : [];
$search_query    = isset($search_query) ? $search_query : '';
$pagination      = isset($pagination) ? $pagination : ['page' => 1, 'per_page' => count($client_bookings), 'total' => count($client_bookings), 'total_pages' => 1];

// $q carries the current search box text through pagination links, same
// pattern as kfb_reservations_page_url() in reservations.php.
if (!function_exists('kfb_client_bookings_page_url')) {
    function kfb_client_bookings_page_url($page, $q = '')
    {
        $params = [];
        if ($q !== '') $params['q'] = $q;
        if ($page > 1) $params['page'] = $page;
        return site_url('admin/client-bookings') . (!empty($params) ? '?' . http_build_query($params) : '');
    }
}
?>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>Client bookings</h2>
    <p class="kfb-hint">Submitted via reservation-detail.html — trip details only, no pricing/payment. Review manually.</p>
  </header>

  <form method="get" action="<?= site_url('admin/client-bookings') ?>" class="kfb-reservation-search">
    <input type="search" name="q" value="<?= htmlspecialchars($search_query) ?>"
           placeholder="Search booking ID, name, email, phone, pickup/dropoff, vehicle…"
           class="kfb-reservation-search-input">
    <button type="submit" class="kfb-btn kfb-btn--primary kfb-btn--sm">Search</button>
    <?php if ($search_query !== ''): ?>
      <a href="<?= kfb_client_bookings_page_url(1) ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (empty($client_bookings)): ?>
    <p class="kfb-empty">
      No client bookings
      <?php if ($search_query !== ''): ?> matching &ldquo;<?= htmlspecialchars($search_query) ?>&rdquo;<?php endif; ?>
      .
    </p>
  <?php else: ?>
    <div class="kfb-table-scroll">
      <table class="kfb-table">
        <thead>
          <tr>
            <th>Booking</th>
            <th>Customer</th>
            <th>Pick-up</th>
            <th>Vehicle</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($client_bookings as $r): ?>
            <tr>
              <td><code class="kfb-mono"><?= htmlspecialchars($r['booking_id']) ?></code></td>
              <td>
                <?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?: '—' ?>
                <br><small class="kfb-hint"><?= htmlspecialchars($r['email']) ?></small>
              </td>
              <td><?= htmlspecialchars((string)$r['pickup_date']) ?> <small class="kfb-hint"><?= htmlspecialchars(substr((string)$r['pickup_time'], 0, 5)) ?></small></td>
              <td><?= htmlspecialchars($r['vehicle_name']) ?: '—' ?></td>
              <td><small class="kfb-hint"><?= htmlspecialchars($r['created_at']) ?></small></td>
              <td><a href="<?= site_url('admin/client-bookings/' . $r['booking_id']) ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">View →</a></td>
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
            <a class="kfb-btn kfb-btn--ghost kfb-btn--sm" href="<?= kfb_client_bookings_page_url($pagination['page'] - 1, $search_query) ?>">← Prev</a>
          <?php else: ?>
            <span class="kfb-btn kfb-btn--ghost kfb-btn--sm is-disabled">← Prev</span>
          <?php endif; ?>
          <span class="kfb-pagination-page">Page <?= number_format((int)$pagination['page']) ?> of <?= number_format((int)$pagination['total_pages']) ?></span>
          <?php if ($pagination['page'] < $pagination['total_pages']): ?>
            <a class="kfb-btn kfb-btn--ghost kfb-btn--sm" href="<?= kfb_client_bookings_page_url($pagination['page'] + 1, $search_query) ?>">Next →</a>
          <?php else: ?>
            <span class="kfb-btn kfb-btn--ghost kfb-btn--sm is-disabled">Next →</span>
          <?php endif; ?>
        </div>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
