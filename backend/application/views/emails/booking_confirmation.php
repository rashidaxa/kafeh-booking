<?php
/**
 * Reservation confirmation email sent to the customer when an admin accepts
 * a reservation. Markup is the approved "Reservation Confirmation" template
 * (same layout, wording and date/time formats as the legacy portal's
 * sendconfirmation view) — only the values are dynamic. Inline styles /
 * tables only: email clients ignore <style> support and external CSS/JS.
 *
 * A round trip renders one block per leg (outbound, return), each priced as
 * half the round trip — the same split Legacy_reservations::sync() pushes.
 *
 * Vars (built by Admin_api::_confirmation_vars()):
 *   $legs[]            reference, client_name, pickup_date, pickup_time, dropoff_time, trip_type,
 *                      passenger_name, client_ref, client_phone, ordered_by, passenger_count,
 *                      vehicle_name, pickup_lines[], dropoff_lines[], notes, transport, gratuity,
 *                      extra_charges[{name,amount}], payment_method, discount, grand_total
 *   $cs_line           e.g. "Toll: +1 (800) 781-4700"
 *   $footer_lines[]    company contact lines for the footer
 *   $logo_header_url, $logo_footer_url
 */
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en"><head>

      <title>Reservation Confirmation</title>

      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">

      <meta name="viewport" content="width=device-width, initial-scale=1.0">

      <!--[if mso]>

      <xml>

         <o:OfficeDocumentSettings>

            <o:PixelsPerInch>96</o:PixelsPerInch>

            <o:AllowPNG/>

         </o:OfficeDocumentSettings>

      </xml>

      <![endif]-->

      <style>

         *{

         box-sizing: border-box;

         }

         body{

         margin: 0;

         padding: 0;

         }

         a[x-apple-data-detectors] {

         color: inherit !important;

         text-decoration: inherit !important;

         }

         #MessageViewBody a {

         color: inherit;

         text-decoration: none;

         }

         p{

         line-height: inherit

         }

         .desktop_hide,

         .desktop_hide table{

         mso-hide: all;

         display: none;

         max-height: 0px;

         overflow: hidden;

         }

         @media (max-width:660px) {

         .row-28 .column-1 .block-2.image_block img {

         display: inline-block!important;

         }

         .row-content {

         width: 100%!important;

         }

         .mobile_hide {

         display: none;

         }

         .stack .column {

         width: 100%;

         display: block;

         }

         .mobile_hide {

         min-height: 0;

         max-height: 0;

         max-width: 0;

         overflow: hidden;

         font-size: 0px;

         }

         .desktop_hide,

         .desktop_hide table {

         display: table!important;

         max-height: none!important;

         }

         .row-28 .column-1 .block-2.image_block .alignment {

         text-align: center!important;

         }

         .row-29 .column-1 .block-1.text_block td.pad {

         padding: 5px 10px!important;

         }

         .row-6 .column-1 {

         padding: 5px 0 0!important;

         }

         }
                   @media print {
            @page {
              margin: 0mm;
              margin-top: 15px;
              margin-bottom: 15px;
            }
            html {
               margin: 0mm;
            }
            body  {
              padding-top: 72px;
              padding-bottom: 72px ;
              -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
         ::-webkit-scrollbar { display: none; }
         ::scrollbar { display: none; }
         }

      </style>

   </head>

   <body style="background-color: rgb(235, 235, 235); margin: 0px; padding: 0px; -moz-text-size-adjust: none;" data-background-color="rgb(235, 235, 235)">

<table class="nl-container" role="presentation" style="background-color: rgb(235, 235, 235);" data-background-color="rgb(235, 235, 235)" width="100%" cellspacing="0" cellpadding="0" border="0">

                  <tbody>

                     <tr>

                        <td>

                           <table class="row row-1" role="presentation" style="background-color: rgb(255, 255, 255);" data-background-color="rgb(255, 255, 255)" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:10px;padding-right:10px;padding-top:15px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.2; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; font-size: 14px; mso-line-height-alt: 16.8px;">Your reservation is confirmed</p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                                <td class="column column-2" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:10px;padding-right:10px;padding-top:15px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.2; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: left; mso-line-height-alt: 16.8px;">Customer Service <?= $e($cs_line) ?></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-2 page-break" role="presentation" style="background-color: rgb(0, 151, 219);" data-background-color="rgb(0, 151, 219)" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 10px; padding-right: 10px; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <div class="spacer_block" style="height:15px;line-height:15px;font-size:1px;"> </div>

                                                   <div class="spacer_block desktop_hide" style="mso-hide: all; display: none; max-height: 0; overflow: hidden; height: 10px; line-height: 10px; font-size: 1px;"> </div>

                                                   <table class="image_block block-3" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="width:100%;padding-right:0px;padding-left:0px;padding-bottom:15px;">

                                                               <div class="alignment" style="line-height:10px" align="left"><img src="<?= $e($logo_header_url) ?>" style="display: block; height: auto; border: 0; width: 300px; max-width: 100%;" width="300"></div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                                <td class="column column-2" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <div class="spacer_block" style="height:5px;line-height:5px;font-size:1px;"> </div>

                                                   <div class="spacer_block mobile_hide" style="height:10px;line-height:10px;font-size:1px;"> </div>

                                                   <table class="text_block block-3" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:10px;padding-right:10px;padding-top:10px;">

                                                               <div style="font-family: 'Times New Roman', Georgia, serif">

                                                                  <div class="" style="font-size: 14px; font-family: TimesNewRoman, &quot;Times New Roman&quot;, Times, Beskerville, Georgia, serif; color: rgb(255, 255, 255); line-height: 1.2;" data-color="rgb(255, 255, 255)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: right; mso-line-height-alt: 16.8px;"><span style="font-size:24px;">Reservation Confirmation</span></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <!-- Multiple Starts from here -->
<?php foreach ($legs as $leg): ?>

                            

                              
                                 <table class="row row-4" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <div class="spacer_block" style="height:20px;line-height:20px;font-size:1px;"> </div>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>



                               

                           <table class="row row-3" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 15px; padding-right: 15px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:10px;padding-left:10px;padding-right:10px;padding-top:20px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.2; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: center; mso-line-height-alt: 16.8px;"><span style="font-size:22px;">Reservation Confirmation# <?= $e($leg["reference"]) ?></span></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

  <!--                          <table class="row row-4" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <div class="spacer_block" style="height:20px;line-height:20px;font-size:1px;"> </div>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table> -->

                           <table class="row row-5" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(0, 151, 219); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(0, 151, 219)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 20px; padding-right: 20px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="10" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div style="font-family: 'Times New Roman', Georgia, serif">

                                                                  <div class="" style="font-size: 14px; font-family: TimesNewRoman, &quot;Times New Roman&quot;, Times, Beskerville, Georgia, serif; color: rgb(255, 255, 255); line-height: 1.2;" data-color="rgb(255, 255, 255)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: center; mso-line-height-alt: 16.8px;"><strong><span style="font-size:24px;">Reservation Details</span></strong></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-6" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                   <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(255, 255, 255); border-radius: 0px; color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="5" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(0, 0, 0); line-height: 1.2; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(0, 0, 0)">

                                                                     <p style="margin: 0; font-size: 14px; mso-line-height-alt: 16.8px;"><strong>Client: <?= $e(ucwords($leg["client_name"])) ?> </strong></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                   <table class="divider_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="10" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div class="alignment" align="center">

                                                                  <table role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                                     <tbody>

                                                                        <tr>

                                                                           <td class="divider_inner" style="font-size: 1px; line-height: 1px; border-top: 1px solid rgb(187, 187, 187);" data-border-top-color="rgb(187, 187, 187)"><span> </span></td>

                                                                        </tr>

                                                                     </tbody>

                                                                  </table>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-7" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:5px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Pick-up Date: <?= $e(date("l, F d, Y", strtotime($leg["pickup_date"]))) ?></p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Pick-up Time: <?= $e(date("g:i A", strtotime($leg["pickup_time"]))) ?> / <?= $e(date("H:i ", strtotime($leg["pickup_time"]))) ?>Hrs</p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Drop-off Time:  <?= $e(date("g:i A", strtotime($leg["dropoff_time"]))) ?> / <?= $e(date("H:i ", strtotime($leg["dropoff_time"]))) ?>Hrs</p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Service Type: <?= $e(ucwords($leg["trip_type"])) ?></p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Passenger: <?= $e(ucwords($leg["passenger_name"])) ?></p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Client Ref#:  <?= $e($leg["client_ref"]) ?></p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Phone Number: <?= $e($leg["client_phone"]) ?></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                                <td class="column column-2" style="font-weight: 400; text-align: left; border-color: currentcolor currentcolor currentcolor rgb(222, 223, 222); border-style: none none none solid; border-width: 0px 0px 0px 1px; vertical-align: top;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="rgb(222, 223, 222)" width="50%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:10px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Ordered By: <?= $e(ucwords($leg["ordered_by"])) ?></p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">No. of Pass: <?= $e($leg["passenger_count"]) ?></p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Vehicle Type: <?= $e(ucwords($leg["vehicle_name"])) ?></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-8" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="divider_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:15px;">

                                                               <div class="alignment" align="center">

                                                                  <table role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                                     <tbody>

                                                                        <tr>

                                                                           <td class="divider_inner" style="font-size: 1px; line-height: 1px; border-top: 1px solid rgb(222, 223, 222);" data-border-top-color="rgb(222, 223, 222)"><span> </span></td>

                                                                        </tr>

                                                                     </tbody>

                                                                  </table>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                                <td class="column column-2" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="divider_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:15px;">

                                                               <div class="alignment" align="center">

                                                                  <table role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                                     <tbody>

                                                                        <tr>

                                                                           <td class="divider_inner" style="font-size: 1px; line-height: 1px; border-top: 1px solid rgb(222, 223, 222);" data-border-top-color="rgb(222, 223, 222)"><span> </span></td>

                                                                        </tr>

                                                                     </tbody>

                                                                  </table>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-9" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <div class="spacer_block" style="height:20px;line-height:20px;font-size:1px;"> </div>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-10" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(0, 151, 219); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(0, 151, 219)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 20px; padding-right: 20px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="10" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div style="font-family: 'Times New Roman', Georgia, serif">

                                                                  <div class="" style="font-size: 14px; font-family: TimesNewRoman, &quot;Times New Roman&quot;, Times, Beskerville, Georgia, serif; color: rgb(255, 255, 255); line-height: 1.2;" data-color="rgb(255, 255, 255)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: center; mso-line-height-alt: 16.8px;"><strong><span style="font-size:24px;">Trip Routing Information</span></strong></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-11" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-left:20px;padding-right:20px;padding-top:15px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(0, 151, 219); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(0, 151, 219)">

                                                                     <p style="margin: 0; font-size: 14px; mso-line-height-alt: 21px;"><strong>PICK-UP:</strong></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                   <table class="text_block block-3" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                         <?php foreach ($leg["pickup_lines"] as $line): ?><p style="margin: 0; mso-line-height-alt: 21px;"><?= $e($line) ?></p><?php endforeach; ?>
                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                                <td class="column column-2" style="font-weight: 400; text-align: left; border-color: currentcolor currentcolor currentcolor rgb(222, 223, 222); border-style: none none none solid; border-width: 0px 0px 0px 1px; vertical-align: top;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="rgb(222, 223, 222)" width="50%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-left:20px;padding-right:20px;padding-top:15px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(0, 151, 219); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(0, 151, 219)">

                                                                     <p style="margin: 0; font-size: 14px; mso-line-height-alt: 21px;"><strong>Drop Off:</strong></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                   <table class="text_block block-3" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:5px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <?php foreach ($leg["dropoff_lines"] as $line): ?><p style="margin: 0; mso-line-height-alt: 21px;"><?= $e($line) ?></p><?php endforeach; ?>
                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-12" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="divider_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:15px;">

                                                               <div class="alignment" align="center">

                                                                  <table role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                                     <tbody>

                                                                        <tr>

                                                                           <td class="divider_inner" style="font-size: 1px; line-height: 1px; border-top: 1px solid rgb(222, 223, 222);" data-border-top-color="rgb(222, 223, 222)"><span> </span></td>

                                                                        </tr>

                                                                     </tbody>

                                                                  </table>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                                <td class="column column-2" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="divider_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:15px;">

                                                               <div class="alignment" align="center">

                                                                  <table role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                                     <tbody>

                                                                        <tr>

                                                                           <td class="divider_inner" style="font-size: 1px; line-height: 1px; border-top: 1px solid rgb(222, 223, 222);" data-border-top-color="rgb(222, 223, 222)"><span> </span></td>

                                                                        </tr>

                                                                     </tbody>

                                                                  </table>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-13" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 5px; padding-right: 5px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <div class="spacer_block" style="height:20px;line-height:20px;font-size:1px;"> </div>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-14" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(0, 151, 219); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(0, 151, 219)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 20px; padding-right: 20px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="10" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div style="font-family: 'Times New Roman', Georgia, serif">

                                                                  <div class="" style="font-size: 14px; font-family: TimesNewRoman, &quot;Times New Roman&quot;, Times, Beskerville, Georgia, serif; color: rgb(255, 255, 255); line-height: 1.2;" data-color="rgb(255, 255, 255)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: center; mso-line-height-alt: 16.8px;"><span style="font-size:24px;"><strong>Notes/Comments and Special Requests</strong><br></span></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-15" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 15px; padding-right: 15px; vertical-align: top; padding-top: 15px; padding-bottom: 15px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:10px;padding-left:20px;padding-right:20px;padding-top:5px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; font-size: 14px; mso-line-height-alt: 21px;"><?= nl2br($e($leg["notes"])) ?></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-16" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 5px; padding-right: 5px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <div class="spacer_block" style="height:20px;line-height:20px;font-size:1px;"> </div>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-17" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(0, 151, 219); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(0, 151, 219)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 20px; padding-right: 20px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="10" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div style="font-family: 'Times New Roman', Georgia, serif">

                                                                  <div class="" style="font-size: 14px; font-family: TimesNewRoman, &quot;Times New Roman&quot;, Times, Beskerville, Georgia, serif; color: rgb(255, 255, 255); line-height: 1.2;" data-color="rgb(255, 255, 255)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: center; mso-line-height-alt: 16.8px;"><span style="font-size:24px;"><strong>Charges &amp; Fees</strong><br></span></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-18" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="50%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:5px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">Regular Hrly/Flat Chrgs: $<?= number_format((float)$leg["transport"], 2, ".", "") ?></div>

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">Gratuity: $<?= number_format((float)$leg["gratuity"], 2, ".", "") ?></div>
<?php foreach ($leg["extra_charges"] as $c): ?>
<div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)"><?= $e($c["name"]) ?>: $<?= number_format((float)$c["amount"], 2, ".", "") ?></div>
<?php endforeach; ?>

                                                                              
                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                                <td class="column column-2" style="font-weight: 400; text-align: left; border-color: currentcolor currentcolor currentcolor rgb(222, 223, 222); border-style: none none none solid; border-width: 0px 0px 0px 1px; vertical-align: top;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="rgb(222, 223, 222)" width="50%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:20px;padding-right:20px;padding-top:10px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.5; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Payment Method: <?= $e($leg["payment_method"]) ?></p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Discount: -$<?= number_format((float)$leg["discount"], 2, ".", "") ?></p>

                                                                     <p style="margin: 0; mso-line-height-alt: 21px;">Estimated Charges $<?= number_format((float)$leg["grand_total"], 2, ".", "") ?></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           
                           <?php endforeach; ?>

<table class="row row-19" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 5px; padding-right: 5px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <div class="spacer_block" style="height:20px;line-height:20px;font-size:1px;"> </div>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-20" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(0, 151, 219); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(0, 151, 219)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 20px; padding-right: 20px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="10" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div style="font-family: 'Times New Roman', Georgia, serif">

                                                                  <div class="" style="font-size: 14px; font-family: TimesNewRoman, &quot;Times New Roman&quot;, Times, Beskerville, Georgia, serif; color: rgb(255, 255, 255); line-height: 1.2;" data-color="rgb(255, 255, 255)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: center; mso-line-height-alt: 16.8px;"><span style="font-size:24px;"><strong>Cancellation Policy</strong><br></span></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-21" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 15px; padding-right: 15px; vertical-align: top; padding-top: 15px; padding-bottom: 15px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:10px;padding-left:30px;padding-right:30px;padding-top:10px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.2; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">Cancellation Policy: For buses, cancellations 46 or more days before service result in forfeiture of the initial 60% payment; cancellations 45 days or fewer before service result in forfeiture of 100% of the contract value. For all other vehicles, cancellations 31 or more days before service result in forfeiture of the initial 60% payment; cancellations 30 days or fewer before service result in forfeiture of 100% of the contract value. All cancellation charges are subject to applicable law. </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                   <table class="divider_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:10px;padding-left:20px;padding-right:20px;padding-top:10px;">

                                                               <div class="alignment" align="center">

                                                                  <table role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                                     <tbody>

                                                                        <tr>

                                                                           <td class="divider_inner" style="font-size: 1px; line-height: 1px; border-top: 1px solid rgb(222, 223, 222);" data-border-top-color="rgb(222, 223, 222)"><span> </span></td>

                                                                        </tr>

                                                                     </tbody>

                                                                  </table>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-22" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 5px; padding-right: 5px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <div class="spacer_block" style="height:20px;line-height:20px;font-size:1px;"> </div>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-23" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(0, 151, 219); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(0, 151, 219)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 20px; padding-right: 20px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="10" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div style="font-family: 'Times New Roman', Georgia, serif">

                                                                  <div class="" style="font-size: 14px; font-family: TimesNewRoman, &quot;Times New Roman&quot;, Times, Beskerville, Georgia, serif; color: rgb(255, 255, 255); line-height: 1.2;" data-color="rgb(255, 255, 255)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: center; mso-line-height-alt: 16.8px;"><strong><span style="font-size:24px;">Pick-up Procedures &amp; Instructions</span></strong></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-24" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 15px; padding-right: 15px; vertical-align: top; padding-top: 15px; padding-bottom: 15px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:10px;padding-left:30px;padding-right:30px;padding-top:10px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(28, 28, 28); line-height: 1.2; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; mso-line-height-alt: 16.8px;"><strong>Airport Arrivals</strong><strong>:</strong>&nbsp;

                                                                        Whether you checked luggage or not, proceed to the baggage claim level,

                                                                        once you have your luggage, call 1-800-781-4700 and the dispatcher will

                                                                        then tell you what door or vestibule number to go out and you will be 

                                                                        provided with a description of the Vehicle &amp; the License Plate 

                                                                        Number.

                                                                     </p>

                                                                     <p style="margin: 0; mso-line-height-alt: 16.8px;">&nbsp;</p>

                                                                     <p style="margin: 0; mso-line-height-alt: 16.8px;"><strong>Meet &amp; Greet Service: </strong>&nbsp;Chauffeur

                                                                        will meet Passenger at the Baggage Claim Carousel assigned to the 

                                                                        flight. You do not need to call us, unless you are unable to locate your

                                                                        driver. The Chauffeur will assist with luggage and escort the Passenger

                                                                        to their vehicle<br><br>

                                                                     </p>

                                                                     <p style="margin: 0; mso-line-height-alt: 16.8px;"><strong>FBO – Private Airports:</strong>

                                                                        Please proceed to the Lobby or Main Entrance / Exit for pick-up. The 

                                                                        Chauffeur will assist with luggage. If permissible and with prior 

                                                                        permission only our Chauffeur will meet you at your private jet or 

                                                                        planeside.

                                                                     </p>

                                                                     <p style="margin: 0; mso-line-height-alt: 16.8px;">&nbsp;</p>

                                                                     <p style="margin: 0; mso-line-height-alt: 16.8px;"><strong>Train Stations:</strong><strong> </strong>Please

                                                                        proceed to the Lobby or Main Entrance / Exit for pick-up. Call our 

                                                                        dispatch at 1-800-781-4700. Our dispatcher will ask for your last name 

                                                                        and reservation number. Please have this information ready before you 

                                                                        call &amp; dispatcher will help find your vehicle.

                                                                     </p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                   <table class="divider_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:10px;padding-left:20px;padding-right:20px;padding-top:10px;">

                                                               <div class="alignment" align="center">

                                                                  <table role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                                     <tbody>

                                                                        <tr>

                                                                           <td class="divider_inner" style="font-size: 1px; line-height: 1px; border-top: 1px solid rgb(222, 223, 222);" data-border-top-color="rgb(222, 223, 222)"><span> </span></td>

                                                                        </tr>

                                                                     </tbody>

                                                                  </table>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-25" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 5px; padding-right: 5px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <div class="spacer_block" style="height:20px;line-height:20px;font-size:1px;"> </div>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-26" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(0, 151, 219); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(0, 151, 219)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 20px; padding-right: 20px; vertical-align: top; padding-top: 5px; padding-bottom: 5px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="10" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad">

                                                               <div style="font-family: 'Times New Roman', Georgia, serif">

                                                                  <div class="" style="font-size: 14px; font-family: TimesNewRoman, &quot;Times New Roman&quot;, Times, Beskerville, Georgia, serif; color: rgb(255, 255, 255); line-height: 1.2;" data-color="rgb(255, 255, 255)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: center; mso-line-height-alt: 16.8px;"><strong><span style="font-size:24px;"><span data-d-component="text" data-d-inline=""><span data-d-component="text" data-d-inline="">Reservation Contract &amp; Terms &amp; Conditions</span></span></span></strong></p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-27 page-break" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td><!-- MASTER AGREEMENT START 1 -->


                                       <table class="row-content stack" role="presentation" style="background-color: rgb(255, 255, 255); color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; padding-left: 15px; padding-right: 15px; vertical-align: top; padding-top: 15px; padding-bottom: 15px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:10px;padding-left:30px;padding-right:30px;padding-top:10px;"><div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:rgb(28,28,28);line-height:1.2;">
                                                              <p style="margin:0;"><strong>Customer Acknowledgment</strong></p>
                                                              <p style="margin:0;">By making a reservation, authorizing payment, electronically accepting these terms, or providing affirmative verbal acceptance by telephone, the customer acknowledges that the reservation is subject to these Reservation Confirmation Terms, theA1 Limousine Service Website Terms &amp; Conditions, and, where applicable, theA1 Limousine Service Master Transportation Charter &amp; Rental Agreement. These terms are incorporated into and form part of the customer's reservation agreement. The customer is responsible for reviewing the applicable terms before or at the time of booking.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Confirmations &amp; Rates</strong></p>
                                                              <p style="margin:0;">Your reservation confirmation reflects the services, vehicle, date, time, locations, and pricing agreed upon at the time of booking. Rates and services are based on the information provided by the customer. Changes to passenger count, vehicle requirements, pickup or destination locations, additional stops, waiting time, service duration, luggage, special equipment, or other service requirements may result in additional charges. A reservation is not considered confirmed untilA1 Limousine Service has accepted the reservation and any required payment or payment authorization has been received.</p>
                                                              <p style="margin:0;">Payment Terms: 60% is due at booking; the remaining 40% is automatically charged 45 days before service for buses and 30 days before service for all other vehicles. If booked inside the applicable Point of No Return, 100% is due at booking.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Point-to-Point Service</strong></p>
                                                              <p style="margin:0;">Point-to-point service generally includes one authorized pickup location and one authorized destination, with no substantial waiting period or unscheduled stops. The vehicle may not remain at a pickup or drop-off location for more than 10 minutes unless otherwise authorized byA1 Limousine Service or required by the reservation. Additional stops, route deviations, or waiting time must be authorized in advance when practicable and may result in additional charges. Authorized waiting or route deviations may be billed at the applicable rate, generally $5.00 to $15.00 per minute, plus applicable gratuity and other charges. Eating, smoking, vaping, and unauthorized alcohol consumption are prohibited in the vehicle.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Hourly Rental</strong></p>
                                                              <p style="margin:0;">Hourly reservations are subject to the applicable minimum rental period stated at booking. A five-minute grace period applies after the scheduled end time. Additional time after the grace period may be billed in one-hour increments, together with applicable fuel, operational, waiting, or other charges and gratuity. Eating, non-alcoholic drinking unless authorized, smoking, vaping, electronic cigarettes, vaporizers, and cannabis use or smoking are prohibited in the vehicle.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Airports &amp; Train Stations</strong></p>
                                                              <p style="margin:0;">Airport and train station pickups are subject to applicable waiting-time policies and the specific terms provided with the reservation. Unless otherwise stated in the reservation, applicable waiting charges may be billed as follows: Sedan: $3.50 per minute; SUV: $4.00 per minute; Van: $5.00 per minute; Limousine: $7.00 per minute; Trolley/Bus: $8.00 per minute.A1 Limousine Service is not responsible for missed, canceled, diverted, or delayed flights or trains, or for delays caused by immigration, customs, security, baggage claim, weather, traffic, road closures, airline or railroad operations, or other circumstances beyondA1 Limousine Service' reasonable control. Customers should promptly notifyA1 Limousine Service of material changes to flight or train information whenever possible. An itemized receipt for applicable waiting time or other authorized charges may be provided upon request.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>No-Show &amp; Cancellation Policy</strong></p>
                                                              <p style="margin:0;">A reservation canceled within the applicable non-refundable period, or a verified no-show, may result in forfeiture of amounts paid and/or the full reservation charge, as applicable under theA1 Limousine Service Website Terms &amp; Conditions and any applicable Master Transportation Charter &amp; Rental Agreement. For a verified no-show, the customer may be responsible for 100% of the applicable trip cost, applicable surcharges, 20% gratuity, and other properly applicable charges. Airport and train station grace periods, where applicable, are governed by the reservation terms and theA1 Limousine Service Website Terms &amp; Conditions.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Passenger Conduct, Drugs &amp; Alcohol</strong></p>
                                                              <p style="margin:0;">Passengers must remain seated while the vehicle is moving and may not place any part of their body outside vehicle windows, doors, emergency exits, sunroofs, or moonroofs. Weapons, illegal drugs, unlawful activity, violence, threats, harassment, and unsafe conduct are prohibited. Smoking, vaping, electronic cigarette use, and cannabis use or smoking are prohibited in the vehicle. Alcohol may be transported or consumed only when permitted by applicable law, the vehicle classification, andA1 Limousine Service' policies.A1 Limousine Service or its chauffeur may terminate service or require a passenger to leave the vehicle when reasonably necessary because of safety, legal, operational, or conduct concerns. No refund is required for service terminated because of prohibited, unlawful, or unsafe conduct, subject to applicable law.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Additional Charges</strong></p>
                                                              <p style="margin:0;">Customers must disclose, when applicable, pets, service animals, sports equipment, musical instruments, excessive or unusual luggage, special equipment, additional passengers, additional stops, or other requirements that may affect the vehicle or service. Additional charges may apply for waiting time, additional service time, route deviations, additional stops, mileage, tolls, parking, airport or facility fees, congestion, special requests, cleaning, damage, cancellation, no-show, or other properly applicable charges.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Cleaning &amp; Damage</strong></p>
                                                              <p style="margin:0;">The customer is responsible for extraordinary cleaning, damage, or loss caused by the customer or members of the customer's party, to the maximum extent permitted by applicable law. Extraordinary cleaning or biohazard-related charges may be assessed up to $2,000, depending on the nature and extent of the incident. Structural, upholstery, equipment, or other vehicle damage may result in charges of up to $5,000, depending on the nature and extent of the damage. Other reasonable repair or replacement costs may apply where permitted by applicable law.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Vehicle Substitution</strong></p>
                                                              <p style="margin:0;">A1 Limousines may substitute a vehicle when reasonably necessary because of mechanical problems, safety concerns, availability, operational requirements, road conditions, or circumstances beyondA1 Limousine Service’ reasonable control.A1 Limousine Service will use reasonable efforts to provide a substitute vehicle with comparable passenger capacity and service capability whenever practicable. Specific vehicle make, model, color, age, or individual amenities are not guaranteed unless expressly guaranteed in a written reservation agreement.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Affiliates &amp; Subcontractors</strong></p>
                                                              <p style="margin:0;">A1 Limousines may use qualified affiliates, independent transportation companies, subcontractors, or other service providers to perform all or part of a reservation.A1 Limousine Service will use reasonable care in selecting service providers and remains responsible for its obligations under applicable law and the customer's reservation agreement. Independent transportation providers may have their own operational procedures, policies, and legal obligations.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Baggage, Personal Property &amp; Child Safety</strong></p>
                                                              <p style="margin:0;">Customers are responsible for their personal property and should not leave valuables unattended in a vehicle.A1 Limousine Service is not responsible for lost, stolen, damaged, or forgotten property except to the extent required by applicable law. Customers are responsible for providing any legally required child safety seats unless a seat has been specifically arranged and confirmed in writing byA1 Limousine Service.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Force Majeure &amp; Service Delays</strong></p>
                                                              <p style="margin:0;">A1 Limousines is not responsible for delays, interruptions, inability to perform, or changes in service caused by circumstances beyond its reasonable control, including severe weather, natural disasters, traffic conditions, road closures, accidents, vehicle breakdowns, government actions, strikes, public emergencies, airline or transportation disruptions, or other force majeure events.A1 Limousine Service will make reasonable efforts to provide the reserved service or an appropriate alternative when practicable.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Telephone &amp; Communication Records</strong></p>
                                                              <p style="margin:0;">Telephone calls and other communications withA1 Limousine Service may be recorded, transcribed, or retained for reservation verification, quality assurance, customer service, training, security, dispute resolution, recordkeeping, collection, or legal purposes, subject to applicable law.A1 Limousine Service will provide any notice and obtain any consent required by applicable federal or state law before recording or transcribing a private communication.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>Payment Authorization</strong></p>
                                                              <p style="margin:0;">By authorizing a reservation, the customer authorizesA1 Limousine Service to charge the approved payment method for the reservation amount and for additional charges properly arising under the reservation and applicable terms. Additional authorized charges may include, without limitation, waiting time, additional service time, additional stops, tolls, parking, cleaning, damage, cancellation, no-show, or other properly documented charges. The payment method provided for the reservation may be retained and used for subsequent authorized charges arising from the same reservation, subject to applicable law and the terms provided to the customer.</p>
                                                              <p style="margin:0;">&nbsp;</p>
                                                              <p style="margin:0;"><strong>☑ I  I acknowledge that I have read, understand, and agree to the applicable A1 Limousine Service Reservation Confirmation Terms, the A1 Limousines Website Terms &amp; Conditions, and, where applicable, the A1 Limousine Master Transportation Charter &amp; Rental Agreement. I also acknowledge that I provided verbal consent during a recorded telephone call, where applicable and as permitted by law.</strong></p>
                                                            </div></td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                   <table class="divider_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:10px;padding-left:20px;padding-right:20px;padding-top:10px;">

                                                               <div class="alignment" align="center">

                                                                  <table role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                                     <tbody>

                                                                        <tr>

                                                                           <td class="divider_inner" style="font-size: 1px; line-height: 1px; border-top: 1px solid rgb(222, 223, 222);" data-border-top-color="rgb(222, 223, 222)"><span> </span></td>

                                                                        </tr>

                                                                     </tbody>

                                                                  </table>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-28" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="background-color: rgb(255, 255, 255); border-radius: 0px; color: rgb(0, 0, 0); width: 640px;" data-background-color="rgb(255, 255, 255)" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="25%">

                                                   <table class="image_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="width:100%;padding-right:0px;padding-left:0px;padding-top:5px;padding-bottom:5px;">

                                                               <div class="alignment" style="line-height:10px" align="center"><img src="<?= $e($logo_footer_url) ?>" style="display: block; height: auto; border: 0; width: 160px; max-width: 100%;" width="160"></div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                                <td class="column column-2" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="75%">

                                                   <table class="text_block block-2" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:15px;padding-left:10px;padding-right:10px;padding-top:15px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 14px; color: rgb(0, 0, 0); line-height: 1.2; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(0, 0, 0)">

                                                                     
                                                                     <?php foreach ($footer_lines as $line): ?>
<p style="margin: 0; font-size: 12px; text-align: center; mso-line-height-alt: 14.399999999999999px; letter-spacing: normal;"><span style="font-size:12px;"><strong><?= $e($line) ?></strong></span></p>
<?php endforeach; ?>

                                                                  
                                                                  
                                                                  
                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                           <table class="row row-29" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;" width="100%" cellspacing="0" cellpadding="0" border="0" align="center">

                              <tbody>

                                 <tr>

                                    <td>

                                       <table class="row-content stack" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; color: #000000; width: 640px;" width="640" cellspacing="0" cellpadding="0" border="0" align="center">

                                          <tbody>

                                             <tr>

                                                <td class="column column-1" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; font-weight: 400; text-align: left; vertical-align: top; padding-top: 10px; padding-bottom: 10px; border-top: 0px; border-right: 0px; border-bottom: 0px; border-left: 0px;" data-border-top-color="currentcolor" data-border-right-color="currentcolor" data-border-bottom-color="currentcolor" data-border-left-color="currentcolor" width="100%">

                                                   <table class="text_block block-1" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; word-break: break-word;" width="100%" cellspacing="0" cellpadding="0" border="0">

                                                      <tbody>

                                                         <tr>

                                                            <td class="pad" style="padding-bottom:20px;padding-left:10px;padding-right:10px;padding-top:20px;">

                                                               <div style="font-family: sans-serif">

                                                                  <div class="" style="font-size: 12px; color: rgb(28, 28, 28); line-height: 1.2; font-family: Arial, Helvetica Neue, Helvetica, sans-serif;" data-color="rgb(28, 28, 28)">

                                                                     <p style="margin: 0; font-size: 14px; text-align: left; mso-line-height-alt: 16.8px;"><span style="font-size:10px;">The

                                                                        information contained in this e-mail and any accompanying documents is 

                                                                        confidential, may be privileged, and is intended solely for the person 

                                                                        and/or entity to whom it is addressed (i.e. those identified in the "To"

                                                                        and "cc" box). They are the property of A1Limousine Service’s 

                                                                        Corporation. Unauthorized review, use, disclosure, or copying of this 

                                                                        communication, or any part thereof, is strictly prohibited and may be 

                                                                        unlawful. If you have received this e-mail in error, please return the 

                                                                        e-mail and attachments to the sender and delete the e-mail and 

                                                                        attachments and any copy from your system.</span>

                                                                     </p>

                                                                  </div>

                                                               </div>

                                                            </td>

                                                         </tr>

                                                      </tbody>

                                                   </table>

                                                </td>

                                             </tr>

                                          </tbody>

                                       </table>

                                    </td>

                                 </tr>

                              </tbody>

                           </table>

                        </td>

                     </tr>

                  </tbody>

               </table>

               </body></html>