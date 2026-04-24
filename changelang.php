<?php

define('NO_OUTPUT_BUFFERING', true);
require('../../config.php');

$lang = optional_param('lang', '', PARAM_LANG);
$returnurl = optional_param('return', '/', PARAM_LOCALURL);

if ($returnurl === '' || $returnurl[0] !== '/') {
    $returnurl = '/';
}

$supported = ['en', 'fr', 'ar'];
if (!in_array($lang, $supported, true)) {
    redirect(new moodle_url($returnurl, ['lang' => 'en']));
}

$SESSION->lang = $lang;
$SESSION->forcelang = $lang;
$SESSION->local_elearning_system_lang = $lang;
setcookie('local_elearning_system_lang', $lang, time() + (60 * 60 * 24 * 365), '/');
if (isset($USER) && is_object($USER)) {
    $USER->lang = $lang;
}
if (isloggedin() && !isguestuser()) {
    set_user_preference('lang', $lang);
}

if (function_exists('force_current_language')) {
    force_current_language($lang);
}
if (function_exists('fix_current_language')) {
    fix_current_language($lang);
}

redirect(new moodle_url($returnurl, ['lang' => $lang]));
