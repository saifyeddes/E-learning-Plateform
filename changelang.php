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
    redirect(new moodle_url($returnurl));
}

$availablelangs = array_keys(get_string_manager()->get_list_of_translations(false));
if (!in_array($lang, $availablelangs, true)) {
    redirect(new moodle_url($returnurl));
}

$SESSION->lang = $lang;
if (isloggedin() && !isguestuser()) {
    set_user_preference('lang', $lang);
}

redirect(new moodle_url($returnurl));
