<?php
$filters = isset($filters) ? $filters : ['search' => '', 'company_id' => 0, 'status' => '', 'verified' => ''];
$pagination = isset($pagination) ? $pagination : ['page' => 1, 'per_page' => 50, 'total' => count($users), 'total_pages' => 1];

// Pagination links keep every active filter.
$page_url = function ($page) use ($filters) {
    $p = [];
    if ($filters['search'] !== '')    $p['q'] = $filters['search'];
    if ((int)$filters['company_id'])  $p['company'] = (int)$filters['company_id'];
    if ($filters['status'] !== '')    $p['status'] = $filters['status'];
    if ($filters['verified'] !== '')  $p['verified'] = $filters['verified'];
    if ($page > 1)                    $p['page'] = $page;
    return site_url('admin/users') . ($p ? '?' . http_build_query($p) : '');
};
?>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>All users <small class="kfb-hint">(<?= number_format((int)$pagination['total']) ?>)</small></h2>
  </header>

  <form method="get" action="<?= site_url('admin/users') ?>" class="kfb-reservation-search">
    <?php if (!empty($companies) && count($companies) > 1): ?>
      <select name="company" onchange="this.form.submit()" class="kfb-reservation-search-input" style="max-width:200px" aria-label="Company">
        <option value="">All companies</option>
        <?php foreach ($companies as $co): ?>
          <option value="<?= (int)$co['id'] ?>" <?= (int)$filters['company_id'] === (int)$co['id'] ? 'selected' : '' ?>><?= htmlspecialchars($co['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="status" onchange="this.form.submit()" class="kfb-reservation-search-input" style="max-width:150px" aria-label="Status">
      <option value="">Any status</option>
      <option value="active"   <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="disabled" <?= $filters['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
    </select>
    <select name="verified" onchange="this.form.submit()" class="kfb-reservation-search-input" style="max-width:170px" aria-label="Email verification">
      <option value="">Verified or not</option>
      <option value="yes" <?= $filters['verified'] === 'yes' ? 'selected' : '' ?>>Email verified</option>
      <option value="no"  <?= $filters['verified'] === 'no' ? 'selected' : '' ?>>Not verified</option>
    </select>
    <input type="search" name="q" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="Search name, email, phone…" class="kfb-reservation-search-input">
    <button type="submit" class="kfb-btn kfb-btn--primary kfb-btn--sm">Search</button>
    <?php if ($filters['search'] !== '' || (int)$filters['company_id'] || $filters['status'] !== '' || $filters['verified'] !== ''): ?>
      <a href="<?= site_url('admin/users') ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (empty($users)): ?>
    <p class="kfb-empty">No users match.</p>
  <?php else: ?>
    <div class="kfb-table-scroll">
      <table class="kfb-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Company</th>
            <th>Type</th>
            <th>Email verified</th>
            <th>Status</th>
            <th>Reservations</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])) ?: '—' ?></td>
              <td><?= htmlspecialchars($u['email']) ?><?php if (!empty($u['phone'])): ?><br><small class="kfb-hint"><?= htmlspecialchars($u['phone']) ?></small><?php endif; ?></td>
              <td><?= htmlspecialchars($u['company_name'] ?? '—') ?></td>
              <td><?= (int)$u['has_account'] ? 'Account' : '<span class="kfb-hint">Guest</span>' ?></td>
              <td>
                <?php if (!empty($u['email_verified_at'])): ?>
                  <span class="kfb-badge kfb-badge--ok">Verified</span>
                <?php elseif ((int)$u['has_account']): ?>
                  <span class="kfb-badge kfb-badge--warn">Pending</span>
                <?php else: ?>
                  <span class="kfb-hint">—</span>
                <?php endif; ?>
              </td>
              <td><span class="kfb-badge <?= $u['status'] === 'active' ? 'kfb-badge--ok' : 'kfb-badge--off' ?>"><?= $u['status'] === 'active' ? 'Active' : 'Disabled' ?></span></td>
              <td><?= (int)$u['reservations_count'] ?></td>
              <td><small class="kfb-hint"><?= htmlspecialchars(substr((string)$u['created_at'], 0, 10)) ?></small></td>
              <td><a href="<?= site_url('admin/users/' . (int)$u['id']) ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">View →</a></td>
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
        <span class="kfb-pagination-summary">Showing <?= number_format($rangeStart) ?>–<?= number_format($rangeEnd) ?> of <?= number_format((int)$pagination['total']) ?></span>
        <div class="kfb-pagination-nav">
          <?php if ($pagination['page'] > 1): ?>
            <a class="kfb-btn kfb-btn--ghost kfb-btn--sm" href="<?= $page_url($pagination['page'] - 1) ?>">← Prev</a>
          <?php else: ?>
            <span class="kfb-btn kfb-btn--ghost kfb-btn--sm is-disabled">← Prev</span>
          <?php endif; ?>
          <span class="kfb-pagination-page">Page <?= (int)$pagination['page'] ?> of <?= (int)$pagination['total_pages'] ?></span>
          <?php if ($pagination['page'] < $pagination['total_pages']): ?>
            <a class="kfb-btn kfb-btn--ghost kfb-btn--sm" href="<?= $page_url($pagination['page'] + 1) ?>">Next →</a>
          <?php else: ?>
            <span class="kfb-btn kfb-btn--ghost kfb-btn--sm is-disabled">Next →</span>
          <?php endif; ?>
        </div>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
