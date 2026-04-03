<?php

require('../../config.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/cart.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Cart');
$PAGE->set_heading('Your Cart');

global $DB, $CFG;

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

$isloggedin = isloggedin() && !isguestuser();

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}

$action = optional_param('action', '', PARAM_ALPHA);
$itemid = optional_param('id', 0, PARAM_INT);
$qty = optional_param('qty', 1, PARAM_INT);

if (in_array($action, ['remove', 'clear', 'increase', 'decrease', 'setqty'])) {
    require_sesskey();
}

if ($action === 'remove' && $itemid > 0) {
    unset($SESSION->local_elearning_system_cart[$itemid]);
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

if ($action === 'increase' && $itemid > 0) {
    $SESSION->local_elearning_system_cart[$itemid] = 1;
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

if ($action === 'decrease' && $itemid > 0) {
    if (isset($SESSION->local_elearning_system_cart[$itemid])) {
        $SESSION->local_elearning_system_cart[$itemid]--;
        if ($SESSION->local_elearning_system_cart[$itemid] <= 0) {
            unset($SESSION->local_elearning_system_cart[$itemid]);
        }
    }
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

if ($action === 'setqty' && $itemid > 0) {
    if ($qty <= 0) {
        unset($SESSION->local_elearning_system_cart[$itemid]);
    } else {
        $SESSION->local_elearning_system_cart[$itemid] = 1;
    }
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

if ($action === 'clear') {
    $SESSION->local_elearning_system_cart = [];
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

$cartids = array_keys($SESSION->local_elearning_system_cart);
$products = [];
$total = 0.0;

if (!empty($cartids)) {
    [$insql, $params] = $DB->get_in_or_equal($cartids, SQL_PARAMS_NAMED);
    $records = $DB->get_records_select('elearning_products', 'id ' . $insql, $params, 'id DESC');
    $isloggedinuser = isloggedin() && !isguestuser();

    foreach ($records as $r) {
        $price = !empty($r->price) ? (float)$r->price : 0.0;
        $saleprice = !empty($r->saleprice) ? (float)$r->saleprice : 0.0;
        $displayprice = $saleprice > 0 ? $saleprice : $price;
        $status = strtolower(trim((string)($r->status ?? '')));
        $rawtype = strtolower(trim((string)($r->type ?? '')));

        if ($displayprice <= 0) {
            $type = 'free';
        } else if (in_array($rawtype, ['paid', 'subscription', 'subscroiption', 'subcription', 'subscribe', 'premium'])) {
            $type = 'paid';
        } else {
            $type = 'free';
        }

        if (empty($r->isbundle) && $type === 'paid' && $status !== 'publish') {
            unset($SESSION->local_elearning_system_cart[$r->id]);
            continue;
        }

        if ($isloggedinuser && local_elearning_system_is_product_covered_by_purchase((int)$USER->id, (int)$r->id, $DB)) {
            unset($SESSION->local_elearning_system_cart[$r->id]);
            continue;
        }

        $qty = (int)($SESSION->local_elearning_system_cart[$r->id] ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }
        if ($qty > 1) {
            $qty = 1;
            $SESSION->local_elearning_system_cart[$r->id] = 1;
        }

        $line = $displayprice * $qty;
        $total += $line;

        $products[] = [
            'id' => (int)$r->id,
            'name' => format_string($r->name),
            'qty' => $qty,
            'price' => number_format($displayprice, 2),
            'lineprice' => number_format($line, 2),
            'producturl' => (new moodle_url('/local/elearning_system/product.php', ['id' => (int)$r->id]))->out(false),
            'increaseurl' => (new moodle_url('/local/elearning_system/cart.php', ['action' => 'increase', 'id' => (int)$r->id, 'sesskey' => sesskey()]))->out(false),
            'decreaseurl' => (new moodle_url('/local/elearning_system/cart.php', ['action' => 'decrease', 'id' => (int)$r->id, 'sesskey' => sesskey()]))->out(false),
            'removeurl' => (new moodle_url('/local/elearning_system/cart.php', ['action' => 'remove', 'id' => (int)$r->id, 'sesskey' => sesskey()]))->out(false),
        ];
    }
}

$checkouturl = (new moodle_url('/local/elearning_system/checkout.php'))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/cart', [
    'products' => $products,
    'hasproducts' => !empty($products),
    'total' => number_format($total, 2),
    'checkouturl' => $checkouturl,
    'isloggedin' => $isloggedin,
    'cartcount' => array_sum($SESSION->local_elearning_system_cart),
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
    'loginurl' => (new moodle_url('/login/index.php', ['wantsurl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false)]))->out(false),
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'storeurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
    'clearcarturl' => (new moodle_url('/local/elearning_system/cart.php', ['action' => 'clear', 'sesskey' => sesskey()]))->out(false),
]);
echo $OUTPUT->footer();
