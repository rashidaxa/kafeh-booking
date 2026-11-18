<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * "Reservation Request Declined" email — sent when an admin rejects a
 * reservation (Admin_api::_send_cancellation). Vars: recipient_name,
 * booking_id, date, time.
 */
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>A1 Limousines | Reservation Request Declined</title>
<style>
body { margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, Helvetica, sans-serif; }
.container { width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; }
.header { background-color: #0797d9; padding: 25px 20px; text-align: center; }
.header img { width: 220px; max-width: 100%; height: auto; }
.header-title { color: #ffffff; font-size: 22px; font-weight: bold; margin: 15px 0 0; }
.content { padding: 25px 30px; }
.body-text { color: #333333; font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
.reservation-details { width: 100%; margin: 16px 0; background-color: #f5f7f9; border: 1px solid #e2e6ea; }
.reservation-details td { padding: 12px 15px; }
@media only screen and (max-width: 480px) { .content { padding: 20px; } }
</style>
</head>

<body>
<div class="container">

    <div class="header">
        <img src="https://grounddispatch.com/a1-limousine-logo.png" alt="A1 Limousines">
        <p class="header-title">Reservation Request Declined</p>
    </div>

    <div class="content">

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 20px;">
            <tr>
                <td align="center" bgcolor="#c62828" style="background-color:#c62828; padding:11px 12px;">
                    <p style="margin:0; color:#ffffff; font-size:16px; line-height:1.4; font-weight:bold; letter-spacing:1.5px; text-align:center;">
                        STATUS: REQUEST DECLINED
                    </p>
                </td>
            </tr>
        </table>

        <p class="body-text">Hi <?= $e($recipient_name) ?>,</p>

        <p class="body-text">
            Thank you for choosing A1 Limousines. Unfortunately, we are unable to accommodate your transportation request below.
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="reservation-details">
            <tr>
                <td>
                    <p style="margin:0 0 7px; color:#333333; font-size:15px; font-weight:bold;">Reservation Details</p>
                    <p style="margin:0; color:#333333; font-size:14px; line-height:1.6;">
                        <strong>Booking ID:</strong> <?= $e($booking_id) ?>
                        &nbsp;|&nbsp;
                        <strong>Date:</strong> <?= $e($date) ?>
                        &nbsp;|&nbsp;
                        <strong>Pickup Time:</strong> <?= $e($time) ?>
                    </p>
                </td>
            </tr>
        </table>

        <p class="body-text">Please be assured that no charges or cancellation fees will apply. If a temporary authorization hold was placed on your credit or debit card, it will be released. The time required for the hold to disappear from your account depends on your bank or card issuer.</p>

        <p class="body-text" style="margin-bottom:0;">We sincerely apologize for any inconvenience this may cause and appreciate your understanding. If you have any questions or would like assistance arranging alternative transportation, please contact us at +1 (800) 781-4700. Our team will be happy to assist you.</p>

    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center" bgcolor="#0797d9" style="background-color:#0797d9;padding:15px 8px;">
                <p style="margin:0;color:#ffffff;font-size:13px;line-height:1.8;font-weight:bold;text-align:center;">
                    <strong>A1 Limousines</strong>
                    <span style="padding:0 4px;">|</span>
                    <a href="tel:+18007814700" style="color:#ffffff;text-decoration:none;font-weight:bold;white-space:nowrap;">1 (800) 781-4700</a>
                    <span style="padding:0 4px;">|</span>
                    <a href="https://www.A1Limousines.com" style="color:#ffffff;text-decoration:none;font-weight:bold;white-space:nowrap;">www.A1Limousines.com</a>
                </p>
            </td>
        </tr>
        <tr>
            <td align="center" bgcolor="#eeeeee" style="background-color:#eeeeee;padding:16px 20px 22px;">
                <p style="margin:0;font-size:12px;line-height:1.7;color:#596675;text-align:center;">
                    This is an automated notification regarding your reservation request.
                </p>
            </td>
        </tr>
    </table>

</div>
</body>
</html>
