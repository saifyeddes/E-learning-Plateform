<?php
namespace local_elearning_system\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;

class send_expiration_reminders extends scheduled_task {

    public function get_name() {
        return get_string('sendreminderexpirationtask', 'local_elearning_system');
    }

    public function execute() {
        global $DB;

        require_once(__DIR__ . '/../../lib.php');

        local_elearning_system_process_expiration_reminders($DB);
    }
}
