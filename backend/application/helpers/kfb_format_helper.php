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

if (!function_exists('kfb_title_case')) {
    /**
     * The normal rule, used for every person's name and every company name: the first letter of each word
     * (words are separated by spaces) is a capital and the rest is lower case, whatever was typed:
     * "rashid shahzad", "RASHID SHAHZAD" and "rASHID sHAHZAD" all -> "Rashid Shahzad";
     * "o'brien" -> "O'brien", "mcdonald" -> "Mcdonald", "ACME LIMOUSINE LLC" -> "Acme Limousine Llc", "WmmW" -> "Wmmw".
     */
    function kfb_title_case($name)
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
        if ($name === '') return '';
        $name = mb_strtolower($name, 'UTF-8');
        return preg_replace_callback('/(^|\s)(\p{L})/u', function ($m) {
            return $m[1] . mb_strtoupper($m[2], 'UTF-8');
        }, $name);
    }
}

if (!function_exists('kfb_title_case_company')) {
    /** Company names follow the same rule as people's names — see kfb_title_case(). */
    function kfb_title_case_company($name)
    {
        return kfb_title_case($name);
    }
}

if (!function_exists('kfb_phone')) {
    /**
     * US/Canada numbers as xxx-xxx-xxxx (a leading country code 1 is dropped).
     * Anything else — international numbers, extensions, text — is returned as typed.
     */
    function kfb_phone($p)
    {
        $p = trim((string)$p);
        if ($p === '') return '';
        $d = preg_replace('/\D+/', '', $p);
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        if (strlen($d) === 10 && preg_match('/^[\d\s().+\-]+$/', $p)) {
            return substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6);
        }
        return $p;
    }
}
