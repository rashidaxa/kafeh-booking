<?php
$editing = isset($editing) ? $editing : NULL;
$is_edit = !empty($editing);
$addons  = isset($addons) ? $addons : [];
$categories = ['Red Carpet', 'Floral & Balloon', 'Beverage', 'Decor', 'Wedding', 'Other'];
?>

<section class="kfb-split">
  <!-- Left: add-on list -->
  <aside class="kfb-card kfb-card--narrow">
    <header class="kfb-card-head">
      <h2>All add-ons</h2>
      <button type="button" id="kfbNewAddon" class="kfb-btn kfb-btn--primary kfb-btn--sm">+ New</button>
    </header>

    <?php if (empty($addons)): ?>
      <p class="kfb-empty">No add-ons yet — create one with the form on the right.</p>
    <?php else: ?>
      <ul class="kfb-list" id="kfbAddonList">
        <?php foreach ($addons as $a): ?>
          <li class="kfb-list-item <?= ($is_edit && (int)$editing['id'] === (int)$a['id']) ? 'is-active' : '' ?>"
              data-id="<?= (int)$a['id'] ?>">
            <div class="kfb-list-body">
              <strong><?= htmlspecialchars($a['name']) ?> <small class="kfb-mono"><?= htmlspecialchars($a['code']) ?></small></strong>
              <small>
                <?php
                  $ch = (float)$a['price_chicago'];
                  $am = (float)$a['price_america'];
                  $ww = (float)$a['price_worldwide'];
                  $symbol = $a['pricing_type'] === 'percent' ? '%' : '$';
                  if ($a['pricing_type'] === 'percent') {
                    echo rtrim(rtrim(number_format($ch, 2), '0'), '.') . '% / '
                       . rtrim(rtrim(number_format($am, 2), '0'), '.') . '% / '
                       . rtrim(rtrim(number_format($ww, 2), '0'), '.') . '%';
                  } else {
                    echo '$' . number_format($ch, 2) . ' / $' . number_format($am, 2) . ' / $' . number_format($ww, 2);
                  }
                ?>
                · <?= htmlspecialchars($a['category'] ?? '—') ?>
              </small>
            </div>
            <div class="kfb-list-actions">
              <?php if ((int)$a['status'] === 1): ?>
                <span class="kfb-badge kfb-badge--ok">On</span>
              <?php else: ?>
                <span class="kfb-badge kfb-badge--off">Off</span>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>

  <!-- Right: edit form -->
  <section class="kfb-card">
    <header class="kfb-card-head">
      <h2 id="kfbFormTitle"><?= $is_edit ? 'Edit add-on' : 'Create add-on' ?></h2>
      <a href="<?= site_url('admin/addons') ?>" id="kfbCancelEdit" class="kfb-btn kfb-btn--ghost kfb-btn--sm"
         <?= $is_edit ? '' : 'hidden' ?>>Cancel</a>
    </header>

    <form id="kfbAddonForm" class="kfb-form kfb-form--grid"
          action="<?= $is_edit ? site_url('admin/api/addons/' . (int)$editing['id'] . '/save') : site_url('admin/api/addons/save') ?>"
          method="post"
          onsubmit="return false;"
          target="_self">
      <input type="hidden" name="id" value="<?= $is_edit ? (int)$editing['id'] : '' ?>">

      <!-- General -->
      <fieldset class="kfb-fieldset">
        <legend>General</legend>
        <label class="kfb-field kfb-field--full">
          <span>Code <em>*</em> <small class="kfb-hint">short identifier (e.g. REDCARPET, CHAMPAGNE)</small></span>
          <input type="text" name="code" required maxlength="40" pattern="[A-Za-z0-9_\-]+"
                 value="<?= $is_edit ? htmlspecialchars($editing['code']) : '' ?>"
                 style="text-transform: uppercase; font-family: ui-monospace, monospace;">
        </label>
        <label class="kfb-field kfb-field--full">
          <span>Display name <em>*</em></span>
          <input type="text" name="name" required maxlength="120"
                 value="<?= $is_edit ? htmlspecialchars($editing['name']) : '' ?>"
                 placeholder="e.g. Red Carpet Service">
        </label>
        <label class="kfb-field kfb-field--full">
          <span>Description</span>
          <textarea name="description" maxlength="500" rows="2"
                    placeholder="Short customer-facing description"><?= $is_edit ? htmlspecialchars($editing['description']) : '' ?></textarea>
        </label>
        <label class="kfb-field">
          <span>Category</span>
          <select name="category">
            <?php foreach ($categories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>"
                <?= ($is_edit && ($editing['category'] ?? '') === $cat) ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="kfb-field kfb-field--inline">
          <input type="checkbox" name="status" value="1"
                 <?= (!$is_edit || (int)$editing['status'] === 1) ? 'checked' : '' ?>>
          <span>Enabled (customers can add this to their booking)</span>
        </label>
      </fieldset>

      <!-- Pricing -->
      <fieldset class="kfb-fieldset">
        <legend>Pricing (per region)</legend>
        <label class="kfb-field">
          <span>Type</span>
          <select name="pricing_type">
            <option value="flat"   <?= (!$is_edit || $editing['pricing_type'] === 'flat')   ? 'selected' : '' ?>>Flat amount ($)</option>
            <option value="percent"<?= ($is_edit && $editing['pricing_type'] === 'percent') ? 'selected' : '' ?>>Percent of subtotal (%)</option>
          </select>
        </label>
        <label class="kfb-field">
          <span>Inside Chicago <em>*</em></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="price_chicago" required min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)$editing['price_chicago'], 2, '.', '') : '0.00' ?>">
          </div>
        </label>
        <label class="kfb-field">
          <span>Inside America <em>*</em></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="price_america" required min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)$editing['price_america'], 2, '.', '') : '0.00' ?>">
          </div>
        </label>
        <label class="kfb-field">
          <span>Worldwide <em>*</em></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="price_worldwide" required min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)$editing['price_worldwide'], 2, '.', '') : '0.00' ?>">
          </div>
        </label>
        <label class="kfb-field">
          <span>Sort order</span>
          <input type="number" name="sort_order" min="0" step="1"
                 value="<?= $is_edit ? (int)$editing['sort_order'] : '0' ?>">
        </label>
      </fieldset>

      <div class="kfb-form-actions">
        <button type="submit" class="kfb-btn kfb-btn--primary">
          <?= $is_edit ? 'Save changes' : 'Create add-on' ?>
        </button>
        <a href="<?= site_url('admin/addons') ?>" class="kfb-btn kfb-btn--ghost" id="kfbCancelEditBottom">Cancel</a>
        <?php if ($is_edit): ?>
          <button type="button" class="kfb-btn kfb-btn--danger kfb-btn--sm" id="kfbDeleteAddon"
                  data-endpoint="<?= site_url('admin/api/addons/' . (int)$editing['id'] . '/delete') ?>">
            Delete
          </button>
        <?php endif; ?>
      </div>

      <div class="kfb-form-errors" id="kfbFormErrors" hidden></div>
    </form>
  </section>
</section>
