<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin-portal display formats, in one place:
 *   date      MM-DD-YYYY        (change KFB_DATE_FORMAT to switch to e.g. 'm/d/Y')
 *   time      hh:mm AM/PM       (12-hour clock)
 *   datetime  MM-DD-YYYY hh:mm AM/PM
 * All three return '—' for empty/invalid input. Display only: stored values stay ISO.
 */
if (!defined('KFB_DATE_FORMAT')) define('KFB_DATE_FORMAT', 'm-d-Y');
if (!defined('KFB_TIME_FORMAT')) define('KFB_TIME_FORMAT', 'h:i A');

if (!function_exists('kfb_hm')) {
    function kfb_hm($t)
    {
        $ts = $t ? strtotime((string)$t) : FALSE;
        return $ts ? date(KFB_TIME_FORMAT, $ts) : '—';
    }
}
if (!function_exists('kfb_date')) {
    function kfb_date($d)
    {
        $ts = ($d && $d !== '0000-00-00' && $d !== '0000-00-00 00:00:00') ? strtotime((string)$d) : FALSE;
        return $ts ? date(KFB_DATE_FORMAT, $ts) : '—';
    }
}
if (!function_exists('kfb_datetime')) {
    function kfb_datetime($d)
    {
        $ts = ($d && $d !== '0000-00-00 00:00:00') ? strtotime((string)$d) : FALSE;
        return $ts ? date(KFB_DATE_FORMAT . ' ' . KFB_TIME_FORMAT, $ts) : '—';
    }
}
