<?php

define('NO_OUTPUT_BUFFERING', true);
require('../../config.php');
require_once(__DIR__ . '/lib.php');

$lang = optional_param('lang', '', PARAM_LANG);
$returnurl = optional_param('return', '/', PARAM_LOCALURL);

if ($returnurl === '' || $returnurl[0] !== '/') {
    $returnurl = '/';
}

if (!in_array($lang, ['en', 'fr', 'ar'], true)) {
    redirect(new moodle_url($returnurl, ['lang' => 'en']));
}

local_elearning_system_apply_requested_language();

redirect(new moodle_url($returnurl, ['lang' => $lang]));
