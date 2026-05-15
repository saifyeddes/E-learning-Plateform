<?php
namespace local_elearning_system\output;

defined('MOODLE_INTERNAL') || die();

use context_system;
use moodle_url;

class mobile {

    public static function mobile_course_store($args) {
        global $OUTPUT, $USER;

        require_login();

        $context = context_system::instance();

        $data = [
            'userid' => $USER->id,
            'fullname' => fullname($USER),
            'storeurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
            'mycoursesurl' => (new moodle_url('/local/elearning_system/mycourses.php'))->out(false),
            'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
        ];

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template(
                        'local_elearning_system/mobile_store',
                        $data
                    ),
                ],
            ],
            'javascript' => '',
            'otherdata' => '',
            'files' => [],
        ];
    }
}