<?php
$u = $user;
$fullName = trim($u['first_name'] . ' ' . $u['last_name']);
$hasAccount = (int)$u['has_account'] === 1;
$isActive = $u['status'] === 'active';
$resCount = (int)$u['reservations_count'];
$act = function ($name) use ($u) { return site_url('admin/users/' . (int)$u['id'] . '/' . $name); };
$deleteMsg = 'Delete ' . ($fullName !== '' ? $fullName : $u['email']) . '? Their saved cards and sessions are removed. '
    . ($resCount ? $resCount . ' reservation(s) will be kept as guest records.' : 'They have no reservations.')
    . ' This cannot be undone.';
?>

<p><a href="<?= site_url('admin/users') ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">← All users</a></p>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2><?= htmlspecialchars($fullName ?: $u['email']) ?></h2>
    <span>
      <span class="kfb-badge <?= $isActive ? 'kfb-badge--ok' : 'kfb-badge--off' ?>"><?= $isActive ? 'Active' : 'Disabled' ?></span>
      <?php if (!empty($u['email_verified_at'])): ?>
        <span class="kfb-badge kfb-badge--ok">Email verified</span>
      <?php elseif ($hasAccount): ?>
        <span class="kfb-badge kfb-badge--warn">Email not verified</span>
      <?php else: ?>
        <span class="kfb-badge kfb-badge--off">Guest (no account)</span>
      <?php endif; ?>
    </span>
  </header>
  <div class="kfb-detail-grid">
    <div><span class="kfb-hint">Company</span><p><?= htmlspecialchars($u['company_name'] ?? '—') ?></p></div>
    <div><span class="kfb-hint">Email</span><p><?= htmlspecialchars($u['email']) ?></p></div>
    <div><span class="kfb-hint">Phone</span><p><?= htmlspecialchars($u['phone'] ?: '—') ?></p></div>
    <div><span class="kfb-hint">Created</span><p><?= htmlspecialchars(kfb_datetime($u['created_at'])) ?></p></div>
    <div><span class="kfb-hint">Last booking</span><p><?= htmlspecialchars(kfb_datetime($u['last_booking_at'] ?? NULL)) ?></p></div>
    <div><span class="kfb-hint">Reservations</span><p><?= $resCount ?></p></div>
  </div>
</section>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>Edit profile</h2></header>
  <form method="post" action="<?= $act('save') ?>" class="kfb-form kfb-form--grid">
    <input type="hidden" name="form" value="profile">
    <label class="kfb-field"><span>First name <em>*</em></span><input type="text" name="first_name" required maxlength="100" value="<?= htmlspecialchars($u['first_name']) ?>"></label>
    <label class="kfb-field"><span>Last name <em>*</em></span><input type="text" name="last_name" required maxlength="100" value="<?= htmlspecialchars($u['last_name']) ?>"></label>
    <label class="kfb-field"><span>Phone</span><input type="text" name="phone" maxlength="50" value="<?= htmlspecialchars((string)$u['phone']) ?>"></label>
    <div class="kfb-form-actions kfb-field--full"><button type="submit" class="kfb-btn kfb-btn--primary">Save profile</button></div>
  </form>
</section>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>Change email</h2></header>
  <p class="kfb-hint" style="margin:-4px 0 12px;">
    The new email takes effect immediately but is <strong>unverified</strong> — the customer verifies it with a code at their next login.
    Their sessions are ended and both the old and new address get a notice. Reservations not yet accepted get the new email;
    accepted/paid ones keep the email they were booked with.
  </p>
  <form method="post" action="<?= $act('save') ?>" class="kfb-form kfb-form--grid">
    <input type="hidden" name="form" value="email">
    <label class="kfb-field kfb-field--full"><span>New email</span><input type="email" name="email" required maxlength="150" placeholder="<?= htmlspecialchars($u['email']) ?>"></label>
    <div class="kfb-form-actions kfb-field--full"><button type="submit" class="kfb-btn kfb-btn--primary">Change email</button></div>
  </form>
</section>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>Account actions</h2></header>
  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
    <?php if ($isActive): ?>
      <form method="post" action="<?= $act('action') ?>" onsubmit="return confirm('Disable this account? They will not be able to log in and their sessions end.')">
        <input type="hidden" name="action" value="disable"><button type="submit" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Disable account</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= $act('action') ?>">
        <input type="hidden" name="action" value="enable"><button type="submit" class="kfb-btn kfb-btn--primary kfb-btn--sm">Enable account</button>
      </form>
    <?php endif; ?>

    <?php if ($hasAccount && empty($u['email_verified_at'])): ?>
      <form method="post" action="<?= $act('action') ?>">
        <input type="hidden" name="action" value="resend_code"><button type="submit" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Resend verification code</button>
      </form>
    <?php endif; ?>
    <?php if (empty($u['email_verified_at'])): ?>
      <form method="post" action="<?= $act('action') ?>" onsubmit="return confirm('Mark this email as verified without a code?')">
        <input type="hidden" name="action" value="mark_verified"><button type="submit" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Mark email verified</button>
      </form>
    <?php endif; ?>

    <form method="post" action="<?= $act('action') ?>" onsubmit="return confirm('End all of this user\'s active sessions?')">
      <input type="hidden" name="action" value="logout_all"><button type="submit" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Log out everywhere</button>
    </form>

    <?php if ($hasAccount): ?>
      <form method="post" action="<?= $act('action') ?>" onsubmit="return confirm('Email a password-reset link to <?= htmlspecialchars($u['email'], ENT_QUOTES) ?>?')">
        <input type="hidden" name="action" value="send_reset_link"><button type="submit" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Send password-reset link</button>
      </form>
    <?php endif; ?>

    <form method="post" action="<?= $act('action') ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($deleteMsg), ENT_QUOTES) ?>)">
      <input type="hidden" name="action" value="delete"><button type="submit" class="kfb-btn kfb-btn--sm" style="background:#dc2626;color:#fff;border-color:#dc2626;">Delete user</button>
    </form>
  </div>

  <?php if ($hasAccount): ?>
    <form method="post" action="<?= $act('action') ?>" class="kfb-form kfb-form--grid" onsubmit="return confirm('Set this password and end the customer\'s sessions?')">
      <input type="hidden" name="action" value="set_password">
      <label class="kfb-field kfb-field--full"><span>Set a new password <em>(min 6 characters — you tell the customer; their sessions end)</em></span>
        <input type="text" name="new_password" minlength="6" autocomplete="off" placeholder="New password"></label>
      <div class="kfb-form-actions kfb-field--full"><button type="submit" class="kfb-btn kfb-btn--primary kfb-btn--sm">Set password</button></div>
    </form>
  <?php else: ?>
    <p class="kfb-hint">This is a guest record (booked without creating an account), so there is no password to reset.</p>
  <?php endif; ?>
</section>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>Saved cards</h2></header>
  <?php if (empty($cards)): ?>
    <p class="kfb-empty">No saved cards.</p>
  <?php else: ?>
    <div class="kfb-table-scroll"><table class="kfb-table">
      <thead><tr><th>Card</th><th>Nickname</th><th>Expires</th><th>Default</th></tr></thead>
      <tbody>
        <?php foreach ($cards as $c): ?>
          <tr>
            <td><?= htmlspecialchars(ucfirst((string)$c['card_brand'])) ?> •••• <?= htmlspecialchars(substr(preg_replace('/\D/', '', (string)$c['card_last4']), -4)) ?></td>
            <td><?= htmlspecialchars((string)($c['nickname'] ?? '')) ?: '—' ?></td>
            <td><?= htmlspecialchars(sprintf('%02d/%s', (int)($c['expiry_month'] ?? 0), (string)($c['expiry_year'] ?? ''))) ?></td>
            <td><?= !empty($c['is_default']) ? 'Yes' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<section class="kfb-card">
  <header class="kfb-card-head"><h2>Reservations</h2></header>
  <?php if (empty($reservations)): ?>
    <p class="kfb-empty">No reservations linked to this user.</p>
  <?php else: ?>
    <div class="kfb-table-scroll"><table class="kfb-table">
      <thead><tr><th>Booking</th><th>Passenger</th><th>Pick-up</th><th>Amount</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($reservations as $r): ?>
          <tr>
            <td><code class="kfb-mono"><?= htmlspecialchars($r['booking_id']) ?></code></td>
            <td><?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])) ?: '—' ?></td>
            <td><?= htmlspecialchars(kfb_date($r['pickup_date'])) ?> <small class="kfb-hint"><?= htmlspecialchars(kfb_hm($r['pickup_time'])) ?></small></td>
            <td>$<?= number_format((float)$r['amount'], 2) ?></td>
            <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$r['status']))) ?></td>
            <td><small class="kfb-hint"><?= htmlspecialchars(kfb_datetime($r['created_at'])) ?></small></td>
            <td><a href="<?= site_url('admin/reservations/' . $r['booking_id']) ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">View →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
