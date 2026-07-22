<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create first admin · Admin Portal</title>
<link rel="stylesheet" href="<?= base_url('assets/admin/admin.css') ?>">
</head>
<body class="kfb-auth">
<div class="kfb-auth-card">
  <div class="kfb-auth-brand">
    <span class="kfb-brand-mark">A</span>
    <div>
      <strong>Admin</strong>
      <small>First-time setup</small>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="kfb-flash kfb-flash--<?= htmlspecialchars($flash['type'] ?? 'info') ?>">
      <?= htmlspecialchars($flash['message'] ?? '') ?>
    </div>
  <?php endif; ?>

  <p class="kfb-auth-help">
    Create the first admin account. Once any admin exists this page is locked.
  </p>

  <form method="post" action="<?= site_url('admin/setup/save') ?>" class="kfb-form" autocomplete="off">
    <label>
      <span>Username <em>(required)</em></span>
      <input type="text" name="username" required autofocus minlength="3"
             value="<?= htmlspecialchars(isset($username) ? $username : '') ?>">
      <?php if (!empty($errors['username'])): ?><small class="kfb-error"><?= htmlspecialchars($errors['username']) ?></small><?php endif; ?>
    </label>
    <label>
      <span>Display name <em>(optional)</em></span>
      <input type="text" name="display_name"
             value="<?= htmlspecialchars(isset($display_name) ? $display_name : '') ?>">
    </label>
    <label>
      <span>Email <em>(optional)</em></span>
      <input type="email" name="email"
             value="<?= htmlspecialchars(isset($email) ? $email : '') ?>">
      <?php if (!empty($errors['email'])): ?><small class="kfb-error"><?= htmlspecialchars($errors['email']) ?></small><?php endif; ?>
    </label>
    <label>
      <span>Password <em>(min 6)</em></span>
      <input type="password" name="password" required minlength="6" autocomplete="new-password">
      <?php if (!empty($errors['password'])): ?><small class="kfb-error"><?= htmlspecialchars($errors['password']) ?></small><?php endif; ?>
    </label>
    <label>
      <span>Confirm password</span>
      <input type="password" name="password_confirm" required minlength="6" autocomplete="new-password">
      <?php if (!empty($errors['password_confirm'])): ?><small class="kfb-error"><?= htmlspecialchars($errors['password_confirm']) ?></small><?php endif; ?>
    </label>
    <button type="submit" class="kfb-btn kfb-btn--primary kfb-btn--block">Create admin</button>
  </form>
</div>
</body>
</html>