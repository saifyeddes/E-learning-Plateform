<?php

defined('MOODLE_INTERNAL') || die();

$addons = [
    'local_elearning_system' => [
        'handlers' => [
            'coursestore' => [
                'displaydata' => [
                    'title' => 'Dourouss Store',
                    'icon' => 'school',
                    'class' => '',
                ],
                'delegate' => 'CoreMainMenuDelegate',
                'method' => 'mobile_course_store',
                'offlinefunctions' => [],
            ],
        ],
        'lang' => [
            ['pluginname', 'local_elearning_system'],
        ],
    ],
];