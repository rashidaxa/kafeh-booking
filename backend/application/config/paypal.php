<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking API — PayPal configuration (Orders v2 REST API, redirect checkout)
 *
 * Get sandbox Client ID / Secret at:
 *   https://developer.paypal.com/dashboard/applications/sandbox
 * (Apps & Credentials → Sandbox → Create App)
 *
 * Never put real credentials here — this file is tracked by git. Set
 * PAYPAL_CLIENT_ID / PAYPAL_CLIENT_SECRET in backend/.env instead
 * (gitignored; see .env.example).
 */

$config['client_id']     = getenv('PAYPAL_CLIENT_ID')     ?: 'YOUR_SANDBOX_CLIENT_ID';
$config['client_secret'] = getenv('PAYPAL_CLIENT_SECRET') ?: 'YOUR_SANDBOX_CLIENT_SECRET';
$config['environment']   = getenv('PAYPAL_ENV') ?: 'sandbox';  // 'sandbox' | 'live'
