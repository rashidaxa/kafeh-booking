<?php
$page_title = isset($page_title) ? $page_title : 'Admin';
$admin      = isset($admin) ? $admin : NULL;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title) ?> · Admin Portal</title>
<link rel="stylesheet" href="<?= base_url('assets/admin/admin.css') ?>">
</head>
<body class="kfb-admin">
<aside class="kfb-sidebar">
  <div class="kfb-brand">
    <a href="<?= site_url('admin') ?>" class="kfb-brand-link" title="Go to dashboard">
      <span class="kfb-brand-mark">A</span>
      <div class="kfb-brand-text">
        <strong>Admin</strong>
        <small>Admin Portal</small>
      </div>
    </a>
  </div>
  <nav class="kfb-nav">
    <a href="<?= site_url('admin') ?>"          class="<?= $page_title === 'Dashboard' ? 'is-active' : '' ?>">Dashboard</a>
    <a href="<?= site_url('admin/vehicles') ?>" class="<?= $page_title === 'Vehicles' || $page_title === 'Edit Vehicle' ? 'is-active' : '' ?>">Vehicles</a>
    <a href="<?= site_url('admin/promos') ?>"   class="<?= $page_title === 'Promo Codes' || $page_title === 'Edit Promo Code' ? 'is-active' : '' ?>">Promo Codes</a>
    <a href="<?= site_url('admin/addons') ?>"   class="<?= $page_title === 'Add-On Services' || $page_title === 'Edit Add-On' ? 'is-active' : '' ?>">Add-Ons</a>
    <a href="<?= site_url('admin/reservations') ?>" class="<?= strpos($page_title, 'Reservation') === 0 ? 'is-active' : '' ?>">Reservations</a>
    <a href="<?= site_url('admin/settings') ?>" class="<?= $page_title === 'Settings' ? 'is-active' : '' ?>">Settings</a>
  </nav>
  <div class="kfb-sidebar-foot">
    <?php if ($admin): ?>
      <div class="kfb-user">
        <div class="kfb-avatar"><?= strtoupper(substr($admin['username'], 0, 1)) ?></div>
        <div class="kfb-user-meta">
          <strong><?= htmlspecialchars($admin['display_name'] ?: $admin['username']) ?></strong>
          <small>@<?= htmlspecialchars($admin['username']) ?></small>
        </div>
      </div>
      <a href="<?= site_url('admin/logout') ?>" class="kfb-logout">Log out</a>
    <?php endif; ?>
  </div>
</aside>
<main class="kfb-main">
  <header class="kfb-topbar">
    <h1><?= htmlspecialchars($page_title) ?></h1>
    <div class="kfb-topbar-meta">
      <span class="kfb-env"><?= htmlspecialchars(ENVIRONMENT) ?></span>
    </div>
  </header>
  <?php if (!empty($flash)): ?>
    <div class="kfb-flash kfb-flash--<?= htmlspecialchars($flash['type'] ?? 'info') ?>">
      <?= htmlspecialchars($flash['message'] ?? '') ?>
    </div>
  <?php endif; ?>