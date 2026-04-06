<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the elearning_system plugin.
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_elearning_system_upgrade($oldversion) {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026032701) {

        // Define table elearning_products.
        $table = new xmldb_table('elearning_products');

        // Add categoryid field to elearning_products.
        $field = new xmldb_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, false, false, null, 'description');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add courseid field to elearning_products.
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, false, false, null, 'categoryid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add image field to elearning_products.
        $field = new xmldb_field('image', XMLDB_TYPE_TEXT, null, null, false, false, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add video field to elearning_products.
        $field = new xmldb_field('video', XMLDB_TYPE_TEXT, null, null, false, false, null, 'image');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add saleprice field to elearning_products.
        $field = new xmldb_field('saleprice', XMLDB_TYPE_NUMBER, '10,2', null, false, false, null, 'price');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add status field to elearning_products.
        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '50', null, true, false, 'draft', 'video');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add type field to elearning_products.
        $field = new xmldb_field('type', XMLDB_TYPE_CHAR, '50', null, true, false, 'free', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add showinstructor field to elearning_products.
        $field = new xmldb_field('showinstructor', XMLDB_TYPE_INTEGER, '1', null, true, false, 0, 'type');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add showratings field to elearning_products.
        $field = new xmldb_field('showratings', XMLDB_TYPE_INTEGER, '1', null, true, false, 0, 'showinstructor');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add showenrolled field to elearning_products.
        $field = new xmldb_field('showenrolled', XMLDB_TYPE_INTEGER, '1', null, true, false, 0, 'showratings');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add timemodified field to elearning_products.
        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, true, false, '0', 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define table elearning_orders.
        $table = new xmldb_table('elearning_orders');
        if (!$dbman->table_exists($table)) {
            $table = new xmldb_table('elearning_orders');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('productid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('amount', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026032701, 'local', 'elearning_system');
    }

    if ($oldversion < 2026040200) {

        $table = new xmldb_table('elearning_products');

        // Add isbundle field to elearning_products.
        $field = new xmldb_field('isbundle', XMLDB_TYPE_INTEGER, '1', null, true, false, 0, 'showenrolled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add bundleitems field to elearning_products.
        $field = new xmldb_field('bundleitems', XMLDB_TYPE_TEXT, null, null, false, false, null, 'isbundle');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026040200, 'local', 'elearning_system');
    }

    if ($oldversion < 2026040202) {

        $table = new xmldb_table('elearning_orders');

        // Add promocode field to elearning_orders.
        $field = new xmldb_field('promocode', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'amount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add discountamount field to elearning_orders.
        $field = new xmldb_field('discountamount', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, 0, 'promocode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026040202, 'local', 'elearning_system');
    }

    if ($oldversion < 2026040503) {

        $table = new xmldb_table('elearning_orders');

        $field = new xmldb_field('durationmonths', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 1, 'discountamount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, false, false, null, 'durationmonths');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Backfill existing orders as 1 month subscriptions from purchase date.
        $orders = $DB->get_records('elearning_orders', null, '', 'id,timecreated,durationmonths,expiresat');
        foreach ($orders as $order) {
            $record = new stdClass();
            $record->id = (int)$order->id;
            $record->durationmonths = !empty($order->durationmonths) ? (int)$order->durationmonths : 1;
            if (!empty($order->expiresat)) {
                $record->expiresat = (int)$order->expiresat;
            } else {
                $months = max(1, min(24, (int)$record->durationmonths));
                $date = new DateTime('@' . (int)$order->timecreated);
                $date->setTimezone(core_date::get_server_timezone_object());
                $date->modify('+' . $months . ' months');
                $record->expiresat = (int)$date->getTimestamp();
            }
            $DB->update_record('elearning_orders', $record);
        }

        upgrade_plugin_savepoint(true, 2026040503, 'local', 'elearning_system');
    }

    if ($oldversion < 2026040601) {
        require_once($CFG->dirroot . '/local/elearning_system/lib.php');
        local_elearning_system_sync_enrolments_to_course_enddates($DB);
        upgrade_plugin_savepoint(true, 2026040601, 'local', 'elearning_system');
    }

    return true;
}
