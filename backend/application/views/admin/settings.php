<?php
$settings = isset($settings) ? $settings : ['meet_greet_chicago' => 65, 'meet_greet_elsewhere' => 95];
?>

<section class="kfb-split">
  <section class="kfb-card">
    <header class="kfb-card-head">
      <h2>Meet &amp; Greet fee</h2>
    </header>
    <p class="kfb-hint" style="margin: -4px 0 16px;">
      Applied once per booking when the customer picks <strong>Meet &amp; Greet (Inside)</strong> as their
      pickup or drop-off point at an airport. This fee is global — it no longer varies by vehicle.
    </p>

    <form id="kfbSettingsForm" class="kfb-form kfb-form--grid"
          action="<?= site_url('admin/api/settings/save') ?>"
          method="post"
          onsubmit="return false;">
      <fieldset class="kfb-fieldset">
        <legend>Pricing</legend>
        <label class="kfb-field">
          <span>Chicago airports <em>*</em> <small class="kfb-hint">O'Hare, Midway</small></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="meet_greet_chicago" required min="0" step="0.01"
                   value="<?= number_format((float)($settings['meet_greet_chicago'] ?? 65), 2, '.', '') ?>">
          </div>
        </label>
        <label class="kfb-field">
          <span>All other airports <em>*</em></span>
          <div class="kfb-money">
            <span class="kfb-money-prefix">$</span>
            <input type="number" name="meet_greet_elsewhere" required min="0" step="0.01"
                   value="<?= number_format((float)($settings['meet_greet_elsewhere'] ?? 95), 2, '.', '') ?>">
          </div>
        </label>
      </fieldset>

      <div class="kfb-form-actions">
        <button type="submit" class="kfb-btn kfb-btn--primary">Save changes</button>
        <span class="kfb-form-saved" id="kfbSettingsSaved" hidden>Saved.</span>
      </div>

      <div class="kfb-form-errors" id="kfbFormErrors" hidden></div>
    </form>
  </section>
</section>
