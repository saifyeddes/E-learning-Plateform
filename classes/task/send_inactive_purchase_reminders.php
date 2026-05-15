<?php

namespace local_elearning_system\task;

defined('MOODLE_INTERNAL') || die();

class send_inactive_purchase_reminders extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'Send inactive purchase reminder emails';
    }

    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/elearning_system/lib.php');

        local_elearning_system_process_inactive_purchase_reminders($DB);
    }
}