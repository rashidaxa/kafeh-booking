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
#[\AllowDynamicProperties]
class Mailer
{
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('crmsync');
        $this->CI->load->library('phpmailer_library');
        $this->CI->load->model('Company_model');
    }

    const A1_HEADER_LOGO = 'https://grounddispatch.com/a1-limousine-logo.png';

    /**
     * Name / logo / phone / website for the shared notification layout.
     * Falls back to A1 Limousines' published details when the company is
     * unknown or has none set; the website link only shows for A1 itself.
     */
    public function branding($company = NULL)
    {
        $isA1  = !$company || (int)($company['id'] ?? 1) === 1;
        $phone = trim((string)($company['phone_1'] ?? ''));
        if ($phone === '') $phone = '1 (800) 781-4700';
        $logo = $company ? $this->CI->Company_model->logo_url($company) : NULL;
        return [
            'company_name'  => !empty($company['name']) ? $company['name'] : 'A1 Limousines',
            'logo_url'      => (!$logo || $isA1) ? self::A1_HEADER_LOGO : $logo,
            'phone_display' => $phone,
            'phone_tel'     => $this->_tel($phone),
            'website'       => $isA1 ? 'www.A1Limousines.com' : '',
        ];
    }

    /** "1 (800) 781-4700" / "(800) 781-4700" / "+44 20 …" -> a dialable tel: value. */
    protected function _tel($phone)
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strpos(trim($phone), '+') === 0) return '+' . $digits;
        if (strlen($digits) === 10) return '+1' . $digits;
        return '+' . $digits;
    }

    /**
     * Sends a notification through the shared layout (views/emails/notification.php).
     * $vars: heading, preview, greeting, paragraphs, highlight, details, button, closing
     * (see that view). A plain-text twin is generated from the same content.
     */
    public function send_notification($to, $subject, array $vars, $company = NULL)
    {
        $brand = $this->branding($company);
        $html  = $this->CI->load->view('emails/notification', array_merge($brand, $vars), TRUE);

        $text = [];
        if (isset($vars['greeting']) && $vars['greeting'] !== NULL) $text[] = 'Dear ' . $vars['greeting'] . ',';
        foreach (($vars['paragraphs'] ?? []) as $p) $text[] = trim(html_entity_decode(strip_tags($p), ENT_QUOTES, 'UTF-8'));
        if (!empty($vars['highlight'])) $text[] = $vars['highlight']['title'] . ': ' . trim(html_entity_decode(strip_tags($vars['highlight']['html']), ENT_QUOTES, 'UTF-8'));
        foreach (($vars['details'] ?? []) as $k => $v) $text[] = $k . ': ' . $v;
        if (!empty($vars['button'])) $text[] = $vars['button']['label'] . ': ' . $vars['button']['url'];
        $text[] = $brand['company_name'] . ' | ' . $brand['phone_display'];

        return $this->send($to, $subject, $html, TRUE, [
            'from_name' => $brand['company_name'],
            'reply_to'  => $company['contact_email'] ?? NULL,
            'alt_body'  => implode("\n\n", $text),
        ]);
    }

    /**
     * Sends one email. Returns TRUE/FALSE — callers treat this as
     * best-effort (log and move on), same as the mail() calls it
     * replaces; a failed email must never block the request it's
     * attached to (registration, password reset, booking confirmation).
     */
    public function send($to, $subject, $body, $isHtml = FALSE, array $opts = [])
    {
        // $opts: from_name, reply_to (a company's contact email), alt_body (plain-text twin of an HTML body)
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
            $mail->setFrom($this->CI->config->item('smtp_email'), !empty($opts['from_name']) ? $opts['from_name'] : 'A1 Limousine');
            $mail->addAddress($to);
            $mail->addReplyTo(!empty($opts['reply_to']) && filter_var($opts['reply_to'], FILTER_VALIDATE_EMAIL) ? $opts['reply_to'] : $this->CI->config->item('smtp_email'));
            $mail->Subject = $subject;
            $mail->isHTML($isHtml);
            $mail->Body = $body;
            if ($isHtml && !empty($opts['alt_body'])) $mail->AltBody = $opts['alt_body'];

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
