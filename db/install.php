<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_elearning_system_install() {
    global $CFG;

    set_config(
        'forgottenpasswordurl',
        $CFG->wwwroot . '/local/elearning_system/forgot_password.php'
    );
}