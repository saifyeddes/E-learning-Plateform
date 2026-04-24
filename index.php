<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');

// ✅ CONTEXTE OBLIGATOIRE
$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url('/local/elearning_system/index.php');
$PAGE->set_pagelayout('standard');

$requestedlang = optional_param('lang', '', PARAM_LANG);
$supportedlangs = ['en', 'fr', 'ar'];
if (in_array($requestedlang, $supportedlangs, true)) {
    $SESSION->lang = $requestedlang;
    $SESSION->forcelang = $requestedlang;
    $SESSION->local_elearning_system_lang = $requestedlang;
    setcookie('local_elearning_system_lang', $requestedlang, time() + (60 * 60 * 24 * 365), '/');
    if (isset($USER) && is_object($USER)) {
        $USER->lang = $requestedlang;
    }
    if (isloggedin() && !isguestuser()) {
        set_user_preference('lang', $requestedlang);
    }
    if (function_exists('force_current_language')) {
        force_current_language($requestedlang);
    }
    if (function_exists('fix_current_language')) {
        fix_current_language($requestedlang);
    }
}

$frontendstrings = local_elearning_system_get_flat_language_strings();

$PAGE->set_title($frontendstrings['allcourses'] ?? 'All Courses');
$PAGE->set_heading($frontendstrings['allcourses'] ?? 'All Courses');
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
    'home_allcourses' => $frontendstrings['allcourses'] ?? 'All Courses',
    'home_homeintro' => $frontendstrings['homeintro'] ?? '',
    'home_homeslide1kicker' => $frontendstrings['homeslide1kicker'] ?? '',
    'home_homeslide1title' => $frontendstrings['homeslide1title'] ?? '',
    'home_homeslide1desc' => $frontendstrings['homeslide1desc'] ?? '',
    'home_homeslide2kicker' => $frontendstrings['homeslide2kicker'] ?? '',
    'home_homeslide2title' => $frontendstrings['homeslide2title'] ?? '',
    'home_homeslide2desc' => $frontendstrings['homeslide2desc'] ?? '',
    'home_homeslide3kicker' => $frontendstrings['homeslide3kicker'] ?? '',
    'home_homeslide3title' => $frontendstrings['homeslide3title'] ?? '',
    'home_homeslide3desc' => $frontendstrings['homeslide3desc'] ?? '',
    'home_browsecourses' => $frontendstrings['browsecourses'] ?? '',
    'home_mycourses' => $frontendstrings['mycourses'] ?? '',
    'home_signin' => $frontendstrings['signin'] ?? '',
    'home_cart' => $frontendstrings['cart'] ?? '',
    'home_viewbundles' => $frontendstrings['viewbundles'] ?? '',
    'home_opencart' => $frontendstrings['opencart'] ?? '',
    'home_findacourse' => $frontendstrings['findacourse'] ?? '',
    'home_checkout' => $frontendstrings['checkout'] ?? '',
    'home_search' => $frontendstrings['search'] ?? '',
    'home_searchbycoursename' => $frontendstrings['searchbycoursename'] ?? '',
    'home_type' => $frontendstrings['type'] ?? '',
    'home_alltypes' => $frontendstrings['alltypes'] ?? '',
    'home_free' => $frontendstrings['free'] ?? '',
    'home_paid' => $frontendstrings['paid'] ?? '',
    'home_reset' => $frontendstrings['reset'] ?? '',
    'home_pricelabel' => $frontendstrings['price'] ?? '',
    'home_purchased' => $frontendstrings['purchased'] ?? '',
    'home_incart' => $frontendstrings['incart'] ?? '',
    'home_addtocart' => $frontendstrings['addtocart'] ?? '',
    'home_nocoursesavailable' => $frontendstrings['nocoursesavailable'] ?? '',
    'home_availablebundles' => $frontendstrings['availablebundles'] ?? '',
    'home_bundlesdesc' => $frontendstrings['bundlesdesc'] ?? '',
    'home_bundle' => $frontendstrings['bundle'] ?? '',
    'home_nobundlesavailable' => $frontendstrings['nobundlesavailable'] ?? '',
    'home_nocoursesmatch' => $frontendstrings['nocoursesmatch'] ?? '',
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