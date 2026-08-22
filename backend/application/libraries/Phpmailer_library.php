<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/** Loads PHPMailer — vendored the same way bookingOld's own phpmailer_library does, no Composer dependency. */
class Phpmailer_library
{
    public function load()
    {
        require_once FCPATH . 'vendor/phpmailer/phpmailer/src/Exception.php';
        require_once FCPATH . 'vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once FCPATH . 'vendor/phpmailer/phpmailer/src/SMTP.php';

        return new PHPMailer(TRUE);
    }
}
