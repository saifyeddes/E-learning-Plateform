<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/cart.php');
$PAGE->set_pagelayout('standard');
local_elearning_system_apply_requested_language();
$PAGE->set_title(get_string('cart', 'local_elearning_system'));
$PAGE->set_heading(get_string('yourcart', 'local_elearning_system'));
local_elearning_system_force_auth_login_url('/local/elearning_system/cart.php');

global $DB, $CFG;
function local_elearning_system_plugin_get_cart_products(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        return [];
    }

    $db = \local_elearning_system\plugin_db::get();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $db->prepare("SELECT * FROM el_products WHERE id IN ($placeholders)");
    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param($types, ...$ids);
    $stmt->execute();

    $result = $stmt->get_result();

    $products = [];
    while ($row = $result->fetch_object()) {
        $products[(int)$row->id] = $row;
    }

    $stmt->close();

    return $products;
}

function local_elearning_system_cart_order_is_active(stdClass $order): bool {
    $durationmonths = !empty($order->durationmonths)
        ? max(1, (int)$order->durationmonths)
        : 1;

    if (!empty($order->expiresat)) {
        return (int)$order->expiresat > time();
    }

    if (!empty($order->timecreated)) {
        $expiresat = strtotime('+' . $durationmonths . ' months', (int)$order->timecreated);
        return $expiresat === false || $expiresat > time();
    }

    return true;
}

function local_elearning_system_cart_purchase_status(int $userid, int $productid): string {
    if ($userid <= 0 || $productid <= 0) {
        return 'none';
    }

    $db = \local_elearning_system\plugin_db::get();

    $stmt = $db->prepare("
        SELECT o.id, o.userid, o.productid, o.durationmonths, o.expiresat, o.timecreated,
               p.isbundle, p.bundleitems
          FROM el_orders o
     LEFT JOIN el_products p ON p.id = o.productid
         WHERE o.userid = ?
      ORDER BY o.id DESC
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param('i', $userid);
    $stmt->execute();

    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_object()) {
        if (local_elearning_system_cart_order_is_active($row)) {
            $orders[] = $row;
        }
    }

    $stmt->close();

    foreach ($orders as $order) {
        if ((int)$order->productid === $productid) {
            return 'direct';
        }
    }

    foreach ($orders as $order) {
        if (empty($order->isbundle) || empty($order->bundleitems)) {
            continue;
        }

        $bundleitemids = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)$order->bundleitems)
        ))));

        if (in_array($productid, $bundleitemids, true)) {
            return 'bundle';
        }
    }

    return 'none';
}

$isloggedin = isloggedin() && !isguestuser();

$cartuserid = 0;

if ($isloggedin) {
    $effectiveuserctx = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
    $cartuserid = (int)($effectiveuserctx['targetuserid'] ?? $USER->id);
}

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

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
        $cartitem = local_elearning_system_get_cart_item(
            $SESSION->local_elearning_system_cart,
            $itemid
        );

        $cartitem['productid'] = $itemid;
        $cartitem['qty'] = 1;
        $cartitem['durationmonths'] = $durationmonths;

        $SESSION->local_elearning_system_cart[$itemid] = $cartitem;
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
    $records = local_elearning_system_plugin_get_cart_products($cartids);
    $isloggedinuser = isloggedin() && !isguestuser();
    foreach ($cartids as $cartid) {
    if (!isset($records[(int)$cartid])) {
        unset($SESSION->local_elearning_system_cart[(int)$cartid]);
    }
}

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

        if ($isloggedinuser && local_elearning_system_cart_purchase_status($cartuserid, (int)$r->id) !== 'none') {
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
        $minusduration = max(1, $durationmonths - 1);
$plusduration = min(24, $durationmonths + 1);

        // Force single-purchase quantity per product in cart.
        $SESSION->local_elearning_system_cart[$r->id] = [
    'productid' => (int)$r->id,
    'qty' => 1,
    'durationmonths' => $durationmonths,
];

        $line = $displayprice * $durationmonths;
        $total += $line;

        $image = trim((string)($r->image ?? ''));
        $hasimage = ($image !== '');
        if ($hasimage && strpos($image, 'http') !== 0) {
            if ($image[0] !== '/') {
                $image = '/' . $image;
            }
            $image = $CFG->wwwroot . $image;
        }

        $products[] = [
            'id' => (int)$r->id,
            'name' => format_string($r->name),
            'price' => local_elearning_system_format_price($displayprice),
'unitpriceraw' => number_format($displayprice, 2, '.', ''),
'durationmonths' => $durationmonths,
'lineprice' => local_elearning_system_format_price($line),
'linepriceraw' => number_format($line, 2, '.', ''),
            'hasimage' => $hasimage,
            'image' => $image,
            'producturl' => (new moodle_url('/local/elearning_system/product.php', ['id' => (int)$r->id]))->out(false),
'setdurationurl' => (new moodle_url('/local/elearning_system/cart.php', [
    'action' => 'setduration',
    'id' => (int)$r->id,
    'durationmonths' => $durationmonths,
    'sesskey' => sesskey(),
]))->out(false),

'decreasedurationurl' => (new moodle_url('/local/elearning_system/cart.php', [
    'action' => 'setduration',
    'id' => (int)$r->id,
    'durationmonths' => $minusduration,
    'sesskey' => sesskey(),
]))->out(false),

'increasedurationurl' => (new moodle_url('/local/elearning_system/cart.php', [
    'action' => 'setduration',
    'id' => (int)$r->id,
    'durationmonths' => $plusduration,
    'sesskey' => sesskey(),
]))->out(false),
    'removeurl' => (new moodle_url('/local/elearning_system/cart.php', ['action' => 'remove', 'id' => (int)$r->id, 'sesskey' => sesskey()]))->out(false),
        ];
    }
}

$checkouturl = (new moodle_url('/local/elearning_system/checkout.php'))->out(false);
$authurl = (new moodle_url('/local/elearning_system/auth.php', ['return' => '/local/elearning_system/checkout.php']))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/cart', array_merge([
    'products' => $products,
    'hasproducts' => !empty($products),
    'total' => local_elearning_system_format_price($total),
    'checkouturl' => $checkouturl,
    'authurl' => $authurl,
    'isloggedin' => $isloggedin,
    'cartcount' => local_elearning_system_cart_count($SESSION->local_elearning_system_cart),
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
    'loginurl' => (new moodle_url('/local/elearning_system/auth.php', ['return' => '/local/elearning_system/cart.php']))->out(false),
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'storeurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
    'currencycode' => local_elearning_system_get_site_currency_code(),
    'clearcarturl' => (new moodle_url('/local/elearning_system/cart.php', ['action' => 'clear', 'sesskey' => sesskey()]))->out(false),
], local_elearning_system_get_client_strings()));
echo $OUTPUT->footer();
