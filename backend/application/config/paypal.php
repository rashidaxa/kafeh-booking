<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh — PayPal Sandbox configuration
 *
 * Get your sandbox credentials at:
 *   https://developer.paypal.com/dashboard/applications/sandbox
 */

$config['client_id']     = getenv('PAYPAL_CLIENT_ID')  ?: 'YOUR_SANDBOX_CLIENT_ID';
$config['client_secret'] = getenv('PAYPAL_CLIENT_SECRET') ?: 'YOUR_SANDBOX_CLIENT_SECRET';
$config['environment']   = getenv('PAYPAL_ENV') ?: 'sandbox';  // 'sandbox' | 'live'
