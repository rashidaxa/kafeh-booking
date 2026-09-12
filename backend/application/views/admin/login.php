<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Admin Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= base_url('assets/admin/admin.css') ?>?v=<?= filemtime(FCPATH . 'assets/admin/admin.css') ?>">
</head>
<body class="kfb-auth">
<div class="kfb-auth-card">
  <div class="kfb-auth-brand">
    <span class="kfb-brand-mark">A</span>
    <div>
      <strong>Admin</strong>
      <small>Admin Portal</small>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="kfb-flash kfb-flash--<?= htmlspecialchars($flash['type'] ?? 'info') ?>">
      <?= htmlspecialchars($flash['message'] ?? '') ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($is_setup)): ?>
    <div class="kfb-flash kfb-flash--info">
      No admin accounts yet. <a href="<?= site_url('admin/setup') ?>">Create the first admin →</a>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= site_url('admin/login/save') ?>" class="kfb-form" autocomplete="on">
    <label>
      <span>Username</span>
      <input type="text" name="username" required autofocus autocomplete="username"
             value="<?= htmlspecialchars(isset($username) ? $username : '') ?>">
    </label>
    <label>
      <span>Password</span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="kfb-btn kfb-btn--primary kfb-btn--block">Sign in</button>
  </form>
</div>
<script>window.KFB_ADMIN = { baseUrl: <?= json_encode(base_url()) ?>, siteUrl: <?= json_encode(site_url()) ?> };</script>
</body>
</html>