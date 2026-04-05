<?php

require('../../config.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/checkout.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Checkout');
$PAGE->set_heading('Checkout');

global $DB;

$isloggedin = isloggedin() && !isguestuser();

$loginerrors = [];
$loginidentifier = '';

if (!$isloggedin && optional_param('loginsubmit', 0, PARAM_BOOL) && confirm_sesskey()) {
    $loginidentifier = trim(optional_param('login_username', '', PARAM_RAW_TRIMMED));
    $loginpassword = optional_param('login_password', '', PARAM_RAW_TRIMMED);

    if ($loginidentifier === '' || $loginpassword === '') {
        $loginerrors[] = ['message' => get_string('invalidlogin')];
    } else {
        $username = core_text::strtolower($loginidentifier);
        if (strpos($loginidentifier, '@') !== false) {
            $userbyemail = $DB->get_record('user', [
                'email' => core_text::strtolower($loginidentifier),
                'deleted' => 0,
            ], 'id,username');
            if ($userbyemail) {
                $username = $userbyemail->username;
            }
        }

        $failurereason = null;
        $user = authenticate_user_login($username, $loginpassword, false, $failurereason);

        if ($user) {
            complete_user_login($user);
            redirect(new moodle_url('/local/elearning_system/checkout.php'));
        }

        $loginerrors[] = ['message' => get_string('invalidlogin')];
    }
}

$registererrors = [];
$registerdata = [
    'username' => '',
    'firstname' => '',
    'lastname' => '',
    'email' => '',
    'city' => '',
    'country' => '',
];

if (!$isloggedin && optional_param('registersubmit', 0, PARAM_BOOL) && confirm_sesskey()) {
    require_once($CFG->dirroot . '/user/lib.php');

    $registerdata['username'] = trim(optional_param('reg_username', '', PARAM_USERNAME));
    $registerdata['firstname'] = trim(optional_param('reg_firstname', '', PARAM_TEXT));
    $registerdata['lastname'] = trim(optional_param('reg_lastname', '', PARAM_TEXT));
    $registerdata['email'] = trim(optional_param('reg_email', '', PARAM_EMAIL));
    $registerdata['city'] = trim(optional_param('reg_city', '', PARAM_TEXT));
    $registerdata['country'] = trim(optional_param('reg_country', '', PARAM_ALPHA));
    $regpassword = optional_param('reg_password', '', PARAM_RAW_TRIMMED);

    if ($registerdata['username'] === '') {
        $registererrors[] = ['message' => get_string('missingusername')];
    }
    if ($registerdata['firstname'] === '') {
        $registererrors[] = ['message' => get_string('missingfirstname')];
    }
    if ($registerdata['lastname'] === '') {
        $registererrors[] = ['message' => get_string('missinglastname')];
    }
    if ($registerdata['email'] === '') {
        $registererrors[] = ['message' => get_string('missingemail')];
    }
    if ($regpassword === '') {
        $registererrors[] = ['message' => get_string('missingpassword')];
    }

    if ($registerdata['username'] !== '' && $DB->record_exists('user', ['username' => $registerdata['username'], 'deleted' => 0])) {
        $registererrors[] = ['message' => get_string('usernameexists')];
    }
    if ($registerdata['email'] !== '' && empty($CFG->allowaccountssameemail) && $DB->record_exists('user', ['email' => $registerdata['email'], 'deleted' => 0])) {
        $registererrors[] = ['message' => get_string('emailexists')];
    }

    if ($regpassword !== '') {
        $errmsg = '';
        if (!check_password_policy($regpassword, $errmsg)) {
            $registererrors[] = ['message' => $errmsg];
        }
    }

    if (empty($registererrors)) {
        $newuser = new stdClass();
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $CFG->mnet_localhost_id;
        $newuser->username = $registerdata['username'];
        $newuser->password = $regpassword;
        $newuser->firstname = $registerdata['firstname'];
        $newuser->lastname = $registerdata['lastname'];
        $newuser->email = $registerdata['email'];
        $newuser->city = $registerdata['city'];
        $newuser->country = $registerdata['country'];
        $newuser->lang = current_language();
        $newuser->timecreated = time();
        $newuser->timemodified = time();

        // Let Moodle hash/store the password using the standard user creation flow.
        $newuserid = user_create_user($newuser, true, true);
        complete_user_login(core_user::get_user($newuserid));
        redirect(new moodle_url('/local/elearning_system/checkout.php'));
    }
}

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}

if (empty($SESSION->local_elearning_system_cart)) {
    redirect(new moodle_url('/local/elearning_system/cart.php'));
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

        $qty = (int)($SESSION->local_elearning_system_cart[$r->id] ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }

        $line = $displayprice * $qty;
        $total += $line;
        $linebyproduct[(int)$r->id] = $line;

        $products[] = [
            'id' => (int)$r->id,
            'name' => format_string($r->name),
            'qty' => $qty,
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

$checkoutreturnurl = (new moodle_url('/local/elearning_system/checkout.php'))->out(false);

$loginurl = (new moodle_url('/login/index.php', ['wantsurl' => $checkoutreturnurl]))->out(false);
$logintoken = \core\session\manager::get_login_token();

$countrylist = [];
foreach (get_string_manager()->get_list_of_countries() as $code => $name) {
    $countrylist[] = [
        'code' => $code,
        'name' => $name,
        'selected' => ($registerdata['country'] === $code),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/checkout', [
    'products' => $products,
    'hasproducts' => !empty($products),
    'total' => number_format($grandtotal, 2),
    'isloggedin' => $isloggedin,
    'cartcount' => array_sum($SESSION->local_elearning_system_cart),
    'loginerrors' => $loginerrors,
    'hasloginerrors' => !empty($loginerrors),
    'loginidentifier' => s($loginidentifier),
    'registererrors' => $registererrors,
    'hasregistererrors' => !empty($registererrors),
    'registerusername' => s($registerdata['username']),
    'registerfirstname' => s($registerdata['firstname']),
    'registerlastname' => s($registerdata['lastname']),
    'registeremail' => s($registerdata['email']),
    'registercity' => s($registerdata['city']),
    'countries' => $countrylist,
    'sesskey' => sesskey(),
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'paymenturl' => (new moodle_url('/local/elearning_system/payment.php'))->out(false),
    'checkouturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
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
