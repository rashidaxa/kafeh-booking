<?php
$page_title = isset($page_title) ? $page_title : 'Admin';
$admin      = isset($admin) ? $admin : NULL;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title) ?> · Admin Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= base_url('assets/admin/admin.css') ?>?v=<?= filemtime(FCPATH . 'assets/admin/admin.css') ?>">
</head>
<body class="kfb-admin">
<aside class="kfb-sidebar" id="kfbSidebar">
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
    <a href="<?= site_url('admin') ?>"          class="<?= $page_title === 'Dashboard' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect></svg>
      Dashboard
    </a>
    <a href="<?= site_url('admin/vehicles') ?>" class="<?= $page_title === 'Vehicles' || $page_title === 'Edit Vehicle' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 16l1.2-4.5a2 2 0 0 1 1.9-1.5h9.8a2 2 0 0 1 1.9 1.5L20 16"></path><rect x="2.5" y="16" width="19" height="4" rx="1.5"></rect><circle cx="7" cy="20.2" r="1.3"></circle><circle cx="17" cy="20.2" r="1.3"></circle></svg>
      Vehicles
    </a>
    <a href="<?= site_url('admin/promos') ?>"   class="<?= $page_title === 'Promo Codes' || $page_title === 'Edit Promo Code' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="19" x2="19" y2="5"></line><circle cx="7.5" cy="7.5" r="2.3"></circle><circle cx="16.5" cy="16.5" r="2.3"></circle></svg>
      Promo Codes
    </a>
    <a href="<?= site_url('admin/addons') ?>"   class="<?= $page_title === 'Add-On Services' || $page_title === 'Edit Add-On' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
      Add-Ons
    </a>
    <a href="<?= site_url('admin/surcharges') ?>" class="<?= $page_title === 'Surcharges' || $page_title === 'Edit Surcharge' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3Z"></path><line x1="8.5" y1="8" x2="15.5" y2="8"></line><line x1="8.5" y1="12" x2="15.5" y2="12"></line></svg>
      Surcharges
    </a>
    <a href="<?= site_url('admin/reservations') ?>" class="<?= strpos($page_title, 'Reservation') === 0 ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="16" rx="2"></rect><line x1="3.5" y1="10" x2="20.5" y2="10"></line><line x1="8" y1="3" x2="8" y2="7"></line><line x1="16" y1="3" x2="16" y2="7"></line></svg>
      Reservations
    </a>
    <a href="<?= site_url('admin/settings') ?>" class="<?= $page_title === 'Pricing Settings' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"></line><circle cx="9" cy="6" r="2"></circle><line x1="4" y1="12" x2="20" y2="12"></line><circle cx="16" cy="12" r="2"></circle><line x1="4" y1="18" x2="20" y2="18"></line><circle cx="11" cy="18" r="2"></circle></svg>
      Settings
    </a>
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
<div class="kfb-sidebar-backdrop" id="kfbSidebarBackdrop"></div>
<main class="kfb-main">
  <header class="kfb-topbar">
    <div class="kfb-topbar-left">
      <button type="button" class="kfb-nav-toggle" id="kfbNavToggle" aria-label="Toggle navigation" aria-expanded="false" aria-controls="kfbSidebar">
        <span></span><span></span><span></span>
      </button>
      <h1><?= htmlspecialchars($page_title) ?></h1>
    </div>
    <div class="kfb-topbar-meta">
      <span class="kfb-env"><?= htmlspecialchars(ENVIRONMENT) ?></span>
    </div>
  </header>
  <?php if (!empty($flash)): ?>
    <div class="kfb-flash kfb-flash--<?= htmlspecialchars($flash['type'] ?? 'info') ?>">
      <?= htmlspecialchars($flash['message'] ?? '') ?>
    </div>
  <?php endif; ?>