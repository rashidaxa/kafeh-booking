<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer emails (HTML, shared notification layout — see Mailer::send_notification()) shared by the public API (Api.php) and the
 * admin portal's user management (Admin.php) so the wording lives in one
 * place: verification code, password-reset link, "your email was changed".
 * All best-effort via the Mailer library (SMTP) — callers ignore failures.
 */
#[\AllowDynamicProperties]
class Customer_mail
{
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->library('mailer');
    }

    protected function _name(array $customer)
    {
        $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        return $name !== '' ? $name : 'Customer';
    }

    /** The customer's company (for branding); NULL falls back to A1 Limousines in Mailer::branding(). */
    protected function _company(array $customer)
    {
        $this->CI->load->model('Company_model');
        return $this->CI->Company_model->get($customer['company_id'] ?? 1) ?: NULL;
    }

    protected function _e($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    public function send_verification(array $customer, $otp)
    {
        return $this->CI->mailer->send_notification(
            $customer['email'],
            'Verify your email — your code is ' . $otp,
            [
                'heading'    => 'Verify Your Email',
                'preview'    => 'Your verification code is ' . $otp . '. It expires in 10 minutes.',
                'greeting'   => $this->_name($customer),
                'paragraphs' => ['Thank you for creating an account. Enter the verification code below to verify your email and activate your account.'],
                'highlight'  => [
                    'title' => 'Your Verification Code',
                    'html'  => '<span style="font-size:28px;letter-spacing:6px;font-weight:bold;color:#0876a9;">' . $this->_e($otp) . '</span><br>This code expires in 10 minutes.',
                ],
                'closing'    => 'If you didn\'t request this, you can safely ignore this email.',
            ],
            $this->_company($customer)
        );
    }

    public function send_password_reset(array $customer, $resetLink)
    {
        return $this->CI->mailer->send_notification(
            $customer['email'],
            'Reset your password',
            [
                'heading'    => 'Reset Your Password',
                'preview'    => 'Use the link in this email to choose a new password. It expires in 1 hour.',
                'greeting'   => $this->_name($customer),
                'paragraphs' => [
                    'We received a request to reset your password. Click the button below to choose a new one. This link expires in 1 hour.',
                    'If the button does not work, copy and paste this link into your browser:<br><a href="' . $this->_e($resetLink) . '" style="color:#0876a9;word-break:break-all;">' . $this->_e($resetLink) . '</a>',
                ],
                'button'     => ['label' => 'Reset Password', 'url' => $resetLink],
                'closing'    => 'If you didn\'t request this, you can safely ignore this email.',
            ],
            $this->_company($customer)
        );
    }

    /**
     * Tell the customer their account email was changed by an administrator.
     * Sent to BOTH the old and the new address, branded with the company
     * name and its contact email as Reply-To.
     */
    public function send_email_changed(array $customer, $oldEmail, $newEmail, ?array $company = NULL)
    {
        $companyName = !empty($company['name']) ? $company['name'] : 'A1 Limousines';
        $subject = 'The email on your ' . $companyName . ' account was changed';
        $contact = !empty($company['contact_email']) ? ' at ' . $this->_e($company['contact_email']) : '';
        $vars = [
            'heading'    => 'Your Account Email Was Changed',
            'preview'    => 'The email address on your ' . $companyName . ' account was changed by an administrator.',
            'greeting'   => $this->_name($customer),
            'paragraphs' => [
                'The email address on your ' . $this->_e($companyName) . ' account was changed by an administrator.',
                'Please log in with your new email address. You will be asked to verify it with a code the first time you log in.',
            ],
            'details'    => ['Old email' => $oldEmail, 'New email' => $newEmail],
            'closing'    => 'If you did not expect this change, please contact us' . $contact . '.',
        ];

        $ok = $this->CI->mailer->send_notification($newEmail, $subject, $vars, $company);
        if (strcasecmp($oldEmail, $newEmail) !== 0) {
            $this->CI->mailer->send_notification($oldEmail, $subject, $vars, $company);
        }
        return $ok;
    }
}
