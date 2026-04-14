<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');

// ✅ CONTEXTE OBLIGATOIRE
$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url('/local/elearning_system/index.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Courses');
$PAGE->set_heading('Available Courses');
local_elearning_system_force_auth_login_url('/local/elearning_system/index.php');

global $DB, $CFG, $USER;

function local_elearning_system_is_product_covered_by_purchase(int $userid, int $productid, moodle_database $DB): bool {
    return local_elearning_system_is_product_covered_by_active_purchase($userid, $productid, $DB);
}

$isloggedin = isloggedin() && !isguestuser();
$beneficiaryuserid = (int)$USER->id;
if ($isloggedin) {
    $usercontext = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
    $beneficiaryuserid = (int)$usercontext['targetuserid'];
}

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

$purchasedproductids = [];
if ($isloggedin) {
    local_elearning_system_cleanup_expired_orders_for_user($beneficiaryuserid, $DB);
    if ($DB->get_manager()->table_exists('elearning_orders')) {
        $orders = $DB->get_records('elearning_orders', ['userid' => $beneficiaryuserid], '', 'id,productid,expiresat');
        $ordercolumns = $DB->get_columns('elearning_orders');
        foreach ($orders as $order) {
            if (!local_elearning_system_is_order_active($order, $ordercolumns)) {
                continue;
            }
            $purchasedproductids[(int)$order->productid] = true;
            $bundleproduct = $DB->get_record('elearning_products', ['id' => (int)$order->productid], 'id,isbundle,bundleitems', IGNORE_MISSING);
            if ($bundleproduct && !empty($bundleproduct->isbundle) && !empty($bundleproduct->bundleitems)) {
                $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$bundleproduct->bundleitems)))));
                foreach ($bundleitemids as $bundleitemid) {
                    $purchasedproductids[(int)$bundleitemid] = true;
                }
            }
        }
    }
}

// =============================
// GET PRODUCTS
// Show all free products and only published paid products.
// =============================
$records = $DB->get_records('elearning_products', null, 'id DESC');

$products = [];
$bundles = [];

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

    $isbundle = !empty($r->isbundle);

    // Keep paid non-bundle products visible only when published; bundles stay visible.
    if (!$isbundle && $type === 'paid' && $status !== 'publish') {
        continue;
    }

    $image = '';
    if (!empty($r->image)) {
        if (preg_match('/^https?:\/\//', $r->image)) {
            $image = $r->image;
        } else if (strpos($r->image, '/') === 0) {
            $image = $CFG->wwwroot.$r->image;
        } else {
            $image = $CFG->wwwroot.'/local/elearning_system/uploads/'.$r->image;
        }
    }

    $item = [
        'id' => (int)$r->id,
        'name' => format_string($r->name),
        'description' => format_text($r->description, FORMAT_HTML),

        'image' => $image,
        'hasimage' => !empty($image),

        'price' => number_format($displayprice, 2),

        'saleprice' => ($saleprice > 0)
            ? number_format($saleprice, 2)
            : null,

        'type' => ucfirst($type),
        'isfree' => $type === 'free',
        'ispaid' => $type === 'paid',
        'isbundle' => $isbundle,
        'courseid' => !empty($r->courseid) ? (int)$r->courseid : 0,
        'producturl' => (new moodle_url('/local/elearning_system/product.php', ['id' => (int)$r->id]))->out(false),
        'addtocarturl' => (new moodle_url('/local/elearning_system/add_to_cart.php', ['id' => (int)$r->id]))->out(false),
        'isincart' => array_key_exists((int)$r->id, $SESSION->local_elearning_system_cart),
        'ispurchased' => false,
    ];

    if ($isloggedin) {
        $item['ispurchased'] = local_elearning_system_is_product_covered_by_purchase($beneficiaryuserid, (int)$r->id, $DB);
    }

    if (!empty($r->isbundle)) {
        $bundles[] = $item;
    } else {
        $products[] = $item;
    }
}

echo $OUTPUT->header();

$authurl = (new moodle_url('/local/elearning_system/auth.php', ['return' => '/local/elearning_system/index.php']))->out(false);

echo $OUTPUT->render_from_template('local_elearning_system/home', [
    'bundles' => $bundles,
    'hasbundles' => !empty($bundles),
    'products' => $products,
    'hasproducts' => !empty($products),
    'isloggedin' => $isloggedin,
    'cartcount' => local_elearning_system_cart_count($SESSION->local_elearning_system_cart),
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
    'mycoursesurl' => (new moodle_url('/local/elearning_system/my_courses.php'))->out(false),
    'loginurl' => $authurl,
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'chatbotendpoint' => (new moodle_url('/local/elearning_system/chatbot.php'))->out(false),
    'sesskey' => sesskey(),
]);

echo $OUTPUT->footer();