<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer-facing receipt — a self-contained, print-ready HTML document
 * (not wrapped in the admin layout partials). Rendered by
 * Api::customer_receipt() and opened by the widget via a Blob URL (see
 * embed/booking.js downloadReceipt()), never linked to directly — this
 * URL requires the same bearer-token auth as every other customer
 * endpoint, which a plain browser navigation can't provide.
 *
 * Deliberately excludes anything the admin reservation-detail view shows
 * that shouldn't appear on a customer receipt: raw card number/CVV
 * (kfb_bookings.cardNumber/cvv — only card_brand/card_last4 are used
 * here, same masked shape Customer_model exposes for saved cards),
 * admin accept/reject actions, and internal notes.
 */

$b = isset($booking) ? $booking : [];

if (!function_exists('kfb_r_or_dash')) {
    function kfb_r_or_dash($v) { return ($v === NULL || $v === '' ) ? '—' : htmlspecialchars((string)$v); }
}
if (!function_exists('kfb_r_money')) {
    function kfb_r_money($v) { return '$' . number_format((float)($v ?? 0), 2); }
}
if (!function_exists('kfb_r_date')) {
    function kfb_r_date($d) {
        if (!$d) return '—';
        $t = strtotime((string)$d);
        return $t ? date('M j, Y', $t) : htmlspecialchars((string)$d);
    }
}
if (!function_exists('kfb_r_time')) {
    function kfb_r_time($t) {
        if (!$t) return '';
        $ts = strtotime((string)$t);
        return $ts ? date('g:i A', $ts) : '';
    }
}
if (!function_exists('kfb_r_status_label')) {
    function kfb_r_status_label($status) {
        $labels = [
            'paid' => 'Paid', 'awaiting_approval' => 'Awaiting approval', 'awaiting_payment' => 'Awaiting payment',
            'pending' => 'Pending', 'cancelled' => 'Cancelled', 'payment_failed' => 'Payment failed', 'refunded' => 'Refunded',
        ];
        return $labels[$status] ?? ucfirst(str_replace('_', ' ', (string)$status));
    }
}

$hasPromo = (float)($b['discount_amount'] ?? 0) > 0;
$hasAddons = (float)($b['addons_total'] ?? 0) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt — <?= kfb_r_or_dash($b['booking_id'] ?? NULL) ?></title>
<style>
  * { box-sizing: border-box; }
  body {
    font: 14px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    color: #1a1a1a;
    background: #f4f4f5;
    margin: 0;
    padding: 32px 16px;
  }
  .kfbr-sheet {
    max-width: 720px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid #e2e2e5;
    border-radius: 10px;
    padding: 32px 36px 28px;
  }
  .kfbr-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    border-bottom: 2px solid #111;
    padding-bottom: 16px;
    margin-bottom: 20px;
  }
  .kfbr-brand { font-size: 20px; font-weight: 700; letter-spacing: .2px; }
  .kfbr-brand small { display: block; font-size: 12px; font-weight: 400; color: #6b6b70; margin-top: 2px; }
  .kfbr-head-right { text-align: right; }
  .kfbr-title { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: #6b6b70; }
  .kfbr-booking-id { font-family: ui-monospace, Consolas, Monaco, monospace; font-size: 15px; font-weight: 600; margin-top: 2px; }
  .kfbr-badge {
    display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 999px;
    font-size: 12px; font-weight: 600;
  }
  .kfbr-badge--ok { background: #e6f6ec; color: #0a7a35; }
  .kfbr-badge--warn { background: #fff6e0; color: #8a6100; }
  .kfbr-badge--danger { background: #fdecec; color: #a41616; }
  .kfbr-badge--off { background: #eee; color: #555; }
  .kfbr-section { margin: 22px 0; }
  .kfbr-section h2 {
    font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #6b6b70;
    margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid #eee;
  }
  .kfbr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; }
  .kfbr-grid--full { grid-template-columns: 1fr; }
  .kfbr-field span { display: block; font-size: 11px; color: #8a8a90; }
  .kfbr-field p { margin: 2px 0 0; }
  .kfbr-totals { margin-top: 4px; }
  .kfbr-totals .kfbr-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
  .kfbr-totals .kfbr-row--total { border-top: 2px solid #111; margin-top: 6px; padding-top: 10px; font-size: 16px; font-weight: 700; }
  .kfbr-foot { margin-top: 26px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11.5px; color: #8a8a90; text-align: center; }
  .kfbr-print-bar { max-width: 720px; margin: 0 auto 14px; text-align: right; }
  .kfbr-print-btn {
    font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
    background: #111; color: #fff; border: 0; border-radius: 6px; padding: 8px 16px;
  }
  @media print {
    body { background: #fff; padding: 0; }
    .kfbr-sheet { border: 0; border-radius: 0; max-width: none; padding: 0; }
    .no-print { display: none !important; }
  }
</style>
</head>
<body>

  <div class="kfbr-print-bar no-print">
    <button type="button" class="kfbr-print-btn" onclick="window.print()">Print / Save as PDF</button>
  </div>

  <div class="kfbr-sheet">
    <div class="kfbr-head">
      <div class="kfbr-brand">A1 Limousine<small>Worldwide Chauffeur Services · Bus &amp; Party Bus Rental</small></div>
      <div class="kfbr-head-right">
        <div class="kfbr-title">Receipt</div>
        <div class="kfbr-booking-id"><?= kfb_r_or_dash($b['booking_id'] ?? NULL) ?></div>
        <?php
          $status = $b['status'] ?? '';
          $badgeClass = in_array($status, ['paid'], TRUE) ? 'ok'
                      : (in_array($status, ['awaiting_approval', 'awaiting_payment', 'pending'], TRUE) ? 'warn'
                      : (in_array($status, ['cancelled', 'payment_failed'], TRUE) ? 'danger' : 'off'));
        ?>
        <span class="kfbr-badge kfbr-badge--<?= $badgeClass ?>"><?= htmlspecialchars(kfb_r_status_label($status)) ?></span>
      </div>
    </div>

    <div class="kfbr-section">
      <h2>Billed to</h2>
      <div class="kfbr-grid">
        <div class="kfbr-field"><span>Name</span><p><?= kfb_r_or_dash(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: NULL) ?></p></div>
        <div class="kfbr-field"><span>Email</span><p><?= kfb_r_or_dash($b['email'] ?? NULL) ?></p></div>
        <div class="kfbr-field"><span>Phone</span><p><?= kfb_r_or_dash($b['phone'] ?? NULL) ?></p></div>
        <div class="kfbr-field"><span>Booked on</span><p><?= kfb_r_date($b['created_at'] ?? NULL) ?></p></div>
      </div>
    </div>

    <div class="kfbr-section">
      <h2>Trip</h2>
      <div class="kfbr-grid">
        <div class="kfbr-field"><span>Service</span><p><?= kfb_r_or_dash($b['service_type'] ?? NULL) ?></p></div>
        <div class="kfbr-field"><span>Vehicle</span><p><?= kfb_r_or_dash($b['vehicle_name'] ?? NULL) ?></p></div>
        <div class="kfbr-field"><span>Pickup date &amp; time</span><p><?= kfb_r_date($b['pickup_date'] ?? NULL) ?><?php $t = kfb_r_time($b['pickup_time'] ?? NULL); if ($t) echo ' at ' . htmlspecialchars($t); ?></p></div>
        <div class="kfbr-field"><span>Passengers / Luggage</span><p><?= (int)($b['passengers'] ?? 0) ?> / <?= (int)($b['luggage'] ?? 0) ?></p></div>
        <div class="kfbr-field kfbr-grid--full"><span>Pickup</span><p><?= kfb_r_or_dash($b['pickup'] ?? NULL) ?></p></div>
        <div class="kfbr-field kfbr-grid--full"><span>Drop-off</span><p><?= kfb_r_or_dash($b['dropoff'] ?? NULL) ?></p></div>
        <?php if (!empty($b['is_return_trip'])): ?>
          <div class="kfbr-field"><span>Return date &amp; time</span><p><?= kfb_r_date($b['return_date'] ?? NULL) ?><?php $rt = kfb_r_time($b['return_time'] ?? NULL); if ($rt) echo ' at ' . htmlspecialchars($rt); ?></p></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="kfbr-section">
      <h2>Charges</h2>
      <div class="kfbr-totals">
        <div class="kfbr-row"><span>Transportation</span><span><?= kfb_r_money((float)($b['amount'] ?? 0) - (float)($b['travel_fee_amount'] ?? 0) - (float)($b['surcharges_total_amount'] ?? 0) - (float)($b['gratuity_amount'] ?? 0) - (float)($b['child_seats_fee_amount'] ?? 0) - (float)($b['addons_total'] ?? 0) + (float)($b['discount_amount'] ?? 0)) ?></span></div>
        <?php if ((float)($b['travel_fee_amount'] ?? 0) > 0): ?>
          <div class="kfbr-row"><span>Travel fee</span><span><?= kfb_r_money($b['travel_fee_amount']) ?></span></div>
        <?php endif; ?>
        <?php if ((float)($b['surcharges_total_amount'] ?? 0) > 0): ?>
          <div class="kfbr-row"><span>Surcharges</span><span><?= kfb_r_money($b['surcharges_total_amount']) ?></span></div>
        <?php endif; ?>
        <?php if ((float)($b['gratuity_amount'] ?? 0) > 0): ?>
          <div class="kfbr-row"><span>Gratuity<?= isset($b['gratuity_pct']) ? ' (' . number_format((float)$b['gratuity_pct'], 2) . '%)' : '' ?></span><span><?= kfb_r_money($b['gratuity_amount']) ?></span></div>
        <?php endif; ?>
        <?php if ((float)($b['child_seats_fee_amount'] ?? 0) > 0): ?>
          <div class="kfbr-row"><span>Child seats (<?= (int)($b['child_seats'] ?? 0) ?>)</span><span><?= kfb_r_money($b['child_seats_fee_amount']) ?></span></div>
        <?php endif; ?>
        <?php if ($hasAddons): ?>
          <div class="kfbr-row"><span>Add-ons</span><span><?= kfb_r_money($b['addons_total']) ?></span></div>
        <?php endif; ?>
        <?php if ($hasPromo): ?>
          <div class="kfbr-row"><span>Discount<?= !empty($b['promo_code']) ? ' (' . htmlspecialchars($b['promo_code']) . ')' : '' ?></span><span>&minus; <?= kfb_r_money($b['discount_amount']) ?></span></div>
        <?php endif; ?>
        <div class="kfbr-row kfbr-row--total"><span>Total<?= !empty($b['is_return_trip']) ? ' (round trip)' : '' ?></span><span><?= kfb_r_money($b['amount']) ?></span></div>
      </div>
    </div>

    <div class="kfbr-section">
      <h2>Payment</h2>
      <div class="kfbr-grid">
        <div class="kfbr-field">
          <span>Method</span>
          <p><?= !empty($b['card_brand']) ? htmlspecialchars(ucfirst($b['card_brand'])) . ' •••• ' . htmlspecialchars($b['card_last4'] ?? '') : 'PayPal' ?></p>
        </div>
        <div class="kfbr-field"><span>Status</span><p><?= htmlspecialchars(kfb_r_status_label($status)) ?></p></div>
      </div>
    </div>

    <div class="kfbr-foot">
      This receipt was generated for booking <?= kfb_r_or_dash($b['booking_id'] ?? NULL) ?>. Questions about this charge?
      Contact us and reference this booking ID.
    </div>
  </div>

</body>
</html>
