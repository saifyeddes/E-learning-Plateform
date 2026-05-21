<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/authlib.php');
require_once($CFG->dirroot . '/login/lib.php');
require_once($CFG->dirroot . '/login/set_password_form.php');

$token = required_param('token', PARAM_ALPHANUM);

$PAGE->set_url('/local/elearning_system/set_password.php', [
    'token' => $token,
]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
$PAGE->set_title('Reset password');
$PAGE->set_heading('Reset password');

$PAGE->requires->css(new moodle_url('/local/elearning_system/styles/auth.css?v=' . time()));

core_login_process_password_set($token);
exit;