<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shared SMTP mailer — replaces the old plain mail() calls (Api.php's
 * OTP/reset emails, Admin_api.php's booking confirmation), which
 * depend on a local MTA (sendmail) being configured on the server.
 * Most hosts don't set that up, which is why those emails were never
 * actually arriving on the live server despite mail() reporting no
 * error (it queues to a local MTA that doesn't exist, so there's
 * nothing to fail loudly).
 *
 * Uses the same SMTP mailbox/credentials the bookingOld project already
 * sends real production email through successfully (see
 * application/config/kafeh.php's smtp_* values, copied from bookingOld's
 * application/config/constants.php) via the same vendored PHPMailer.
 */
class Mailer
{
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('crmsync');
        $this->CI->load->library('phpmailer_library');
    }

    /**
     * Sends one email. Returns TRUE/FALSE — callers treat this as
     * best-effort (log and move on), same as the mail() calls it
     * replaces; a failed email must never block the request it's
     * attached to (registration, password reset, booking confirmation).
     */
    public function send($to, $subject, $body, $isHtml = FALSE)
    {
        try {
            $mail = $this->CI->phpmailer_library->load();
            // PHPMailer defaults to iso-8859-1 — our subjects/bodies use
            // UTF-8 characters (e.g. the em dash in "Verify your email —
            // your code is..."), so without this the raw UTF-8 bytes get
            // sent mislabeled as iso-8859-1 and render as "â€”" garbage
            // in the recipient's mail client.
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host       = $this->CI->config->item('smtp_host');
            $mail->SMTPAuth   = TRUE;
            $mail->Username   = $this->CI->config->item('smtp_email');
            $mail->Password   = $this->CI->config->item('smtp_password');
            $mail->SMTPSecure = $this->CI->config->item('smtp_secure');
            $mail->Port       = $this->CI->config->item('smtp_port');
            $mail->setFrom($this->CI->config->item('smtp_email'), 'A1 Limousine');
            $mail->addAddress($to);
            $mail->addReplyTo($this->CI->config->item('smtp_email'));
            $mail->Subject = $subject;
            $mail->isHTML($isHtml);
            $mail->Body = $body;

            $ok = $mail->send();
            if (!$ok) {
                log_message('error', '[Mailer] send to ' . $to . ' failed: ' . $mail->ErrorInfo);
            }
            return $ok;
        } catch (Throwable $e) {
            log_message('error', '[Mailer] send to ' . $to . ' threw: ' . $e->getMessage());
            return FALSE;
        }
    }
}
