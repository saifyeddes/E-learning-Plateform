<?php

defined('MOODLE_INTERNAL') || die();

function local_elearning_system_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    if ($filearea !== 'productimage') {
        return false;
    }

    $fs = get_file_storage();
    $filename = array_pop($args);

    $filepath = '/';

    $file = $fs->get_file($context->id, 'local_elearning_system', $filearea, 0, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

function local_elearning_system_extends_navigation(global_navigation $navigation) {
    // Show Store only for site admins; regular users only see Accueil.
    if (!isloggedin() || isguestuser() || !is_siteadmin()) {
        return;
    }

    $navigation->add(
        'Store',
        new moodle_url('/local/elearning_system/store.php'),
        global_navigation::TYPE_CUSTOM,
        'store'
    );
}

/**
 * Normalize cart structure to ['qty' => int, 'durationmonths' => int].
 *
 * @param array $cart
 * @return void
 */
function local_elearning_system_normalise_cart_structure(array &$cart): void {
    foreach ($cart as $productid => $value) {
        if (is_array($value)) {
            $qty = max(1, (int)($value['qty'] ?? 1));
            $months = max(1, min(24, (int)($value['durationmonths'] ?? 1)));
            $cart[$productid] = [
                'qty' => $qty,
                'durationmonths' => $months,
            ];
            continue;
        }

        $qty = max(1, (int)$value);
        $cart[$productid] = [
            'qty' => $qty,
            'durationmonths' => 1,
        ];
    }
}

/**
 * Get a normalized cart item.
 *
 * @param array $cart
 * @param int $productid
 * @return array{qty:int,durationmonths:int}
 */
function local_elearning_system_get_cart_item(array $cart, int $productid): array {
    $raw = $cart[$productid] ?? ['qty' => 1, 'durationmonths' => 1];
    if (!is_array($raw)) {
        $raw = ['qty' => (int)$raw, 'durationmonths' => 1];
    }

    return [
        'qty' => max(1, (int)($raw['qty'] ?? 1)),
        'durationmonths' => max(1, min(24, (int)($raw['durationmonths'] ?? 1))),
    ];
}

/**
 * Sum all cart quantities.
 *
 * @param array $cart
 * @return int
 */
function local_elearning_system_cart_count(array $cart): int {
    $count = 0;
    foreach ($cart as $value) {
        if (is_array($value)) {
            $count += max(1, (int)($value['qty'] ?? 1));
        } else {
            $count += max(1, (int)$value);
        }
    }
    return $count;
}

/**
 * Calculate expiration timestamp from purchase time and months.
 *
 * @param int $purchasetime
 * @param int $months
 * @return int
 */
function local_elearning_system_calculate_expiration(int $purchasetime, int $months): int {
    $months = max(1, min(24, $months));
    $date = new DateTime('@' . $purchasetime);
    $date->setTimezone(core_date::get_server_timezone_object());
    $date->modify('+' . $months . ' months');
    return (int)$date->getTimestamp();
}

/**
 * Return true when order is active considering expiresat column.
 *
 * @param stdClass $order
 * @param array $ordercolumns
 * @return bool
 */
function local_elearning_system_is_order_active(stdClass $order, array $ordercolumns): bool {
    if (!isset($ordercolumns['expiresat'])) {
        return true;
    }

    $expiresat = (int)($order->expiresat ?? 0);
    if ($expiresat <= 0) {
        return true;
    }

    return time() <= $expiresat;
}

/**
 * Return all course ids unlocked by a product or bundle.
 *
 * @param int $productid
 * @param moodle_database $DB
 * @return int[]
 */
function local_elearning_system_get_product_courseids_by_id(int $productid, moodle_database $DB): array {
    $product = $DB->get_record('elearning_products', ['id' => $productid], 'id,courseid,isbundle,bundleitems', IGNORE_MISSING);
    if (!$product) {
        return [];
    }

    $courseids = [];
    if (!empty($product->courseid)) {
        $courseids[] = (int)$product->courseid;
    }

    if (!empty($product->isbundle) && !empty($product->bundleitems)) {
        $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$product->bundleitems)))));
        if (!empty($bundleitemids)) {
            $bundleproducts = $DB->get_records_list('elearning_products', 'id', $bundleitemids, '', 'id,courseid');
            foreach ($bundleproducts as $bundleproduct) {
                if (!empty($bundleproduct->courseid)) {
                    $courseids[] = (int)$bundleproduct->courseid;
                }
            }
        }
    }

    return array_values(array_unique(array_filter($courseids)));
}

/**
 * Get the exact enrolment end timestamp for an order.
 *
 * @param stdClass $order
 * @return int
 */
function local_elearning_system_get_order_expiresat(stdClass $order): int {
    $purchasetime = (int)($order->timecreated ?? 0);
    $months = max(1, min(24, (int)($order->durationmonths ?? 1)));

    if ($purchasetime <= 0) {
        $purchasetime = time();
    }

    return local_elearning_system_calculate_expiration($purchasetime, $months);
}

/**
 * Update manual enrolments so their end date matches the course end date.
 *
 * @param int $courseid
 * @param int $userid
 * @param int $timeend
 * @return void
 */
function local_elearning_system_update_manual_enrolment_enddate(int $courseid, int $userid, int $timeend): void {
    global $DB;

    if ($courseid <= 0 || $userid <= 0) {
        return;
    }

    $instances = enrol_get_instances($courseid, true);
    foreach ($instances as $instance) {
        if ($instance->enrol !== 'manual') {
            continue;
        }

        $ue = $DB->get_record('user_enrolments', [
            'enrolid' => (int)$instance->id,
            'userid' => $userid,
        ], '*', IGNORE_MISSING);

        if (!$ue) {
            continue;
        }

        if ((int)$ue->timeend !== $timeend) {
            $ue->timeend = $timeend;
            $DB->update_record('user_enrolments', $ue);
        }
    }
}

/**
 * Sync all manual enrolments created by this plugin to exact order end dates.
 *
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_sync_enrolments_to_course_enddates(moodle_database $DB): void {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return;
    }

    $products = $DB->get_records('elearning_products', null, '', 'id,courseid,isbundle,bundleitems');
    if (empty($products)) {
        return;
    }

    foreach ($products as $product) {
        $courseids = [];
        if (!empty($product->courseid)) {
            $courseids[] = (int)$product->courseid;
        }

        if (!empty($product->isbundle) && !empty($product->bundleitems)) {
            $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$product->bundleitems)))));
            if (!empty($bundleitemids)) {
                $bundleproducts = $DB->get_records_list('elearning_products', 'id', $bundleitemids, '', 'id,courseid');
                foreach ($bundleproducts as $bundleproduct) {
                    if (!empty($bundleproduct->courseid)) {
                        $courseids[] = (int)$bundleproduct->courseid;
                    }
                }
            }
        }

        $courseids = array_values(array_unique(array_filter($courseids)));
        if (empty($courseids)) {
            continue;
        }

        $orders = $DB->get_records('elearning_orders', ['productid' => (int)$product->id], 'id ASC', 'id,userid,timecreated,durationmonths');
        foreach ($orders as $order) {
            foreach ($courseids as $courseid) {
                $timeend = local_elearning_system_get_order_expiresat($order);
                local_elearning_system_update_manual_enrolment_enddate((int)$courseid, (int)$order->userid, $timeend);
            }
        }
    }
}

/**
 * Unenrol user from courses unlocked by a product/bundle.
 *
 * @param int $userid
 * @param int $productid
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_unenrol_user_for_product(int $userid, int $productid, moodle_database $DB): void {
    require_once($GLOBALS['CFG']->libdir . '/enrollib.php');

    $courseids = local_elearning_system_get_product_courseids_by_id($productid, $DB);
    if (empty($courseids)) {
        return;
    }

    $manualplugin = enrol_get_plugin('manual');
    if (!$manualplugin) {
        return;
    }

    foreach ($courseids as $courseid) {
        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $instance) {
            if ($instance->enrol !== 'manual') {
                continue;
            }

            $ue = $DB->get_record('user_enrolments', [
                'enrolid' => (int)$instance->id,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);

            if ($ue) {
                $manualplugin->unenrol_user($instance, $userid);
            }
        }
    }
}

/**
 * Remove access for expired orders of a user.
 *
 * @param int $userid
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_cleanup_expired_orders_for_user(int $userid, moodle_database $DB): void {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return;
    }

    $ordercolumns = $DB->get_columns('elearning_orders');
    if (!isset($ordercolumns['expiresat'])) {
        return;
    }

    $orders = $DB->get_records_select('elearning_orders', 'userid = :userid AND expiresat > 0 AND expiresat < :now', [
        'userid' => $userid,
        'now' => time(),
    ], '', 'id,productid,expiresat');

    foreach ($orders as $order) {
        local_elearning_system_unenrol_user_for_product($userid, (int)$order->productid, $DB);
    }
}

/**
 * Check active purchase coverage (direct or bundle item).
 *
 * @param int $userid
 * @param int $productid
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_is_product_covered_by_active_purchase(int $userid, int $productid, moodle_database $DB): bool {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return false;
    }

    $ordercolumns = $DB->get_columns('elearning_orders');

    $orders = $DB->get_records('elearning_orders', ['userid' => $userid], '', 'id,productid,expiresat');
    foreach ($orders as $order) {
        if (!local_elearning_system_is_order_active($order, $ordercolumns)) {
            continue;
        }

        if ((int)$order->productid === $productid) {
            return true;
        }

        $bundleproduct = $DB->get_record('elearning_products', ['id' => (int)$order->productid], 'id,isbundle,bundleitems', IGNORE_MISSING);
        if (!$bundleproduct || empty($bundleproduct->isbundle) || empty($bundleproduct->bundleitems)) {
            continue;
        }

        $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$bundleproduct->bundleitems)))));
        if (in_array($productid, $bundleitemids, true)) {
            return true;
        }
    }

    return false;
}
