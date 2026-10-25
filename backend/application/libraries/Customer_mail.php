<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Plain-text customer emails shared by the public API (Api.php) and the
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
        return $name !== '' ? $name : 'there';
    }

    public function send_verification(array $customer, $otp)
    {
        return $this->CI->mailer->send(
            $customer['email'],
            'Verify your email — your code is ' . $otp,
            "Hi " . $this->_name($customer) . ",\n\n" .
            "Your verification code is: " . $otp . "\n\n" .
            "Enter this code to verify your email and activate your account (expires in 10 minutes).\n\n" .
            "If you didn't request this, you can safely ignore this email.\n"
        );
    }

    public function send_password_reset(array $customer, $resetLink)
    {
        return $this->CI->mailer->send(
            $customer['email'],
            'Reset your password',
            "Hi " . $this->_name($customer) . ",\n\n" .
            "We received a request to reset your password. Click the link below to choose a new one " .
            "(this link expires in 1 hour):\n\n" . $resetLink . "\n\n" .
            "If you didn't request this, you can safely ignore this email.\n"
        );
    }

    /**
     * Tell the customer their account email was changed by an administrator.
     * Sent to BOTH the old and the new address, branded with the company
     * name and its contact email as Reply-To.
     */
    public function send_email_changed(array $customer, $oldEmail, $newEmail, ?array $company = NULL)
    {
        $companyName = !empty($company['name']) ? $company['name'] : 'A1 Limousine';
        $subject = 'The email on your ' . $companyName . ' account was changed';
        $opts = ['from_name' => $companyName, 'reply_to' => $company['contact_email'] ?? NULL];
        $name = $this->_name($customer);

        $body = "Hi {$name},\n\n" .
            "The email address on your {$companyName} account was changed by an administrator.\n\n" .
            "Old email: {$oldEmail}\n" .
            "New email: {$newEmail}\n\n" .
            "Please log in with your new email address. You will be asked to verify it with a code the first time you log in.\n\n" .
            "If you did not expect this change, please contact us" . (!empty($company['contact_email']) ? " at {$company['contact_email']}" : '') . ".\n";

        $ok = $this->CI->mailer->send($newEmail, $subject, $body, FALSE, $opts);
        if (strcasecmp($oldEmail, $newEmail) !== 0) {
            $this->CI->mailer->send($oldEmail, $subject, $body, FALSE, $opts);
        }
        return $ok;
    }
}
