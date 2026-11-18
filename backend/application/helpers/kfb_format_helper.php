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
     * Normalises a person's name to Title Case whatever was typed:
     * "rashid shahzad", "RASHID SHAHZAD" and "rASHID sHAHZAD" all -> "Rashid Shahzad".
     * Each part after a space, hyphen or apostrophe is capitalised (o'brien -> O'Brien,
     * anne-marie -> Anne-Marie), and a "Mc" prefix keeps its second capital (mcdonald -> McDonald).
     */
    function kfb_title_case($name)
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
        if ($name === '') return '';
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace_callback('/(^|[\s\-\'’])(\p{L})/u', function ($m) {
            return $m[1] . mb_strtoupper($m[2], 'UTF-8');
        }, $name);
        // McDonald, McArthur — Mc + letter: capitalise the letter after "Mc" (only at the start of a part).
        return preg_replace_callback('/(^|[\s\-])Mc(\p{L})/u', function ($m) {
            return $m[1] . 'Mc' . mb_strtoupper($m[2], 'UTF-8');
        }, $name);
    }
}

if (!function_exists('kfb_title_case_company')) {
    /**
     * Gentler than kfb_title_case() for company names: only words typed entirely in lower case are
     * capitalised ("acme limousine" -> "Acme Limousine"). Anything already containing a capital is kept
     * as typed, so acronyms and brands survive ("IBM", "ABC LLC", "McKinsey & Co", "iPhone Rentals").
     */
    function kfb_title_case_company($name)
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
        if ($name === '') return '';
        return preg_replace_callback('/[^\s\-\'’]+/u', function ($m) {
            $w = $m[0];
            if ($w !== mb_strtolower($w, 'UTF-8') || !preg_match('/^\p{L}/u', $w)) return $w;
            return mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($w, 1, NULL, 'UTF-8');
        }, $name);
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
