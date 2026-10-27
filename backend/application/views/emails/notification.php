<?php
/**
 * Shared layout for every system email except the reservation confirmation
 * (emails/booking_confirmation.php has its own approved template): account
 * verification code, password reset, account-email-changed, admin
 * notifications. Markup/CSS is the approved "blank template" — only the
 * content slots are dynamic. Rendered by Mailer::send_notification().
 *
 * Vars:
 *   $heading        banner title
 *   $preview        inbox preview text (hidden)
 *   $greeting       name after "Dear " (NULL = no greeting line)
 *   $paragraphs[]   HTML strings (callers escape any user data)
 *   $highlight      optional ['title' => text, 'html' => HTML] shaded box
 *   $details        optional [label => value] rows shown in the shaded box
 *   $button         optional ['label' => text, 'url' => link]
 *   $closing        optional HTML replacing the default thank-you line
 *   branding (Mailer::branding()): $company_name, $logo_url, $phone_display, $phone_tel, $website
 */
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$greeting   = isset($greeting) ? $greeting : NULL;
$paragraphs = isset($paragraphs) ? $paragraphs : [];
$highlight  = isset($highlight) ? $highlight : NULL;
$details    = isset($details) ? $details : [];
$button     = isset($button) ? $button : NULL;
$preview    = isset($preview) ? $preview : $heading;
$closing    = isset($closing) ? $closing : 'Thank you for choosing ' . $e($company_name) . '. We appreciate your business and look forward to serving you.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no">
<title><?= $e($company_name) ?> | Customer Update</title>

<style type="text/css">
  body, table, td, p, h1, h2, h3, a {
    font-family: Arial, Helvetica, sans-serif;
  }

  body {
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
    background-color: #eeeeee;
    color: #333333;
    -webkit-text-size-adjust: 100%;
    -ms-text-size-adjust: 100%;
  }

  table {
    border-collapse: collapse;
    border-spacing: 0;
  }

  img {
    display: block;
    max-width: 100%;
    height: auto;
    border: 0;
    outline: none;
    text-decoration: none;
  }

  .email-container {
    width: 100%;
    max-width: 600px;
    background-color: #ffffff;
  }

  .content {
    padding-left: 24px;
    padding-right: 24px;
  }

  .email-title {
    margin: 0;
    color: #ffffff;
    font-size: 24px;
    line-height: 1.35;
    font-weight: bold;
    text-align: center;
  }

  .body-text {
    margin: 0 0 16px;
    color: #333333;
    font-size: 15px;
    line-height: 1.75;
  }

  .call-button {
    display: inline-block;
    padding: 13px 28px;
    background-color: #0788d8;
    border: 1px solid #0788d8;
    border-radius: 5px;
    color: #ffffff !important;
    font-size: 17px;
    line-height: 1.4;
    font-weight: bold;
    text-decoration: none !important;
    text-align: center;
  }

  .footer-link {
    color: #ffffff !important;
    text-decoration: none !important;
    font-weight: bold;
  }

  @media screen and (max-width: 480px) {
    .outer-padding {
      padding: 4px 0 !important;
    }

    .content {
      padding-left: 16px !important;
      padding-right: 16px !important;
    }

    .email-title {
      font-size: 21px !important;
    }

    .body-text {
      font-size: 14px !important;
      line-height: 1.75 !important;
    }

    .call-button {
      display: block !important;
      padding: 14px 12px !important;
    }

    .footer-text {
      font-size: 11px !important;
    }
  }
</style>
</head>

<body style="margin:0;padding:0;background-color:#eeeeee;">

<!-- INBOX PREVIEW TEXT -->
<div style="display:none;font-size:1px;color:#eeeeee;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
<?= $e($preview) ?>
</div>

<!-- EMAIL BACKGROUND -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eeeeee" style="width:100%;background-color:#eeeeee;">
<tr>
<td align="center" class="outer-padding" style="padding:16px 8px;">

<!-- MAIN EMAIL CONTAINER -->
<table role="presentation" class="email-container" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background-color:#ffffff;">

<!-- HEADER -->
<tr>
<td align="center" bgcolor="#0797d9" style="background-color:#0797d9;padding:24px 16px;">

<img
  src="<?= $e($logo_url) ?>"
  width="220"
  alt="<?= $e($company_name) ?> logo"
  style="display:block;width:220px;max-width:100%;height:auto;margin:0 auto 15px;"
>

<h1 class="email-title">
<?= $e($heading) ?>
</h1>

</td>
</tr>

<!-- MAIN MESSAGE -->
<tr>
<td class="content" style="padding-top:26px;padding-bottom:12px;background-color:#ffffff;">

<?php if ($greeting !== NULL): ?>
<p class="body-text">
Dear <?= $e($greeting) ?>,
</p>
<?php endif; ?>

<?php foreach ($paragraphs as $p): ?>
<p class="body-text">
<?= $p ?>
</p>
<?php endforeach; ?>

<?php if ($button): ?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:6px auto 18px;">
<tr>
<td align="center" bgcolor="#0788d8" style="background-color:#0788d8;border-radius:5px;">
<a
  href="<?= $e($button['url']) ?>"
  class="call-button"
  style="display:inline-block;padding:13px 28px;background-color:#0788d8;border:1px solid #0788d8;border-radius:5px;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:17px;line-height:1.4;font-weight:bold;text-align:center;"
>
<?= $e($button['label']) ?>
</a>
</td>
</tr>
</table>
<?php endif; ?>

</td>
</tr>

<?php if ($highlight || $details): ?>
<!-- HIGHLIGHT / IMPORTANT INFORMATION -->
<tr>
<td class="content" style="padding-top:0;padding-bottom:20px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef8fd" style="width:100%;background-color:#eef8fd;border-left:4px solid #0797d9;">
<tr>
<td style="padding:15px 16px;">

<?php if ($highlight): ?>
<p style="margin:0 0 5px;font-size:14px;line-height:1.5;color:#0876a9;font-weight:bold;">
<?= $e($highlight['title']) ?>
</p>

<p style="margin:0;font-size:14px;line-height:1.7;color:#333333;">
<?= $highlight['html'] ?>
</p>
<?php endif; ?>

<?php if ($details): ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
<?php foreach ($details as $label => $value): ?>
<tr>
<td valign="top" style="padding:3px 10px 3px 0;font-size:14px;line-height:1.6;color:#0876a9;font-weight:bold;white-space:nowrap;"><?= $e($label) ?></td>
<td valign="top" style="padding:3px 0;font-size:14px;line-height:1.6;color:#333333;"><?= nl2br($e($value)) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

</td>
</tr>
</table>

</td>
</tr>
<?php endif; ?>

<!-- CALL TO ACTION -->
<tr>
<td align="center" bgcolor="#f4f7fa" style="background-color:#f4f7fa;padding:23px 20px;">

<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#333333;text-align:center;">
Need assistance? Call us.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
<tr>
<td align="center" bgcolor="#0788d8" style="background-color:#0788d8;border-radius:5px;">

<a
  href="tel:<?= $e($phone_tel) ?>"
  class="call-button"
  aria-label="Call <?= $e($company_name) ?> at <?= $e($phone_display) ?>"
  style="display:inline-block;padding:13px 28px;background-color:#0788d8;border:1px solid #0788d8;border-radius:5px;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:17px;line-height:1.4;font-weight:bold;text-align:center;"
>
<?= $e($phone_display) ?>
</a>

</td>
</tr>
</table>

</td>
</tr>

<!-- CLOSING MESSAGE -->
<tr>
<td class="content" style="padding-top:22px;padding-bottom:24px;">

<p class="body-text" style="margin-bottom:0;">
<?= $closing ?>
</p>

</td>
</tr>

<!-- ONE-LINE FOOTER -->
<tr>
<td align="center" bgcolor="#0797d9" style="background-color:#0797d9;padding:15px 8px;">

<p class="footer-text" style="margin:0;color:#ffffff;font-size:13px;line-height:1.8;font-weight:bold;text-align:center;">

<strong><?= $e($company_name) ?></strong>
<span style="padding:0 4px;color:#ffffff;">|</span>

<a
  href="tel:<?= $e($phone_tel) ?>"
  class="footer-link"
  style="color:#ffffff;text-decoration:none;font-weight:bold;white-space:nowrap;"
>
<?= $e($phone_display) ?>
</a>
<?php if (!empty($website)): ?>

<span style="padding:0 4px;color:#ffffff;">|</span>

<a
  href="https://<?= $e($website) ?>"
  class="footer-link"
  style="color:#ffffff;text-decoration:none;font-weight:bold;white-space:nowrap;"
>
<?= $e($website) ?>
</a>
<?php endif; ?>

</p>

</td>
</tr>

<!-- UNSUBSCRIBE NOTICE -->
<tr>
<td align="center" bgcolor="#eeeeee" style="background-color:#eeeeee;padding:16px 20px 22px;">

<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.7;color:#596675;text-align:center;">
To stop receiving reservation reminder messages, reply <strong>STOP</strong> to unsubscribe.
</p>

</td>
</tr>

</table>
<!-- END MAIN EMAIL CONTAINER -->

</td>
</tr>
</table>

</body>
</html>
