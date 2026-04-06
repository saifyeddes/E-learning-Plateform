<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');

$productid = required_param('id', PARAM_INT);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/product.php', ['id' => $productid]);
$PAGE->set_pagelayout('standard');

global $DB, $CFG;

function local_elearning_system_is_product_covered_by_purchase(int $userid, int $productid, moodle_database $DB): bool {
    return local_elearning_system_is_product_covered_by_active_purchase($userid, $productid, $DB);
}

$isloggedin = isloggedin() && !isguestuser();

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

$productrecord = $DB->get_record('elearning_products', ['id' => $productid], '*', MUST_EXIST);

$price = !empty($productrecord->price) ? (float)$productrecord->price : 0.0;
$saleprice = !empty($productrecord->saleprice) ? (float)$productrecord->saleprice : 0.0;
$displayprice = $saleprice > 0 ? $saleprice : $price;
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
    'type' => ucfirst($type),
    'isfree' => $type === 'free',
    'ispaid' => $type === 'paid',
    'price' => number_format($displayprice, 2),
    'saleprice' => $saleprice > 0 ? number_format($saleprice, 2) : null,
    'categoryname' => $categoryname,
    'coursename' => $coursename,
    'hascourse' => !empty($courseurl),
    'courseurl' => $courseurl,
    'isloggedin' => $isloggedin,
    'cartcount' => local_elearning_system_cart_count($SESSION->local_elearning_system_cart),
    'addtocarturl' => (new moodle_url('/local/elearning_system/add_to_cart.php', ['id' => $productid]))->out(false),
    'isincart' => array_key_exists((int)$productid, $SESSION->local_elearning_system_cart),
    'ispurchased' => false,
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
    'checkouturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
    'checkoutreturnurl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
    'loginurl' => (new moodle_url('/login/index.php', ['wantsurl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false)]))->out(false),
    'signupurl' => (new moodle_url('/login/signup.php', ['wantsurl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false)]))->out(false),
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'backurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
    'isbundle' => !empty($productrecord->isbundle),
    'bundleitems' => [],
    'hasbundleitems' => false,
];

if ($isloggedin && $DB->get_manager()->table_exists('elearning_orders')) {
    local_elearning_system_cleanup_expired_orders_for_user((int)$USER->id, $DB);
    $templatedata['ispurchased'] = local_elearning_system_is_product_covered_by_purchase((int)$USER->id, (int)$productid, $DB);

    if (!$templatedata['ispurchased']) {
        $orders = $DB->get_records('elearning_orders', ['userid' => (int)$USER->id], '', 'id,productid');
        foreach ($orders as $order) {
            $bundleproduct = $DB->get_record('elearning_products', ['id' => (int)$order->productid], 'id,isbundle,bundleitems', IGNORE_MISSING);
            if (!$bundleproduct || empty($bundleproduct->isbundle) || empty($bundleproduct->bundleitems)) {
                continue;
            }

            $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$bundleproduct->bundleitems)))));
            if (in_array((int)$productid, $bundleitemids, true)) {
                $templatedata['ispurchased'] = true;
                break;
            }
        }
    }
}

if (!empty($productrecord->isbundle) && !empty($productrecord->bundleitems)) {
    $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$productrecord->bundleitems)))));
    if (!empty($bundleitemids)) {
        $bundleproducts = $DB->get_records_list('elearning_products', 'id', $bundleitemids, '', 'id,name,courseid');
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
                if (local_elearning_system_is_product_covered_by_active_purchase((int)$USER->id, (int)$bundleproduct->id, $DB)) {
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
echo $OUTPUT->render_from_template('local_elearning_system/product_details', $templatedata);
echo $OUTPUT->footer();
