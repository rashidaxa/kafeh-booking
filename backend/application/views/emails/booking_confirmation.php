<?php
/**
 * HTML booking-confirmation email, branded with the booking's company
 * (logo, address, phone numbers, contact email). Inline styles only —
 * email clients ignore <style>/external CSS.
 *
 * Vars: $company (kfb_companies row or NULL), $booking (Booking_model::get_booking() shape),
 *       $logo_url (absolute URL or NULL), $greet, $passenger
 */
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$companyName = !empty($company['name']) ? $company['name'] : 'A1 Limousine';
$rows = [
    'Booking ID' => $booking['booking_id'],
    'Passenger'  => $passenger,
    'Service'    => $booking['service_type'],
    'Pickup'     => trim(($booking['pickup_date'] ?? '') . ' at ' . ($booking['pickup_time'] ?? '')),
    'From'       => $booking['pickup'],
    'To'         => $booking['dropoff'],
    'Vehicle'    => $booking['vehicle_name'],
    'Amount'     => '$' . number_format((float)$booking['amount'], 2),
];
if (!empty($booking['promo_code'])) {
    $rows['Promo'] = $booking['promo_code'] . ' (-$' . number_format((float)$booking['discount_amount'], 2) . ')';
}
$phones = array_filter([$company['phone_1'] ?? '', $company['phone_2'] ?? '']);
$address = array_filter([$company['address_line_1'] ?? '', $company['address_line_2'] ?? '']);
?>
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
      <tr><td style="padding:24px 28px;border-bottom:1px solid #e5e7eb;text-align:center;">
        <?php if ($logo_url): ?>
          <img src="<?= $e($logo_url) ?>" alt="<?= $e($companyName) ?>" style="max-height:64px;max-width:240px;">
        <?php else: ?>
          <div style="font-size:22px;font-weight:800;"><?= $e($companyName) ?></div>
        <?php endif; ?>
      </td></tr>
      <tr><td style="padding:28px;">
        <p style="margin:0 0 6px;font-size:18px;font-weight:700;">Hi <?= $e($greet) ?>,</p>
        <p style="margin:0 0 20px;font-size:15px;line-height:1.5;">Your booking is confirmed.</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-collapse:collapse;">
          <?php foreach ($rows as $label => $value): if ($value === NULL || trim((string)$value) === '') continue; ?>
            <tr>
              <td style="padding:8px 0;border-bottom:1px solid #f1f5f9;color:#6b7280;width:110px;vertical-align:top;"><?= $e($label) ?></td>
              <td style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-weight:600;"><?= $e($value) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p style="margin:24px 0 0;font-size:14px;line-height:1.5;">Thank you for choosing <?= $e($companyName) ?>.</p>
      </td></tr>
      <tr><td style="padding:18px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:#6b7280;text-align:center;">
        <strong style="color:#111827;"><?= $e($companyName) ?></strong><br>
        <?php foreach ($address as $line): ?><?= $e($line) ?><br><?php endforeach; ?>
        <?php if ($phones): ?><?= $e(implode('  ·  ', $phones)) ?><br><?php endif; ?>
        <?php if (!empty($company['contact_email'])): ?><?= $e($company['contact_email']) ?><?php endif; ?>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
