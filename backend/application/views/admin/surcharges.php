<?php
$editing    = isset($editing) ? $editing : NULL;
$is_edit    = !empty($editing);
$surcharges = isset($surcharges) ? $surcharges : [];

// Surcharge_model::KNOWN_TRIGGERS is the single source of truth for both
// this dropdown and server-side validation — add a new auto-trigger
// condition there (and the matching branch in
// Pricing_engine::_apply_surcharges()) and it shows up here automatically.
$trigger_labels = Surcharge_model::KNOWN_TRIGGERS;
?>

<section class="kfb-split">
  <!-- Left: surcharge list -->
  <aside class="kfb-card kfb-card--narrow">
    <header class="kfb-card-head">
      <h2>All surcharges</h2>
      <button type="button" id="kfbNewSurcharge" class="kfb-btn kfb-btn--primary kfb-btn--sm">+ New</button>
    </header>

    <?php if (empty($surcharges)): ?>
      <p class="kfb-empty">No surcharges yet — create one with the form on the right.</p>
    <?php else: ?>
      <ul class="kfb-list" id="kfbSurchargeList">
        <?php foreach ($surcharges as $s): ?>
          <li class="kfb-list-item <?= ($is_edit && (int)$editing['id'] === (int)$s['id']) ? 'is-active' : '' ?>"
              data-id="<?= (int)$s['id'] ?>">
            <div class="kfb-list-body">
              <strong><?= htmlspecialchars($s['name']) ?> <small class="kfb-mono"><?= htmlspecialchars($s['code']) ?></small></strong>
              <small>
                <?php
                  $amount = (float)$s['amount'];
                  echo $s['pricing_type'] === 'percent'
                      ? rtrim(rtrim(number_format($amount, 2), '0'), '.') . '%'
                      : '$' . number_format($amount, 2);
                ?>
                <?php if (!empty($s['auto_trigger'])): ?>
                  · Auto: <?= htmlspecialchars($trigger_labels[$s['auto_trigger']] ?? $s['auto_trigger']) ?>
                <?php else: ?>
                  · Manual selection
                <?php endif; ?>
              </small>
            </div>
            <div class="kfb-list-actions">
              <?php if ((int)$s['status'] === 1): ?>
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
      <h2 id="kfbFormTitle"><?= $is_edit ? 'Edit surcharge' : 'Create surcharge' ?></h2>
      <a href="<?= site_url('admin/surcharges') ?>" id="kfbCancelEdit" class="kfb-btn kfb-btn--ghost kfb-btn--sm"
         <?= $is_edit ? '' : 'hidden' ?>>Cancel</a>
    </header>

    <form id="kfbSurchargeForm" class="kfb-form kfb-form--grid"
          action="<?= $is_edit ? site_url('admin/api/surcharges/' . (int)$editing['id'] . '/save') : site_url('admin/api/surcharges/save') ?>"
          method="post"
          onsubmit="return false;"
          target="_self">
      <input type="hidden" name="id" value="<?= $is_edit ? (int)$editing['id'] : '' ?>">

      <!-- General -->
      <fieldset class="kfb-fieldset">
        <legend>General</legend>
        <label class="kfb-field kfb-field--full">
          <span>Code <em>*</em> <small class="kfb-hint">short identifier (e.g. AIRPORT_FEE, TOLL)</small></span>
          <input type="text" name="code" required maxlength="40" pattern="[A-Za-z0-9_\-]+"
                 value="<?= $is_edit ? htmlspecialchars($editing['code']) : '' ?>"
                 style="text-transform: uppercase; font-family: ui-monospace, monospace;">
        </label>
        <label class="kfb-field kfb-field--full">
          <span>Display name <em>*</em></span>
          <input type="text" name="name" required maxlength="120"
                 value="<?= $is_edit ? htmlspecialchars($editing['name']) : '' ?>"
                 placeholder="e.g. Fuel surcharge">
        </label>
        <label class="kfb-field kfb-field--full">
          <span>Description</span>
          <textarea name="description" maxlength="500" rows="2"
                    placeholder="Short customer-facing description"><?= $is_edit ? htmlspecialchars($editing['description']) : '' ?></textarea>
        </label>
        <label class="kfb-field kfb-field--inline">
          <input type="checkbox" name="status" value="1"
                 <?= (!$is_edit || (int)$editing['status'] === 1) ? 'checked' : '' ?>>
          <span>Enabled</span>
        </label>
        <label class="kfb-field kfb-field--full">
          <span>Trigger <small class="kfb-hint">when this surcharge applies automatically — leave as Manual to require explicit selection on a booking</small></span>
          <select name="auto_trigger">
            <option value="" <?= (!$is_edit || empty($editing['auto_trigger'])) ? 'selected' : '' ?>>Manual selection only</option>
            <?php foreach ($trigger_labels as $trigger_key => $trigger_label): ?>
              <option value="<?= htmlspecialchars($trigger_key) ?>"
                <?= ($is_edit && ($editing['auto_trigger'] ?? '') === $trigger_key) ? 'selected' : '' ?>>
                <?= htmlspecialchars($trigger_label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </fieldset>

      <!-- Pricing -->
      <fieldset class="kfb-fieldset">
        <legend>Pricing</legend>
        <label class="kfb-field">
          <span>Type</span>
          <select name="pricing_type">
            <option value="flat"   <?= (!$is_edit || $editing['pricing_type'] === 'flat')   ? 'selected' : '' ?>>Flat amount ($)</option>
            <option value="percent"<?= ($is_edit && $editing['pricing_type'] === 'percent') ? 'selected' : '' ?>>Percent of transportation + travel fee (%)</option>
          </select>
        </label>
        <label class="kfb-field">
          <span>Amount <em>*</em></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="amount" required min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)$editing['amount'], 2, '.', '') : '0.00' ?>">
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
          <?= $is_edit ? 'Save changes' : 'Create surcharge' ?>
        </button>
        <a href="<?= site_url('admin/surcharges') ?>" class="kfb-btn kfb-btn--ghost" id="kfbCancelEditBottom">Cancel</a>
        <?php if ($is_edit): ?>
          <button type="button" class="kfb-btn kfb-btn--danger kfb-btn--sm" id="kfbDeleteSurcharge"
                  data-endpoint="<?= site_url('admin/api/surcharges/' . (int)$editing['id'] . '/delete') ?>">
            Delete
          </button>
        <?php endif; ?>
      </div>

      <div class="kfb-form-errors" id="kfbFormErrors" hidden></div>
    </form>
  </section>
</section>
