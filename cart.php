<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/cart.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Cart');
$PAGE->set_heading('Your Cart');

global $DB, $CFG;

function local_elearning_system_is_product_covered_by_purchase(int $userid, int $productid, moodle_database $DB): bool {
    return local_elearning_system_is_product_covered_by_active_purchase($userid, $productid, $DB);
}

$isloggedin = isloggedin() && !isguestuser();

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

if ($isloggedin) {
    local_elearning_system_cleanup_expired_orders_for_user((int)$USER->id, $DB);
}

$action = optional_param('action', '', PARAM_ALPHA);
$itemid = optional_param('id', 0, PARAM_INT);

if (in_array($action, ['remove', 'clear', 'setduration'])) {
    require_sesskey();
}

if ($action === 'remove' && $itemid > 0) {
    unset($SESSION->local_elearning_system_cart[$itemid]);
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

if ($action === 'setduration' && $itemid > 0) {
    $durationmonths = optional_param('durationmonths', 1, PARAM_INT);
    $durationmonths = max(1, min(24, $durationmonths));
    if (isset($SESSION->local_elearning_system_cart[$itemid])) {
        $SESSION->local_elearning_system_cart[$itemid] = [
            'qty' => 1,
            'durationmonths' => $durationmonths,
        ];
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

        $cartitem = local_elearning_system_get_cart_item($SESSION->local_elearning_system_cart, (int)$r->id);
        $durationmonths = (int)$cartitem['durationmonths'];
        if ($durationmonths < 1) {
            $durationmonths = 1;
        }
        if ($durationmonths > 24) {
            $durationmonths = 24;
        }

        // Force single-purchase quantity per product in cart.
        $SESSION->local_elearning_system_cart[$r->id] = [
            'qty' => 1,
            'durationmonths' => $durationmonths,
        ];

        $line = $displayprice * $durationmonths;
        $total += $line;

        $products[] = [
            'id' => (int)$r->id,
            'name' => format_string($r->name),
            'price' => number_format($displayprice, 2),
            'durationmonths' => $durationmonths,
            'lineprice' => number_format($line, 2),
            'producturl' => (new moodle_url('/local/elearning_system/product.php', ['id' => (int)$r->id]))->out(false),
            'setdurationurl' => (new moodle_url('/local/elearning_system/cart.php', ['action' => 'setduration', 'id' => (int)$r->id, 'sesskey' => sesskey()]))->out(false),
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
    'cartcount' => local_elearning_system_cart_count($SESSION->local_elearning_system_cart),
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
    'loginurl' => (new moodle_url('/login/index.php', ['wantsurl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false)]))->out(false),
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'storeurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
    'clearcarturl' => (new moodle_url('/local/elearning_system/cart.php', ['action' => 'clear', 'sesskey' => sesskey()]))->out(false),
]);
echo $OUTPUT->footer();
