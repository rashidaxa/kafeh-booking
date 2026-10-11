<?php
$editing = isset($editing) ? $editing : NULL;
$errors  = isset($errors) ? $errors : [];
$is_edit = !empty($editing['id']);
$val = function ($k, $default = '') use ($editing) {
    return htmlspecialchars((string)(($editing[$k] ?? NULL) !== NULL ? $editing[$k] : $default));
};
$domainsText = '';
if (!empty($editing['domains'])) {
    $domainsText = htmlspecialchars(is_array($editing['domains']) ? implode("\n", $editing['domains']) : (string)$editing['domains']);
}
$err = function ($k) use ($errors) {
    return isset($errors[$k]) ? '<small class="kfb-error">' . htmlspecialchars($errors[$k]) . '</small>' : '';
};
?>

<section class="kfb-split">
  <!-- Left: company list -->
  <aside class="kfb-card kfb-card--narrow">
    <header class="kfb-card-head">
      <h2>All companies</h2>
      <a href="<?= site_url('admin/companies/new') ?>" class="kfb-btn kfb-btn--primary kfb-btn--sm">+ New</a>
    </header>

    <?php if (empty($companies)): ?>
      <p class="kfb-empty">No companies yet.</p>
    <?php else: ?>
      <ul class="kfb-list">
        <?php foreach ($companies as $c): ?>
          <li class="kfb-list-item kfb-list-item--thumb <?= ($is_edit && (int)$editing['id'] === (int)$c['id']) ? 'is-active' : '' ?>"
              onclick="window.location='<?= site_url('admin/companies/' . (int)$c['id']) ?>'" style="cursor:pointer">
            <div class="kfb-list-thumb">
              <?php if (!empty($c['logo'])): ?>
                <img src="<?= base_url(Company_model::LOGO_DIR . $c['logo']) ?>" alt="">
              <?php else: ?>
                <span><?= htmlspecialchars(strtoupper(substr($c['name'], 0, 1))) ?></span>
              <?php endif; ?>
            </div>
            <div class="kfb-list-body">
              <strong><?= htmlspecialchars($c['name']) ?></strong>
              <small><?= htmlspecialchars(implode(', ', $c['domains']) ?: 'no domains') ?></small>
            </div>
            <div class="kfb-list-actions">
              <span class="kfb-badge <?= $c['status'] === 'active' ? 'kfb-badge--ok' : 'kfb-badge--off' ?>"><?= $c['status'] === 'active' ? 'Active' : 'Off' ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>

  <!-- Right: form -->
  <section class="kfb-card">
    <header class="kfb-card-head">
      <h2><?= $is_edit ? 'Edit company' : 'Add company' ?></h2>
      <?php if ($is_edit): ?>
        <a href="<?= site_url('admin/companies') ?>" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Cancel</a>
      <?php endif; ?>
    </header>

    <?php if (!empty($errors['general'])): ?>
      <div class="kfb-flash kfb-flash--error"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>

    <form id="kfbCompanyForm" class="kfb-form kfb-form--grid" action="<?= site_url('admin/companies/save') ?>" method="post" enctype="multipart/form-data">
      <input type="hidden" name="id" value="<?= $is_edit ? (int)$editing['id'] : '' ?>">

      <fieldset class="kfb-fieldset">
        <legend>Company</legend>

        <label class="kfb-field kfb-field--full">
          <span>Company name <em>*</em></span>
          <input type="text" name="name" required maxlength="150" value="<?= $val('name') ?>">
          <?= $err('name') ?>
        </label>

        <label class="kfb-field kfb-field--full">
          <span>Website domains <em>*</em> <em>(one per line — the sites this company's widget runs on, e.g. <code>bookings.example.com</code>; <code>www.</code> is ignored)</em></span>
          <textarea name="domains" rows="3" required placeholder="example.com&#10;staging.example.com"><?= $domainsText ?></textarea>
          <?= $err('domains') ?>
        </label>

        <label class="kfb-field kfb-field--full">
          <span>Widget page URL <em>(page where the booking widget is embedded — emailed links such as password reset open this page)</em></span>
          <input type="url" name="widget_page_url" maxlength="255" placeholder="https://example.com/book" value="<?= $val('widget_page_url') ?>">
          <?= $err('widget_page_url') ?>
        </label>

        <input type="hidden" name="status" value="inactive">
        <label class="kfb-field kfb-field--inline">
          <input type="checkbox" name="status" value="active" <?= (!$is_edit || ($editing['status'] ?? 'active') === 'active') ? 'checked' : '' ?>>
          <span>Active (inactive companies' websites are refused by the booking API)</span>
        </label>
      </fieldset>

      <fieldset class="kfb-fieldset">
        <legend>Branding &amp; contact (shown in emails and receipts)</legend>

        <div class="kfb-field kfb-field--full">
          <span>Logo <em>(JPG, PNG or WEBP, max 1 MB)</em></span>
          <?php if (!empty($editing['logo'])): ?>
            <div style="margin:6px 0 8px;display:flex;align-items:center;gap:12px;">
              <img src="<?= base_url(Company_model::LOGO_DIR . $editing['logo']) ?>" alt="Current logo" style="max-height:56px;max-width:200px;border:1px solid var(--kfb-border,#e5e7eb);border-radius:6px;padding:4px;background:#fff;">
              <?php if ($is_edit): ?>
                <button type="submit" form="kfbCompanyRemoveLogo" class="kfb-btn kfb-btn--ghost kfb-btn--sm">Remove logo</button>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
          <?= $err('logo') ?>
        </div>

        <label class="kfb-field">
          <span>Contact number 1</span>
          <input type="text" name="phone_1" maxlength="50" value="<?= $val('phone_1') ?>">
        </label>
        <label class="kfb-field">
          <span>Contact number 2</span>
          <input type="text" name="phone_2" maxlength="50" value="<?= $val('phone_2') ?>">
        </label>

        <label class="kfb-field kfb-field--full">
          <span>Address line 1</span>
          <input type="text" name="address_line_1" maxlength="255" value="<?= $val('address_line_1') ?>">
        </label>
        <label class="kfb-field kfb-field--full">
          <span>Address line 2</span>
          <input type="text" name="address_line_2" maxlength="255" value="<?= $val('address_line_2') ?>">
        </label>

        <label class="kfb-field">
          <span>Contact email <em>(email footer + Reply-To)</em></span>
          <input type="email" name="contact_email" maxlength="150" value="<?= $val('contact_email') ?>">
          <?= $err('contact_email') ?>
        </label>
        <label class="kfb-field">
          <span>Notification email <em>(receives this company's new-reservation emails)</em></span>
          <input type="email" name="notification_email" maxlength="150" value="<?= $val('notification_email') ?>">
          <?= $err('notification_email') ?>
        </label>
      </fieldset>

      <fieldset class="kfb-fieldset">
        <legend>Legacy portal</legend>
        <label class="kfb-field">
          <span>Legacy company ID <em>(<code>company_id</code> in the legacy portal's companies table)</em></span>
          <input type="number" name="legacy_company_id" min="0" step="1" value="<?= $val('legacy_company_id') ?>">
        </label>
      </fieldset>

      <div class="kfb-form-actions kfb-field--full">
        <button type="submit" class="kfb-btn kfb-btn--primary"><?= $is_edit ? 'Save changes' : 'Add company' ?></button>
      </div>
    </form>

    <?php if ($is_edit && !empty($editing['logo'])): ?>
      <form id="kfbCompanyRemoveLogo" action="<?= site_url('admin/companies/' . (int)$editing['id'] . '/remove-logo') ?>" method="post"></form>
    <?php endif; ?>
  </section>
</section>
