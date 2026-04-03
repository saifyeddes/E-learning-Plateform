<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'local/elearning_system:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'administrator' => CAP_ALLOW,
        ],
    ],
];