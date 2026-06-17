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
          <th>Chicago / hr</th>
          <th>Chicago / km</th>
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
            <td>$<?= number_format((float)$v['hourly_chicago'], 2) ?></td>
            <td>$<?= number_format((float)$v['per_km_chicago'], 2) ?></td>
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