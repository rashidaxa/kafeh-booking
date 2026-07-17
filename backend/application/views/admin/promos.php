<?php
$editing = isset($editing) ? $editing : NULL;
$is_edit = !empty($editing);
$promos  = isset($promos) ? $promos : [];
?>

<section class="kfb-split">
  <!-- Left: promo list -->
  <aside class="kfb-card kfb-card--narrow">
    <header class="kfb-card-head">
      <h2>All promo codes</h2>
      <button type="button" id="kfbNewPromo" class="kfb-btn kfb-btn--primary kfb-btn--sm">+ New</button>
    </header>

    <?php if (empty($promos)): ?>
      <p class="kfb-empty">No promo codes yet — create one with the form on the right.</p>
    <?php else: ?>
      <ul class="kfb-list" id="kfbPromoList">
        <?php foreach ($promos as $p): ?>
          <?php
            $isExpired = !empty($p['expires_at']) && $p['expires_at'] !== '0000-00-00' && $p['expires_at'] < date('Y-m-d');
            $isExhausted = (int)$p['max_uses'] > 0 && (int)$p['used_count'] >= (int)$p['max_uses'];
            $valueLabel = $p['discount_type'] === 'percent'
                          ? rtrim(rtrim(number_format((float)$p['discount_value'], 2), '0'), '.') . '% off'
                          : '$' . number_format((float)$p['discount_value'], 2) . ' off';
          ?>
          <li class="kfb-list-item <?= ($is_edit && (int)$editing['id'] === (int)$p['id']) ? 'is-active' : '' ?>"
              data-id="<?= (int)$p['id'] ?>">
            <div class="kfb-list-body">
              <strong class="kfb-mono"><?= htmlspecialchars($p['code']) ?></strong>
              <small>
                <?= htmlspecialchars($valueLabel) ?>
                <?php if ((int)$p['max_uses'] > 0): ?>
                  · <?= (int)$p['used_count'] ?>/<?= (int)$p['max_uses'] ?> used
                <?php else: ?>
                  · <?= (int)$p['used_count'] ?> used
                <?php endif; ?>
                <?php if ($isExpired): ?> · <span class="kfb-text-warn">expired</span><?php endif; ?>
                <?php if ($isExhausted): ?> · <span class="kfb-text-warn">exhausted</span><?php endif; ?>
              </small>
            </div>
            <div class="kfb-list-actions">
              <?php if ((int)$p['status'] === 1): ?>
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
      <h2 id="kfbFormTitle"><?= $is_edit ? 'Edit promo code' : 'Create promo code' ?></h2>
      <a href="<?= site_url('admin/promos') ?>" id="kfbCancelEdit" class="kfb-btn kfb-btn--ghost kfb-btn--sm"
         <?= $is_edit ? '' : 'hidden' ?>>Cancel</a>
    </header>

    <form id="kfbPromoForm" class="kfb-form kfb-form--grid"
          action="<?= $is_edit ? site_url('admin/api/promos/' . (int)$editing['id'] . '/save') : site_url('admin/api/promos/save') ?>"
          method="post">

      <input type="hidden" name="id" value="<?= $is_edit ? (int)$editing['id'] : '' ?>">

      <!-- ============ General ============ -->
      <fieldset class="kfb-fieldset">
        <legend>General</legend>

        <label class="kfb-field kfb-field--full">
          <span>Code <em>*</em> <small class="kfb-hint">customers enter this at checkout</small></span>
          <input type="text" name="code" required maxlength="40" pattern="[A-Za-z0-9_\-]+"
                 placeholder="e.g. KAFE10"
                 value="<?= $is_edit ? htmlspecialchars($editing['code']) : '' ?>"
                 style="text-transform: uppercase; font-family: ui-monospace, monospace;">
        </label>

        <label class="kfb-field kfb-field--full">
          <span>Internal description</span>
          <input type="text" name="description" maxlength="255"
                 placeholder="Spring 2026 launch — 10% off any vehicle"
                 value="<?= $is_edit ? htmlspecialchars($editing['description']) : '' ?>">
        </label>

        <label class="kfb-field kfb-field--inline">
          <input type="checkbox" name="status" value="1"
                 <?= (!$is_edit || (int)$editing['status'] === 1) ? 'checked' : '' ?>>
          <span>Enabled (customers can use this code)</span>
        </label>
      </fieldset>

      <!-- ============ Discount ============ -->
      <fieldset class="kfb-fieldset">
        <legend>Discount</legend>

        <label class="kfb-field">
          <span>Type</span>
          <select name="discount_type">
            <option value="percent" <?= (!$is_edit || $editing['discount_type'] === 'percent') ? 'selected' : '' ?>>Percent (%)</option>
            <option value="fixed"   <?= ($is_edit && $editing['discount_type'] === 'fixed')   ? 'selected' : '' ?>>Fixed amount ($)</option>
          </select>
        </label>

        <label class="kfb-field">
          <span>Value <em>*</em></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix kfb-money-suffix" id="kfbDiscountUnit">%</span>
            <input type="number" name="discount_value" required min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)$editing['discount_value'], 2, '.', '') : '10.00' ?>">
          </div>
        </label>

        <label class="kfb-field">
          <span>Min. subtotal <small class="kfb-hint">customer subtotal must reach this</small></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="min_amount" min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)$editing['min_amount'], 2, '.', '') : '0.00' ?>">
          </div>
        </label>
      </fieldset>

      <!-- ============ Usage window ============ -->
      <fieldset class="kfb-fieldset">
        <legend>Usage window</legend>

        <label class="kfb-field">
          <span>Start date <small class="kfb-hint">optional</small></span>
          <input type="date" name="starts_at"
                 value="<?= $is_edit && !empty($editing['starts_at']) ? htmlspecialchars($editing['starts_at']) : '' ?>">
        </label>

        <label class="kfb-field">
          <span>Expiry date <small class="kfb-hint">optional</small></span>
          <input type="date" name="expires_at"
                 value="<?= $is_edit && !empty($editing['expires_at']) && $editing['expires_at'] !== '0000-00-00' ? htmlspecialchars($editing['expires_at']) : '' ?>">
        </label>

        <label class="kfb-field">
          <span>Max uses <small class="kfb-hint">0 = unlimited</small></span>
          <input type="number" name="max_uses" min="0" step="1"
                 value="<?= $is_edit ? (int)$editing['max_uses'] : '0' ?>">
        </label>
      </fieldset>

      <div class="kfb-form-actions">
        <button type="submit" class="kfb-btn kfb-btn--primary">
          <?= $is_edit ? 'Save changes' : 'Create promo code' ?>
        </button>
        <a href="<?= site_url('admin/promos') ?>" class="kfb-btn kfb-btn--ghost" id="kfbCancelEditBottom">Cancel</a>
        <?php if ($is_edit): ?>
          <button type="button" class="kfb-btn kfb-btn--danger kfb-btn--sm" id="kfbDeletePromo"
                  data-endpoint="<?= site_url('admin/api/promos/' . (int)$editing['id'] . '/delete') ?>">
            Delete
          </button>
        <?php endif; ?>
      </div>

      <div class="kfb-form-errors" id="kfbFormErrors" hidden></div>
    </form>
  </section>
</section>
