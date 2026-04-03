<?php

require('../../config.php');

$productid = required_param('id', PARAM_INT);
$return = optional_param('return', 'cart', PARAM_ALPHA);

$context = context_system::instance();
$PAGE->set_context($context);

global $DB;

function local_elearning_system_is_product_covered_by_purchase(int $userid, int $productid, moodle_database $DB): bool {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return false;
    }

    if ($DB->record_exists('elearning_orders', ['userid' => $userid, 'productid' => $productid])) {
        return true;
    }

    $product = $DB->get_record('elearning_products', ['id' => $productid], 'id,courseid', IGNORE_MISSING);
    if ($product && !empty($product->courseid)) {
        $coursecontext = context_course::instance((int)$product->courseid, IGNORE_MISSING);
        /** @var context $coursecontext */
        if ($coursecontext && is_enrolled($coursecontext, $userid, '', true)) {
            return true;
        }
    }

    $orders = $DB->get_records('elearning_orders', ['userid' => $userid], '', 'id,productid');
    foreach ($orders as $order) {
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

$product = $DB->get_record('elearning_products', ['id' => $productid], 'id,type,status,price,saleprice,isbundle', MUST_EXIST);

$price = !empty($product->price) ? (float)$product->price : 0.0;
$saleprice = !empty($product->saleprice) ? (float)$product->saleprice : 0.0;
$displayprice = $saleprice > 0 ? $saleprice : $price;
$status = strtolower(trim((string)($product->status ?? '')));
$rawtype = strtolower(trim((string)($product->type ?? '')));

if ($displayprice <= 0) {
    $type = 'free';
} else if (in_array($rawtype, ['paid', 'subscription', 'subscroiption', 'subcription', 'subscribe', 'premium'])) {
    $type = 'paid';
} else {
    $type = 'free';
}

if (empty($product->isbundle) && $type === 'paid' && $status !== 'publish') {
    throw new moodle_exception('invalidaccess');
}

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}

$isloggedin = isloggedin() && !isguestuser();
if ($isloggedin) {
    if (local_elearning_system_is_product_covered_by_purchase((int)$USER->id, (int)$productid, $DB)) {
        \core\notification::add('This product or bundle is already purchased.', \core\output\notification::NOTIFY_WARNING);
        $target = '/local/elearning_system/cart.php';
        if ($return === 'product') {
            $target = '/local/elearning_system/store_product.php';
        }
        redirect(new moodle_url($target, $return === 'product' ? ['id' => $productid] : []));
    }
}

if (!isset($SESSION->local_elearning_system_cart[$productid])) {
    $SESSION->local_elearning_system_cart[$productid] = 1;
} else {
    $SESSION->local_elearning_system_cart[$productid] = 1;
}

$target = '/local/elearning_system/cart.php';
if ($return === 'product') {
    $target = '/local/elearning_system/store_product.php';
}

redirect(new moodle_url($target, $return === 'product' ? ['id' => $productid] : []));
