<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Aviationstack (flight data API) configuration
 *
 * Get a free API key at https://aviationstack.com/signup
 * The free tier allows 100 API calls/month, which is enough for
 * a small-to-medium limo booking site.
 *
 * Leave access_key as 'YOUR_KEY' to disable the lookup entirely;
 * the widget will then fall back to manual flight entry.
 */
$config['aviationstack_access_key'] = 'YOUR_KEY';
$config['aviationstack_base_url']   = 'http://api.aviationstack.com/v1/';
$config['aviationstack_timeout']    = 8; // seconds
