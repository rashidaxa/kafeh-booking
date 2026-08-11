<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh — Stripe configuration
 *
 * Get your test keys at:
 *   https://dashboard.stripe.com/test/apikeys
 *
 * Never put real keys here — this file is tracked by git. Set
 * STRIPE_SECRET_KEY / STRIPE_PUBLISHABLE_KEY in backend/.env instead
 * (gitignored; see .env.example).
 */

$config['secret_key']      = getenv('STRIPE_SECRET_KEY')      ?: 'YOUR_STRIPE_SECRET_KEY';
$config['publishable_key'] = getenv('STRIPE_PUBLISHABLE_KEY') ?: 'YOUR_STRIPE_PUBLISHABLE_KEY';
