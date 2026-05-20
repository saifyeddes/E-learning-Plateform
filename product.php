<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');

$productid = required_param('id', PARAM_INT);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/product.php', ['id' => $productid]);
$PAGE->set_pagelayout('standard');
local_elearning_system_apply_requested_language();
local_elearning_system_force_auth_login_url('/local/elearning_system/product.php?id=' . $productid);

global $DB, $CFG;
function local_elearning_system_plugin_get_product_client(int $id): ?stdClass {
    if ($id <= 0) {
        return null;
    }

    $db = \local_elearning_system\plugin_db::get();

    $stmt = $db->prepare("SELECT * FROM el_products WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $product = $result ? $result->fetch_object() : null;

    $stmt->close();

    return $product ?: null;
}

function local_elearning_system_plugin_get_products_by_ids_client(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        return [];
    }

    $db = \local_elearning_system\plugin_db::get();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $db->prepare("SELECT id, name, courseid FROM el_products WHERE id IN ($placeholders)");
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

function local_elearning_system_product_plugin_order_is_active(stdClass $order): bool {
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

function local_elearning_system_product_plugin_get_user_active_orders(int $userid): array {
    if ($userid <= 0) {
        return [];
    }

    $db = \local_elearning_system\plugin_db::get();

    $stmt = $db->prepare("
        SELECT o.id, o.userid, o.productid, o.timecreated, o.durationmonths, o.expiresat,
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
        if (local_elearning_system_product_plugin_order_is_active($row)) {
            $orders[] = $row;
        }
    }

    $stmt->close();

    return $orders;
}

function local_elearning_system_product_plugin_get_purchase_status(int $userid, int $productid): string {
    if ($userid <= 0 || $productid <= 0) {
        return 'none';
    }

    $orders = local_elearning_system_product_plugin_get_user_active_orders($userid);

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
$beneficiaryuserid = 0;
if ($isloggedin) {
    $usercontext = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
    $beneficiaryuserid = (int)$usercontext['targetuserid'];
}

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

$authurl = (new moodle_url('/local/elearning_system/auth.php', ['return' => '/local/elearning_system/product.php?id=' . $productid]))->out(false);

$productrecord = local_elearning_system_plugin_get_product_client($productid);

if (!$productrecord) {
    throw new moodle_exception('invalidaccess');
}
$originalprice = !empty($productrecord->price) ? (float)$productrecord->price : 0.0;
$saleprice = !empty($productrecord->saleprice) ? (float)$productrecord->saleprice : 0.0;

$displayprice = $saleprice > 0 ? $saleprice : $originalprice;
$hasdiscount = $originalprice > 0 && $saleprice > 0 && $originalprice > $saleprice;
$status = strtolower(trim((string)($productrecord->status ?? '')));
$rawtype = strtolower(trim((string)($productrecord->type ?? '')));

if ($displayprice <= 0) {
    $type = 'free';
} else if (in_array($rawtype, ['paid', 'subscription', 'subscroiption', 'subcription', 'subscribe', 'premium'])) {
    $type = 'paid';
} else {
    $type = 'free';
}

// Keep same visibility rule as home list: paid non-bundle must be published.
if (empty($productrecord->isbundle) && $type === 'paid' && $status !== 'publish') {
    throw new moodle_exception('invalidaccess');
}

$image = '';
if (!empty($productrecord->image)) {
    if (preg_match('/^https?:\/\//', $productrecord->image)) {
        $image = $productrecord->image;
    } else if (strpos($productrecord->image, '/') === 0) {
        $image = $CFG->wwwroot.$productrecord->image;
    } else {
        $image = $CFG->wwwroot.'/local/elearning_system/uploads/'.$productrecord->image;
    }
}

$categoryname = '-';
if (!empty($productrecord->categoryid)) {
    $category = $DB->get_record('course_categories', ['id' => $productrecord->categoryid], 'id,name');
    if ($category) {
        $categoryname = format_string($category->name);
    }
}

$coursename = '-';
$courseurl = '';
if (!empty($productrecord->courseid)) {
    $course = $DB->get_record('course', ['id' => $productrecord->courseid], 'id,fullname');
    if ($course) {
        $coursename = format_string($course->fullname);
        $courseurl = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
    }
}

$PAGE->set_title(format_string($productrecord->name));
$PAGE->set_heading(format_string($productrecord->name));

$templatedata = [
    'name' => format_string($productrecord->name),
    'description' => format_text($productrecord->description ?? '', FORMAT_HTML),
    'hasdescription' => !empty(trim(strip_tags((string)($productrecord->description ?? '')))),
    'image' => $image,
    'hasimage' => !empty($image),
    'type' => $type === 'free' ? get_string('free', 'local_elearning_system') : get_string('paid', 'local_elearning_system'),
    'isfree' => $type === 'free',
    'ispaid' => $type === 'paid',
    'price' => local_elearning_system_format_price($displayprice),
'displayprice' => local_elearning_system_format_price($displayprice),

'originalprice' => $hasdiscount ? local_elearning_system_format_price($originalprice) : '',
'saleprice' => $saleprice > 0 ? local_elearning_system_format_price($saleprice) : '',
'hasdiscount' => $hasdiscount,
    'categoryname' => $categoryname,
    'coursename' => $coursename,
    'hascourse' => !empty($courseurl),
    'courseurl' => $courseurl,
    'isloggedin' => $isloggedin,
    'cartcount' => local_elearning_system_cart_count($SESSION->local_elearning_system_cart),
    'addtocarturl' => (new moodle_url('/local/elearning_system/add_to_cart.php', ['id' => $productid]))->out(false),
    'isincart' => array_key_exists((int)$productid, $SESSION->local_elearning_system_cart),
    'ispurchased' => false,
    'purchaselabel' => get_string('purchased', 'local_elearning_system'),
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
    'checkouturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
    'checkoutreturnurl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
    'authurl' => $authurl,
    'loginurl' => $authurl,
    'signupurl' => (new moodle_url('/login/signup.php', ['wantsurl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false)]))->out(false),
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'backurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
    'isbundle' => !empty($productrecord->isbundle),
    'bundleitems' => [],
    'hasbundleitems' => false,
];

if ($isloggedin) {
    $purchasestatus = local_elearning_system_product_plugin_get_purchase_status($beneficiaryuserid, (int)$productid);

    $templatedata['ispurchased'] = ($purchasestatus !== 'none');
    $templatedata['isdirectpurchase'] = ($purchasestatus === 'direct');
    $templatedata['isbundlepurchase'] = ($purchasestatus === 'bundle');
    $templatedata['purchaselabel'] = ($purchasestatus === 'direct')
        ? get_string('purchased', 'local_elearning_system')
        : (($purchasestatus === 'bundle') ? get_string('includedinbundle', 'local_elearning_system') : '');
}
$isfreeproduct = isset($product->type) && strtolower((string)$product->type) === 'free';

$templatedata['canopencourse'] = false;

if (!empty($templatedata['courseurl'])) {
    if ($isfreeproduct || !empty($templatedata['ispurchased'])) {
        $templatedata['canopencourse'] = true;
    }
}
if (!empty($productrecord->isbundle) && !empty($productrecord->bundleitems)) {
    $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$productrecord->bundleitems)))));
    if (!empty($bundleitemids)) {
        $bundleproducts = local_elearning_system_plugin_get_products_by_ids_client($bundleitemids);
        foreach ($bundleitemids as $bundleitemid) {
            if (empty($bundleproducts[$bundleitemid])) {
                continue;
            }

            $bundleproduct = $bundleproducts[$bundleitemid];
            $bundlecoursename = '-';
            $bundlecourseurl = '';
            if (!empty($bundleproduct->courseid)) {
                $bundlecourse = $DB->get_record('course', ['id' => (int)$bundleproduct->courseid], 'id,fullname', IGNORE_MISSING);
                if ($bundlecourse) {
                    $bundlecoursename = format_string($bundlecourse->fullname);
                    $bundlecourseurl = (new moodle_url('/course/view.php', ['id' => (int)$bundlecourse->id]))->out(false);
                }
            }

            $itempurchased = false;
            if (!empty($templatedata['ispurchased'])) {
                $itempurchased = true;
            } else if ($isloggedin) {
                $itemstatus = local_elearning_system_product_plugin_get_purchase_status($beneficiaryuserid, (int)$bundleproduct->id);
if ($itemstatus !== 'none') {
    $itempurchased = true;
}
            }

            $templatedata['bundleitems'][] = [
                'name' => format_string($bundleproduct->name),
                'coursename' => $bundlecoursename,
                'hascourse' => !empty($bundlecourseurl),
                'courseurl' => $bundlecourseurl,
                'ispurchased' => $itempurchased,
            ];
        }
    }

    $templatedata['hasbundleitems'] = !empty($templatedata['bundleitems']);
}

echo $OUTPUT->header();
$templatedata = array_merge($templatedata, local_elearning_system_get_client_strings());
echo $OUTPUT->render_from_template('local_elearning_system/product_details', $templatedata);
echo $OUTPUT->footer();
