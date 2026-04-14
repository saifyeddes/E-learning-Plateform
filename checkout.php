<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/checkout.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Checkout');
$PAGE->set_heading('Checkout');
local_elearning_system_force_auth_login_url('/local/elearning_system/checkout.php');

global $DB;

$isloggedin = isloggedin() && !isguestuser();

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

if (empty($SESSION->local_elearning_system_cart)) {
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

if (!$isloggedin) {
    redirect(new moodle_url('/local/elearning_system/auth.php', ['return' => '/local/elearning_system/checkout.php']));
}

$cartids = array_keys($SESSION->local_elearning_system_cart);
$products = [];
$total = 0.0;
$linebyproduct = [];

if (!empty($cartids)) {
    [$insql, $params] = $DB->get_in_or_equal($cartids, SQL_PARAMS_NAMED);
    $records = $DB->get_records_select('elearning_products', 'id ' . $insql, $params, 'id DESC');

    foreach ($records as $r) {
        $price = !empty($r->price) ? (float)$r->price : 0.0;
        $saleprice = !empty($r->saleprice) ? (float)$r->saleprice : 0.0;
        $displayprice = $saleprice > 0 ? $saleprice : $price;

        $cartitem = local_elearning_system_get_cart_item($SESSION->local_elearning_system_cart, (int)$r->id);
        $durationmonths = (int)$cartitem['durationmonths'];
        if ($durationmonths < 1) {
            $durationmonths = 1;
        }
        if ($durationmonths > 24) {
            $durationmonths = 24;
        }

        $SESSION->local_elearning_system_cart[(int)$r->id] = [
            'qty' => 1,
            'durationmonths' => $durationmonths,
        ];

        $line = $displayprice * $durationmonths;
        $total += $line;
        $linebyproduct[(int)$r->id] = $line;

        $products[] = [
            'id' => (int)$r->id,
            'name' => format_string($r->name),
            'durationmonths' => $durationmonths,
            'price' => number_format($displayprice, 2),
            'lineprice' => number_format($line, 2),
        ];
    }
}

$couponerror = '';
$couponsuccess = '';
$appliedcoupon = null;
$discountamount = 0.0;
$discountdisplay = '';
$newtotal = $total;

$tvapercent = (float)get_config('local_elearning_system', 'vat_percent');
if ($tvapercent < 0 || $tvapercent > 100) {
    $tvapercent = 0.0;
}

if (optional_param('removecoupon', 0, PARAM_BOOL) && confirm_sesskey()) {
    unset($SESSION->local_elearning_system_coupon);
    $couponsuccess = 'Coupon removed.';
}

if (optional_param('applycoupon', 0, PARAM_BOOL) && confirm_sesskey()) {
    $couponcode = strtoupper(trim((string)optional_param('couponcode', '', PARAM_TEXT)));
    if ($couponcode === '') {
        $couponerror = 'Please enter a coupon code.';
    } else {
        $coupon = $DB->get_record('elearning_coupons', ['code' => $couponcode], '*', IGNORE_MISSING);
        if (!$coupon) {
            $couponerror = 'Coupon code not found.';
        } else if ((string)$coupon->status !== 'active') {
            $couponerror = 'This coupon is inactive.';
        } else if (!empty($coupon->expirydate) && (int)$coupon->expirydate < time()) {
            $couponerror = 'This coupon has expired.';
        } else {
            $SESSION->local_elearning_system_coupon = (object)[
                'id' => (int)$coupon->id,
                'code' => (string)$coupon->code,
                'discounttype' => (string)$coupon->discounttype,
                'discountvalue' => (float)$coupon->discountvalue,
            ];
            $couponsuccess = 'Coupon applied successfully.';
        }
    }
}

if (!empty($SESSION->local_elearning_system_coupon)) {
    $sessioncoupon = $SESSION->local_elearning_system_coupon;
    $couponrecord = $DB->get_record('elearning_coupons', ['id' => (int)$sessioncoupon->id], '*', IGNORE_MISSING);

    if (!$couponrecord || (string)$couponrecord->status !== 'active' || (!empty($couponrecord->expirydate) && (int)$couponrecord->expirydate < time())) {
        unset($SESSION->local_elearning_system_coupon);
    } else {
        $discountvalue = (float)$couponrecord->discountvalue;
        $discounttype = (string)$couponrecord->discounttype;

        if ($discounttype === 'fixed') {
            $discountamount = min($discountvalue, $total);
            $discountdisplay = '$' . number_format($discountamount, 2);
        } else {
            $discountamount = ($total * $discountvalue) / 100;
            $discountdisplay = number_format($discountvalue, 2) . '% (-$' . number_format($discountamount, 2) . ')';
        }

        if ($discountamount < 0) {
            $discountamount = 0.0;
        }
        $newtotal = max(0.0, $total - $discountamount);

        $appliedcoupon = [
            'code' => s((string)$couponrecord->code),
        ];
    }
}

$taxamount = ($newtotal * $tvapercent) / 100;
if ($taxamount < 0) {
    $taxamount = 0.0;
}
$grandtotal = $newtotal + $taxamount;

$authurl = (new moodle_url('/local/elearning_system/auth.php', ['return' => '/local/elearning_system/checkout.php']))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/checkout', [
    'products' => $products,
    'hasproducts' => !empty($products),
    'total' => number_format($grandtotal, 2),
    'isloggedin' => $isloggedin,
    'cartcount' => local_elearning_system_cart_count($SESSION->local_elearning_system_cart),
    'sesskey' => sesskey(),
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'paymenturl' => (new moodle_url('/local/elearning_system/payment.php'))->out(false),
    'checkouturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
    'authurl' => $authurl,
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
    'appliedcoupon' => $appliedcoupon,
    'discountdisplay' => $discountdisplay,
    'newtotal' => number_format($newtotal, 2),
    'subtotal' => number_format($total, 2),
    'taxamount' => number_format($taxamount, 2),
    'tvapercent' => number_format($tvapercent, 2),
    'grandtotal' => number_format($grandtotal, 2),
    'hasdiscount' => $discountamount > 0,
    'discountamount' => number_format($discountamount, 2),
    'couponerror' => $couponerror,
    'hascouponerror' => !empty($couponerror),
    'couponsuccess' => $couponsuccess,
    'hascouponsuccess' => !empty($couponsuccess),
]);
echo $OUTPUT->footer();
