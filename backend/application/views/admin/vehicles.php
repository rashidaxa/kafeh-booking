<?php
$editing = isset($editing) ? $editing : NULL;
$is_edit = !empty($editing);
$regions = [
    'chicago'   => 'Inside Chicago',
    'america'   => 'Inside America',
    'worldwide' => 'Worldwide',
];
$rate_groups = [
    'hourly'       => 'Hourly rates',
    'per_km'       => 'Per-kilometer rates',
    'surcharge'    => 'Surcharge amounts',
    'gratuity'     => 'Gratuity amounts',
    'waiting'      => 'Waiting time (per minute)',
    'child_seat'   => 'Child-seat surcharge (flat fee per child seat)',
];
?>

<section class="kfb-split">
  <!-- Left: vehicle list -->
  <aside class="kfb-card kfb-card--narrow">
    <header class="kfb-card-head">
      <h2>All vehicles</h2>
      <button type="button" id="kfbNewVehicle" class="kfb-btn kfb-btn--primary kfb-btn--sm">+ New</button>
    </header>

    <?php if (empty($vehicles)): ?>
      <p class="kfb-empty">No vehicles yet — create one with the form on the right.</p>
    <?php else: ?>
      <ul class="kfb-list" id="kfbVehicleList">
        <?php foreach ($vehicles as $v): ?>
          <li class="kfb-list-item <?= ($is_edit && (int)$editing['id'] === (int)$v['id']) ? 'is-active' : '' ?>"
              data-id="<?= (int)$v['id'] ?>">
            <div class="kfb-list-thumb">
              <?php if (!empty($v['image'])): ?>
                <img src="<?= base_url('uploads/vehicles/' . $v['image']) ?>" alt="">
              <?php else: ?>
                <span><?= htmlspecialchars($v['emoji'] ?: '🚖') ?></span>
              <?php endif; ?>
            </div>
            <div class="kfb-list-body">
              <strong><?= htmlspecialchars($v['name']) ?></strong>
              <small>
                <?= (int)$v['min_passengers'] ?>–<?= (int)$v['max_passengers'] ?> pax
                · $<?= number_format((float)$v['hourly_chicago'], 2) ?>/hr
              </small>
            </div>
            <div class="kfb-list-actions">
              <?php if ((int)$v['status'] === 1): ?>
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
      <h2 id="kfbFormTitle"><?= $is_edit ? 'Edit vehicle' : 'Create vehicle' ?></h2>
      <a href="<?= site_url('admin/vehicles') ?>" id="kfbCancelEdit" class="kfb-btn kfb-btn--ghost kfb-btn--sm"
         <?= $is_edit ? '' : 'hidden' ?>>Cancel</a>
    </header>

    <form id="kfbVehicleForm" class="kfb-form kfb-form--grid"
          action="<?= $is_edit ? site_url('admin/api/vehicles/' . (int)$editing['id'] . '/save') : site_url('admin/api/vehicles/save') ?>"
          method="post" enctype="multipart/form-data">

      <input type="hidden" name="id" value="<?= $is_edit ? (int)$editing['id'] : '' ?>">

      <!-- ============ General ============ -->
      <fieldset class="kfb-fieldset">
        <legend>General settings</legend>

        <label class="kfb-field kfb-field--full">
          <span>Display name <em>*</em></span>
          <input type="text" name="name" required maxlength="100"
                 value="<?= $is_edit ? htmlspecialchars($editing['name']) : '' ?>">
        </label>

        <label class="kfb-field">
          <span>Code <em>(slug used by widget, e.g. <code>sedan</code>)</em></span>
          <input type="text" name="code" maxlength="50" pattern="[A-Za-z0-9_\-]+"
                 value="<?= $is_edit ? htmlspecialchars($editing['code']) : '' ?>">
        </label>

        <label class="kfb-field">
          <span>Emoji / icon</span>
          <input type="text" name="emoji" maxlength="16"
                 value="<?= $is_edit ? htmlspecialchars($editing['emoji']) : '' ?>"
                 placeholder="🚘">
        </label>

        <label class="kfb-field kfb-field--full">
          <span>Description</span>
          <textarea name="description" maxlength="500" rows="2"
                    placeholder="Mercedes E-Class / BMW 5"><?= $is_edit ? htmlspecialchars($editing['description']) : '' ?></textarea>
        </label>

        <label class="kfb-field kfb-field--inline">
          <input type="checkbox" name="status" value="1"
                 <?= (!$is_edit || (int)$editing['status'] === 1) ? 'checked' : '' ?>>
          <span>Enable this vehicle (show in booking widget)</span>
        </label>

        <label class="kfb-field">
          <span>Sort order</span>
          <input type="number" name="sort_order" min="0" step="1"
                 value="<?= $is_edit ? (int)$editing['sort_order'] : '0' ?>">
        </label>
      </fieldset>

      <!-- ============ Passenger limits ============ -->
      <fieldset class="kfb-fieldset">
        <legend>Passenger limits</legend>
        <label class="kfb-field">
          <span>Minimum passengers <em>*</em></span>
          <input type="number" name="min_passengers" required min="1" max="99" step="1"
                 value="<?= $is_edit ? (int)$editing['min_passengers'] : '1' ?>">
        </label>
        <label class="kfb-field">
          <span>Maximum passengers <em>*</em></span>
          <input type="number" name="max_passengers" required min="1" max="99" step="1"
                 value="<?= $is_edit ? (int)$editing['max_passengers'] : '3' ?>">
        </label>
        <label class="kfb-field">
          <span>Luggage capacity</span>
          <input type="number" name="luggage_capacity" min="0" max="99" step="1"
                 value="<?= $is_edit && $editing['luggage_capacity'] !== NULL ? (int)$editing['luggage_capacity'] : '' ?>">
        </label>
      </fieldset>

      <!-- ============ Rates by region ============ -->
      <?php foreach ($rate_groups as $group_key => $group_label): ?>
        <fieldset class="kfb-fieldset">
          <legend><?= htmlspecialchars($group_label) ?></legend>
          <?php foreach ($regions as $region_key => $region_label): ?>
            <?php
              $field = $group_key . '_' . $region_key;
              $val = $is_edit ? (float)($editing[$field] ?? 0) : 0;
            ?>
            <label class="kfb-field">
              <span><?= htmlspecialchars($region_label) ?></span>
              <div class="kfb-money">
                <span class="kfb-money-prefix">$</span>
                <input type="number" name="<?= $field ?>" min="0" step="0.01"
                       value="<?= number_format($val, 2, '.', '') ?>">
              </div>
            </label>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>

      <!-- ============ Minimum fare + Meet & Greet (v4) ============ -->
      <fieldset class="kfb-fieldset">
        <legend>Minimum fare &amp; Meet &amp; Greet</legend>
        <label class="kfb-field">
          <span>Minimum fare <small class="kfb-hint">USD — bill at least this regardless of distance</small></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="min_fare" min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)($editing['min_fare'] ?? 0), 2, '.', '') : '0.00' ?>">
          </div>
        </label>
        <label class="kfb-field">
          <span>Meet &amp; Greet — Chicago <small class="kfb-hint">USD flat fee</small></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="meet_greet_chicago" min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)($editing['meet_greet_chicago'] ?? 65), 2, '.', '') : '65.00' ?>">
          </div>
        </label>
        <label class="kfb-field">
          <span>Meet &amp; Greet — elsewhere <small class="kfb-hint">USD flat fee</small></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="meet_greet_elsewhere" min="0" step="0.01"
                   value="<?= $is_edit ? number_format((float)($editing['meet_greet_elsewhere'] ?? 95), 2, '.', '') : '95.00' ?>">
          </div>
        </label>
      </fieldset>

      <!-- ============ Image ============ -->
      <fieldset class="kfb-fieldset">
        <legend>Image</legend>

        <div class="kfb-image-row">
          <div class="kfb-image-preview" id="kfbImagePreview">
            <?php if ($is_edit && !empty($editing['image'])): ?>
              <img src="<?= base_url('uploads/vehicles/' . $editing['image']) ?>" alt="Current vehicle image">
            <?php else: ?>
              <span class="kfb-image-placeholder">No image yet</span>
            <?php endif; ?>
          </div>
          <div class="kfb-image-controls">
            <label class="kfb-btn kfb-btn--ghost">
              <input type="file" name="image" id="kfbImageInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" hidden>
              Choose image…
            </label>
            <small class="kfb-hint">JPG, JPEG, PNG, WEBP · max 5 MB</small>
            <?php if ($is_edit && !empty($editing['image'])): ?>
              <label class="kfb-image-remove">
                <input type="checkbox" name="remove_image" value="1">
                Remove current image
              </label>
            <?php endif; ?>
          </div>
        </div>
      </fieldset>

      <div class="kfb-form-actions">
        <button type="submit" class="kfb-btn kfb-btn--primary">
          <?= $is_edit ? 'Save changes' : 'Create vehicle' ?>
        </button>
        <a href="<?= site_url('admin/vehicles') ?>" class="kfb-btn kfb-btn--ghost" id="kfbCancelEditBottom">Cancel</a>
      </div>

      <div class="kfb-form-errors" id="kfbFormErrors" hidden></div>
    </form>
  </section>
</section>