<?php
$settings = isset($settings) ? $settings : [];
$g = function ($key, $default) use ($settings) {
    return (float)($settings[$key] ?? $default);
};
?>

<section class="kfb-card" style="max-width: 720px;">
  <header class="kfb-card-head">
    <h2>Pricing Settings</h2>
  </header>
  <p class="kfb-hint" style="margin: -4px 0 16px;">
    These knobs drive every trip's price alongside each vehicle's own local rate
    (see <a href="<?= site_url('admin/vehicles') ?>">Vehicles</a>) — nothing here is hard-coded in the booking engine.
  </p>

  <form id="kfbSettingsForm" class="kfb-form kfb-form--grid"
        action="<?= site_url('admin/api/settings/save') ?>"
        method="post"
        onsubmit="return false;">

    <fieldset class="kfb-fieldset">
      <legend>Service zones</legend>
      <label class="kfb-field">
        <span>Local service radius <em>*</em> <small class="kfb-hint">miles from the garage</small></span>
        <div class="kfb-money">
          <input type="number" name="pricing_local_service_radius_miles" required min="0" step="0.01"
                 value="<?= number_format($g('pricing_local_service_radius_miles', 75), 2, '.', '') ?>">
          <span class="kfb-money-suffix">mi</span>
        </div>
      </label>
      <label class="kfb-field">
        <span>Regional travel fee <em>*</em> <small class="kfb-hint">per mile beyond the local radius</small></span>
        <div class="kfb-money">
          <span class="kfb-money-prefix">$</span>
          <input type="number" name="pricing_regional_travel_fee_per_mile" required min="0" step="0.01"
                 value="<?= number_format($g('pricing_regional_travel_fee_per_mile', 1.50), 2, '.', '') ?>">
          <span class="kfb-money-suffix">/mi</span>
        </div>
      </label>
      <label class="kfb-field">
        <span>Long-distance multiplier <em>*</em> <small class="kfb-hint">× a vehicle's local rate, for out-of-state trips</small></span>
        <input type="number" name="pricing_long_distance_multiplier" required min="0" step="0.01"
               value="<?= number_format($g('pricing_long_distance_multiplier', 3), 2, '.', '') ?>">
      </label>
      <label class="kfb-field">
        <span>Worldwide multiplier <em>*</em> <small class="kfb-hint">× a vehicle's local rate, for international trips</small></span>
        <input type="number" name="pricing_worldwide_multiplier" required min="0" step="0.01"
               value="<?= number_format($g('pricing_worldwide_multiplier', 3), 2, '.', '') ?>">
      </label>
      <label class="kfb-field">
        <span>Worldwide auto-quote distance limit <em>*</em> <small class="kfb-hint">beyond this, customers see "Request a Quote" instead of a price</small></span>
        <div class="kfb-money">
          <input type="number" name="pricing_worldwide_quote_threshold_miles" required min="0" step="1"
                 value="<?= number_format($g('pricing_worldwide_quote_threshold_miles', 5000), 2, '.', '') ?>">
          <span class="kfb-money-suffix">mi</span>
        </div>
      </label>
    </fieldset>

    <fieldset class="kfb-fieldset">
      <legend>Hourly minimums</legend>
      <label class="kfb-field">
        <span>Local <em>*</em></span>
        <div class="kfb-money">
          <input type="number" name="pricing_local_hourly_minimum_hours" required min="0" step="0.5"
                 value="<?= number_format($g('pricing_local_hourly_minimum_hours', 4), 2, '.', '') ?>">
          <span class="kfb-money-suffix">hrs</span>
        </div>
      </label>
      <label class="kfb-field">
        <span>Regional (beyond the local radius) <em>*</em></span>
        <div class="kfb-money">
          <input type="number" name="pricing_regional_hourly_minimum_hours" required min="0" step="0.5"
                 value="<?= number_format($g('pricing_regional_hourly_minimum_hours', 5), 2, '.', '') ?>">
          <span class="kfb-money-suffix">hrs</span>
        </div>
      </label>
      <label class="kfb-field">
        <span>Worldwide <em>*</em></span>
        <div class="kfb-money">
          <input type="number" name="pricing_worldwide_hourly_minimum_hours" required min="0" step="0.5"
                 value="<?= number_format($g('pricing_worldwide_hourly_minimum_hours', 5), 2, '.', '') ?>">
          <span class="kfb-money-suffix">hrs</span>
        </div>
      </label>
      <p class="kfb-hint kfb-field--full">
        Each vehicle can also set its own local hourly minimum on the
        <a href="<?= site_url('admin/vehicles') ?>">Vehicles</a> page, overriding the local default above.
      </p>
    </fieldset>

    <fieldset class="kfb-fieldset">
      <legend>Gratuity</legend>
      <label class="kfb-field">
        <span>Default gratuity <em>*</em> <small class="kfb-hint">applied to every trip's transportation + travel fee</small></span>
        <div class="kfb-money">
          <input type="number" name="pricing_default_gratuity_pct" required min="0" max="100" step="0.01"
                 value="<?= number_format($g('pricing_default_gratuity_pct', 20), 2, '.', '') ?>">
          <span class="kfb-money-suffix">%</span>
        </div>
      </label>
    </fieldset>

    <fieldset class="kfb-fieldset">
      <legend>Garage location</legend>
      <label class="kfb-field">
        <span>Latitude <em>*</em></span>
        <input type="number" name="pricing_garage_lat" required step="0.00000001"
               value="<?= number_format($g('pricing_garage_lat', 41.98429380078823), 8, '.', '') ?>">
      </label>
      <label class="kfb-field">
        <span>Longitude <em>*</em></span>
        <input type="number" name="pricing_garage_lng" required step="0.00000001"
               value="<?= number_format($g('pricing_garage_lng', -87.9099405503354), 8, '.', '') ?>">
      </label>
      <p class="kfb-hint kfb-field--full">
        Used to measure every trip's distance from base for the local/regional zone check —
        only change this if the company garage physically relocates.
      </p>
    </fieldset>

    <p class="kfb-hint" style="margin: 0 0 8px;">
      Airport fee, fuel surcharge, tolls, Meet &amp; Greet, and other line-item fees are
      managed on the <a href="<?= site_url('admin/surcharges') ?>">Surcharges</a> page.
    </p>

    <div class="kfb-form-actions">
      <button type="submit" class="kfb-btn kfb-btn--primary">Save changes</button>
      <span class="kfb-form-saved" id="kfbSettingsSaved" hidden>Saved.</span>
    </div>

    <div class="kfb-form-errors" id="kfbFormErrors" hidden></div>
  </form>
</section>
