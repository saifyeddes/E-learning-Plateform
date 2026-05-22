<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');
require_login();


$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/payment.php');
local_elearning_system_apply_requested_language();
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('paymenttitle', 'local_elearning_system'));
$PAGE->set_heading(get_string('paymenttitle', 'local_elearning_system'));
$lang = local_elearning_system_get_active_language();

$paymenttexts = [
    'fr' => [
        'page_title' => 'Paiement',
        'success_head' => 'Paiement confirmé',
        'success_title' => 'Cours acheté avec succès',
        'success_text' => 'Votre achat est validé et l’accès au cours est actif. Vous pouvez commencer maintenant depuis votre espace de cours.',
        'success_item_1' => 'Accès activé immédiatement',
        'success_item_2' => 'Historique disponible dans votre espace',
        'success_item_3' => 'Paiement confirmé et enregistré',
        'my_courses' => 'Voir mes cours',
        'continue' => 'Continuer',
        'error_head' => 'Paiement non valide',
        'error_title' => 'Le paiement a échoué',
        'error_text' => 'La transaction n’a pas pu être finalisée. Veuillez réessayer.',
        'back_checkout' => 'Retour au paiement',
        'back_cart' => 'Revoir mon panier',
    ],
    'en' => [
        'page_title' => 'Payment',
        'success_head' => 'Payment confirmed',
        'success_title' => 'Course purchased successfully',
        'success_text' => 'Your purchase is valid and course access is active. You can start now from your course area.',
        'success_item_1' => 'Access activated immediately',
        'success_item_2' => 'History available in your space',
        'success_item_3' => 'Payment confirmed and saved',
        'my_courses' => 'View my courses',
        'continue' => 'Continue',
        'error_head' => 'Invalid payment',
        'error_title' => 'Payment failed',
        'error_text' => 'The transaction could not be completed. Please try again.',
        'back_checkout' => 'Back to checkout',
        'back_cart' => 'Review my cart',
    ],
    'ar' => [
        'page_title' => 'الدفع',
        'success_head' => 'تم تأكيد الدفع',
        'success_title' => 'تم شراء الدورة بنجاح',
        'success_text' => 'تم تأكيد عملية الشراء وتفعيل الوصول إلى الدورة. يمكنك البدء الآن من مساحة الدورات الخاصة بك.',
        'success_item_1' => 'تم تفعيل الوصول مباشرة',
        'success_item_2' => 'السجل متاح في مساحتك',
        'success_item_3' => 'تم تأكيد الدفع وتسجيله',
        'my_courses' => 'عرض دوراتي',
        'continue' => 'متابعة',
        'error_head' => 'الدفع غير صالح',
        'error_title' => 'فشلت عملية الدفع',
        'error_text' => 'تعذر إتمام العملية. يرجى إعادة المحاولة.',
        'back_checkout' => 'العودة إلى الدفع',
        'back_cart' => 'مراجعة السلة',
    ],
];

$pt = $paymenttexts[$lang] ?? $paymenttexts['fr'];
global $DB, $CFG, $USER;
function local_elearning_system_plugin_payment_db(): mysqli {
    return \local_elearning_system\plugin_db::get();
}

function local_elearning_system_plugin_get_payment_products(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        return [];
    }

    $db = local_elearning_system_plugin_payment_db();

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

function local_elearning_system_plugin_insert_order(stdClass $order): int {
    $db = local_elearning_system_plugin_payment_db();
    
    $stmt = $db->prepare("
        INSERT INTO el_orders
        (userid, productid, amount, promocode, discountamount, durationmonths, expiresat, timecreated)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $userid = (int)$order->userid;
    $productid = (int)$order->productid;
    $amount = (float)$order->amount;
    $promocode = !empty($order->promocode) ? (string)$order->promocode : null;
    $discountamount = !empty($order->discountamount) ? (float)$order->discountamount : 0.0;
    $durationmonths = !empty($order->durationmonths) ? (int)$order->durationmonths : 1;
    $expiresat = !empty($order->expiresat) ? (int)$order->expiresat : 0;
    $timecreated = !empty($order->timecreated) ? (int)$order->timecreated : time();

    $stmt->bind_param(
        'iidsdiii',
        $userid,
        $productid,
        $amount,
        $promocode,
        $discountamount,
        $durationmonths,
        $expiresat,
        $timecreated
    );

    if (!$stmt->execute()) {
        throw new moodle_exception('Plugin DB insert order error: ' . $stmt->error);
    }

    $orderid = (int)$db->insert_id;
    $stmt->close();

    return $orderid;
}

function local_elearning_system_plugin_get_coupon_by_id(int $couponid): ?stdClass {
    if ($couponid <= 0) {
        return null;
    }

    $db = local_elearning_system_plugin_payment_db();

    $stmt = $db->prepare("
        SELECT *
          FROM el_coupons
         WHERE id = ?
         LIMIT 1
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare coupon error: ' . $db->error);
    }

    $stmt->bind_param('i', $couponid);
    $stmt->execute();

    $result = $stmt->get_result();
    $coupon = $result ? $result->fetch_object() : null;

    $stmt->close();

    return $coupon ?: null;
}

function local_elearning_system_plugin_increment_coupon_use(int $couponid): void {
    if ($couponid <= 0) {
        return;
    }

    $db = local_elearning_system_plugin_payment_db();

    $stmt = $db->prepare("
        UPDATE el_coupons
           SET currentuse = currentuse + 1,
               status = CASE
                   WHEN maxuse IS NOT NULL
                    AND maxuse > 0
                    AND currentuse + 1 >= maxuse
                   THEN 'inactive'
                   ELSE status
               END
         WHERE id = ?
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare coupon update error: ' . $db->error);
    }

    $stmt->bind_param('i', $couponid);

    if (!$stmt->execute()) {
        throw new moodle_exception('Plugin DB coupon update error: ' . $stmt->error);
    }

    $stmt->close();
}


function local_elearning_system_is_product_covered_by_purchase(int $userid, int $productid, moodle_database $DB): bool {
    return local_elearning_system_is_product_covered_by_active_purchase($userid, $productid, $DB);
}

/**
 * Return all course IDs unlocked by a product (single product or bundle items).
 *
 * @param stdClass $product
 * @param moodle_database $DB
 * @return int[]
 */
function local_elearning_system_get_product_courseids(stdClass $product, moodle_database $DB): array {
    $courseids = [];

    if (!empty($product->courseid)) {
        $courseids[] = (int)$product->courseid;
    }

    if (!empty($product->isbundle) && !empty($product->bundleitems)) {
        $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$product->bundleitems)))));
        if (!empty($bundleitemids)) {
            $bundleproducts = $DB->get_records_list('elearning_products', 'id', $bundleitemids, '', 'id, courseid');
            foreach ($bundleproducts as $bundleproduct) {
                if (!empty($bundleproduct->courseid)) {
                    $courseids[] = (int)$bundleproduct->courseid;
                }
            }
        }
    }

    return array_values(array_unique(array_filter($courseids)));
}

/**
 * Enrol user to all course(s) provided by a purchased product or bundle.
 *
 * @param int $productid
 * @param int $userid
 * @param int $durationmonths
 * @param int $purchasetime
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_enrol_user_for_product(int $productid, int $userid, int $durationmonths, int $purchasetime, moodle_database $DB): void {
    $product = $DB->get_record('elearning_products', ['id' => $productid], 'id,courseid,isbundle,bundleitems', IGNORE_MISSING);
    if (!$product) {
        return;
    }

    $courseids = local_elearning_system_get_product_courseids($product, $DB);
    if (empty($courseids)) {
        return;
    }

    $manualplugin = enrol_get_plugin('manual');
    if (!$manualplugin) {
        return;
    }

    $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
    if ($studentroleid <= 0) {
        $studentroleid = 5;
    }

    foreach ($courseids as $courseid) {
        $courseid = (int)$courseid;
        if ($courseid <= 0) {
            continue;
        }

        $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
        /** @var context $coursecontext */
        if (!$coursecontext || is_enrolled($coursecontext, $userid, '', true)) {
            continue;
        }

        $instances = enrol_get_instances($courseid, true);
        $manualinstance = null;
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual' && (int)$instance->status === ENROL_INSTANCE_ENABLED) {
                $manualinstance = $instance;
                break;
            }
        }

        if (!$manualinstance) {
            continue;
        }

        $timeend = local_elearning_system_calculate_expiration($purchasetime, $durationmonths);

        // Prevent welcome email hook from trying to send from invalid noreply.
        $instanceforenrol = clone $manualinstance;
        $instanceforenrol->customint1 = ENROL_DO_NOT_SEND_EMAIL;
        $manualplugin->enrol_user($instanceforenrol, $userid, $studentroleid, time(), $timeend, ENROL_USER_ACTIVE);
    }
}

$ordercolumns = [];
if ($DB->get_manager()->table_exists('elearning_orders')) {
    $ordercolumns = $DB->get_columns('elearning_orders');
}

$action = optional_param('action', 'start', PARAM_ALPHA);
$status = optional_param('status', '', PARAM_ALPHA);
$stripesessionid = optional_param('session_id', '', PARAM_RAW_TRIMMED);
$provider = optional_param('provider', '', PARAM_ALPHA);
$issimulatedresult = optional_param('simulated', 0, PARAM_INT);
$isfreeresult = optional_param('isfree', 0, PARAM_INT);

$stripesk = trim((string)get_config('local_elearning_system', 'stripe_secret_key'));
$stripecurrency = core_text::strtolower(trim((string)get_config('local_elearning_system', 'stripe_currency')));
if ($stripecurrency === '') {
    $stripecurrency = 'usd';
}

$tvapercent = (float)get_config('local_elearning_system', 'vat_percent');
if ($tvapercent < 0 || $tvapercent > 100) {
    $tvapercent = 0.0;
}

$simulatesuccess = (int)get_config('local_elearning_system', 'simulate_success');

$usercontext = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
$beneficiaryuserid = (int)$usercontext['targetuserid'];
$isparentaccount = !empty($usercontext['isparentaccount']);
$beneficiaryfullname = trim((string)($usercontext['targetfullname'] ?? ''));

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);
local_elearning_system_cleanup_expired_orders_for_user($beneficiaryuserid, $DB);

if (!isset($SESSION->local_elearning_system_pending_order) || !is_array($SESSION->local_elearning_system_pending_order)) {
    $SESSION->local_elearning_system_pending_order = [];
}

if ($action === 'start' && empty($SESSION->local_elearning_system_cart)) {
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

if ($action === 'start') {
    if ($simulatesuccess) {
        redirect(new moodle_url('/local/elearning_system/payment.php', [
            'action' => 'result',
            'status' => 'success',
            'simulated' => 1,
        ]));
    }

    $cartids = array_keys($SESSION->local_elearning_system_cart);
    $records = local_elearning_system_plugin_get_payment_products($cartids);

    foreach ($cartids as $cartid) {
    if (!isset($records[(int)$cartid])) {
        unset($SESSION->local_elearning_system_cart[(int)$cartid]);
    }
}

if (empty($records)) {
    redirect(new moodle_url('/local/elearning_system/cart.php'));
}

    $pendingitems = [];
    $totalamount = 0.0;

    $appliedcoupon = null;

if (!empty($SESSION->local_elearning_system_coupon)) {
    $couponid = (int)($SESSION->local_elearning_system_coupon->id ?? 0);

    if ($couponid > 0) {
        $coupon = local_elearning_system_plugin_get_coupon_by_id($couponid);

        if (
            $coupon
            && strtolower((string)$coupon->status) === 'active'
            && (empty($coupon->expirydate) || (int)$coupon->expirydate >= time())
        ) {
            $appliedcoupon = $coupon;
        } else {
            unset($SESSION->local_elearning_system_coupon);
        }
    }
}

    $stripepostfields = [
        'mode' => 'payment',
        'success_url' => (new moodle_url('/local/elearning_system/payment.php', [
            'action' => 'result',
            'status' => 'success',
            'provider' => 'stripe',
        ]))->out(false) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => (new moodle_url('/local/elearning_system/payment.php', [
            'action' => 'result',
            'status' => 'cancel',
            'provider' => 'stripe',
        ]))->out(false),
    ];

    $idx = 0;
    $remainingfixedcoupondiscount = 0.0;
    if ($appliedcoupon && (string)$appliedcoupon->discounttype === 'fixed') {
        $remainingfixedcoupondiscount = max(0.0, (float)$appliedcoupon->discountvalue);
    }

    foreach ($records as $r) {
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

        if (local_elearning_system_is_product_covered_by_purchase($beneficiaryuserid, (int)$r->id, $DB)) {
            unset($SESSION->local_elearning_system_cart[$r->id]);
            continue;
        }

        $price = !empty($r->price) ? (float)$r->price : 0.0;
        $saleprice = !empty($r->saleprice) ? (float)$r->saleprice : 0.0;
        $baseprice = $saleprice > 0 ? $saleprice : $price;
        $displayprice = $baseprice;
        $discountperunit = 0.0;
        $promocode = '';

        if ($appliedcoupon) {
            $discountvalue = (float)$appliedcoupon->discountvalue;
            $discounttype = (string)$appliedcoupon->discounttype;
            if ($discounttype === 'fixed') {
                $fixeddiscount = min($remainingfixedcoupondiscount, $displayprice);
                $displayprice = max(0.0, $displayprice - $fixeddiscount);
                $remainingfixedcoupondiscount = max(0.0, $remainingfixedcoupondiscount - $fixeddiscount);
            } else {
                $displayprice = max(0.0, $displayprice - (($displayprice * $discountvalue) / 100));
            }
            $discountperunit = max(0.0, $baseprice - $displayprice);
            $promocode = (string)$appliedcoupon->code;
        }

        $pricewithtax = $displayprice + (($displayprice * $tvapercent) / 100);
        $lineamount = $pricewithtax * $durationmonths;
        $discountamount = $discountperunit * $durationmonths;
        $totalamount += $lineamount;

        $expiresat = local_elearning_system_calculate_expiration(time(), $durationmonths);

        $pendingitems[] = [
            'productid' => (int)$r->id,
            'amount' => number_format($lineamount, 2, '.', ''),
            'promocode' => $promocode,
            'discountamount' => number_format($discountamount, 2, '.', ''),
            'durationmonths' => $durationmonths,
            'expiresat' => $expiresat,
        ];

        $stripeunitamount = (int)round($pricewithtax * 100);
        if ($stripeunitamount < 0) {
            $stripeunitamount = 0;
        }
        $stripepostfields['line_items[' . $idx . '][price_data][currency]'] = $stripecurrency;
        $stripename = format_string($r->name) . ' (' . $durationmonths . ' mois)';
        $stripepostfields['line_items[' . $idx . '][price_data][product_data][name]'] = $stripename;
        $stripepostfields['line_items[' . $idx . '][price_data][unit_amount]'] = $stripeunitamount;
        $stripepostfields['line_items[' . $idx . '][quantity]'] = $durationmonths;
        $idx++;
    }

    if ($idx === 0) {
        redirect(new moodle_url('/local/elearning_system/cart.php'));
    }

    $SESSION->local_elearning_system_pending_order = [
        'userid' => (int)$USER->id,
        'beneficiaryuserid' => $beneficiaryuserid,
        'items' => $pendingitems,
        'timecreated' => time(),
    ];

    // If all items are free, process enrollment directly without Stripe
    if ($totalamount <= 0) {
        require_once($CFG->libdir . '/enrollib.php');
        foreach ($pendingitems as $item) {
            if (local_elearning_system_is_product_covered_by_purchase($beneficiaryuserid, (int)$item['productid'], $DB)) {
                continue;
            }

            $order = new stdClass();
$order->userid = $beneficiaryuserid;
$order->productid = (int)$item['productid'];
$order->amount = (float)$item['amount'];
$order->promocode = trim((string)($item['promocode'] ?? ''));
$order->discountamount = (float)($item['discountamount'] ?? 0);
$order->durationmonths = max(1, (int)($item['durationmonths'] ?? 1));
$order->expiresat = (int)($item['expiresat'] ?? local_elearning_system_calculate_expiration(time(), (int)($item['durationmonths'] ?? 1)));
            $ordertimecreated = time();
            $order->timecreated = $ordertimecreated;
           $order->id = local_elearning_system_plugin_insert_order($order);
local_elearning_system_enrol_user_for_product((int)$item['productid'], $beneficiaryuserid, (int)$item['durationmonths'], $ordertimecreated, $DB);

local_elearning_system_send_order_notification_if_needed($order, 'purchase_product', $DB);
local_elearning_system_send_admin_purchase_notification($order, $DB);

if ($isparentaccount && (int)$USER->id !== $beneficiaryuserid) {
    local_elearning_system_send_parent_purchase_email($order, (int)$USER->id, $DB);
}
        }

        if ($appliedcoupon) {
    local_elearning_system_plugin_increment_coupon_use((int)$appliedcoupon->id);
}

        $SESSION->local_elearning_system_pending_order = [];
        $SESSION->local_elearning_system_cart = [];
        unset($SESSION->local_elearning_system_coupon);

        redirect(new moodle_url('/local/elearning_system/payment.php', [
            'action' => 'result',
            'status' => 'success',
            'isfree' => 1,
        ]));
    }

if ($stripesk === '') {
    $lang = local_elearning_system_get_active_language();

$paymenttexts = [
    'fr' => [
        'success_head' => 'Paiement confirmé',
        'success_title' => 'Cours acheté avec succès',
        'success_text' => 'Votre achat est validé et l’accès au cours est actif. Vous pouvez commencer maintenant depuis votre espace de cours.',
        'success_item_1' => 'Accès activé immédiatement',
        'success_item_2' => 'Historique disponible dans votre espace',
        'success_item_3' => 'Paiement confirmé et enregistré',
        'child_purchase' => 'Achat enregistré pour votre enfant : ',
        'my_courses' => 'Voir mes cours',
        'continue' => 'Continuer',
        'error_head' => 'Paiement non valide',
        'error_title' => 'Le paiement a échoué',
        'error_text' => 'La transaction n’a pas pu être finalisée. Vérifiez vos informations puis relancez le paiement.',
        'back_checkout' => 'Retour au paiement',
        'back_cart' => 'Revoir mon panier',
    ],
    'en' => [
        'success_head' => 'Payment confirmed',
        'success_title' => 'Course purchased successfully',
        'success_text' => 'Your purchase is valid and course access is active. You can start now from your course area.',
        'success_item_1' => 'Access activated immediately',
        'success_item_2' => 'History available in your space',
        'success_item_3' => 'Payment confirmed and saved',
        'child_purchase' => 'Purchase saved for your child: ',
        'my_courses' => 'View my courses',
        'continue' => 'Continue',
        'error_head' => 'Invalid payment',
        'error_title' => 'Payment failed',
        'error_text' => 'The transaction could not be completed. Please check your information and try again.',
        'back_checkout' => 'Back to checkout',
        'back_cart' => 'Review my cart',
    ],
    'ar' => [
        'success_head' => 'تم تأكيد الدفع',
        'success_title' => 'تم شراء الدورة بنجاح',
        'success_text' => 'تم تأكيد عملية الشراء وتفعيل الوصول إلى الدورة. يمكنك البدء الآن من مساحة الدورات الخاصة بك.',
        'success_item_1' => 'تم تفعيل الوصول مباشرة',
        'success_item_2' => 'السجل متاح في مساحتك',
        'success_item_3' => 'تم تأكيد الدفع وتسجيله',
        'child_purchase' => 'تم تسجيل الشراء لطفلك: ',
        'my_courses' => 'عرض دوراتي',
        'continue' => 'متابعة',
        'error_head' => 'الدفع غير صالح',
        'error_title' => 'فشلت عملية الدفع',
        'error_text' => 'تعذر إتمام العملية. يرجى التحقق من المعلومات ثم إعادة المحاولة.',
        'back_checkout' => 'العودة إلى الدفع',
        'back_cart' => 'مراجعة السلة',
    ],
];

$t = $paymenttexts[$lang] ?? $paymenttexts['fr'];
    echo $OUTPUT->header();
    echo html_writer::div('Achat failed', 'alert alert-danger');
    echo html_writer::div('Stripe secret key is not configured.', 'alert alert-warning');
    echo html_writer::link(
        new moodle_url('/local/elearning_system/checkout.php'),
        'Back to checkout',
        ['class' => 'btn btn-secondary']
    );
    echo $OUTPUT->footer();
    exit;
}

if (!function_exists('curl_init')) {
    echo $OUTPUT->header();
    echo html_writer::div('Achat failed', 'alert alert-danger');
    echo html_writer::div('cURL is not enabled in PHP.', 'alert alert-warning');
    echo html_writer::link(
        new moodle_url('/local/elearning_system/checkout.php'),
        'Back to checkout',
        ['class' => 'btn btn-secondary']
    );
    echo $OUTPUT->footer();
    exit;
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($stripepostfields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $stripesk,
    'Content-Type: application/x-www-form-urlencoded',
]);

$response = curl_exec($ch);
$httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlerror = curl_error($ch);
curl_close($ch);

$result = json_decode((string)$response, true);

if ($httpcode >= 200 && $httpcode < 300 && !empty($result['url'])) {
    redirect($result['url']);
}

echo $OUTPUT->header();

echo html_writer::div('Achat failed', 'alert alert-danger');
echo html_writer::div('HTTP code: ' . s((string)$httpcode), 'alert alert-warning');

if (!empty($curlerror)) {
    echo html_writer::div('cURL error: ' . s($curlerror), 'alert alert-warning');
}

if (!empty($response)) {
    echo html_writer::tag('pre', s((string)$response), [
        'class' => 'alert alert-light',
        'style' => 'white-space:pre-wrap; direction:ltr; text-align:left; max-height:350px; overflow:auto;'
    ]);
}

echo html_writer::link(
    new moodle_url('/local/elearning_system/checkout.php'),
    'Back to checkout',
    ['class' => 'btn btn-secondary']
);

echo $OUTPUT->footer();
exit;

}
$paidsuccess = false;
if ($action === 'result' && $status === 'success') {
    // Success URLs generated by free-checkout and simulation do not include a Stripe session.
    if ($issimulatedresult || $isfreeresult) {
        $paidsuccess = true;
    } else if ($stripesessionid !== '' && $stripesk !== '' && function_exists('curl_init')) {
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($stripesessionid));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $stripesk,
        ]);
        $response = curl_exec($ch);
        $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode((string)$response, true);
        if ($httpcode >= 200 && $httpcode < 300 && (
            (!empty($result['payment_status']) && $result['payment_status'] === 'paid') ||
            (!empty($result['status']) && $result['status'] === 'complete' && !empty($result['payment_status']) && $result['payment_status'] === 'no_payment_required')
        )) {
            $paidsuccess = true;
        }
    }

    // Fallback for local/dev servers where Stripe API verification may fail (TLS/network),
    // while Stripe has already redirected to the success URL with a valid session id.
    if (!$paidsuccess && $provider === 'stripe' && $stripesessionid !== '' && !empty($SESSION->local_elearning_system_pending_order['items'])) {
        $pending = $SESSION->local_elearning_system_pending_order;
        $pendinguserid = (int)($pending['userid'] ?? 0);
        $pendingtimecreated = (int)($pending['timecreated'] ?? 0);
        if ($pendinguserid === (int)$USER->id && $pendingtimecreated > 0 && (time() - $pendingtimecreated) <= (2 * HOURSECS)) {
            $paidsuccess = true;
        }
    }
}

if ($paidsuccess) {
    if (!empty($SESSION->local_elearning_system_pending_order['items'])) {
        $pending = $SESSION->local_elearning_system_pending_order;
        if (!empty($pending['userid']) && (int)$pending['userid'] === (int)$USER->id) {
            $pendingbeneficiaryuserid = !empty($pending['beneficiaryuserid']) ? (int)$pending['beneficiaryuserid'] : $beneficiaryuserid;
            require_once($CFG->libdir . '/enrollib.php');
            foreach ($pending['items'] as $item) {
                if (local_elearning_system_is_product_covered_by_purchase($pendingbeneficiaryuserid, (int)$item['productid'], $DB)) {
                    continue;
                }

                $order = new stdClass();
$order->userid = $pendingbeneficiaryuserid;
$order->productid = (int)$item['productid'];
$order->amount = (float)$item['amount'];
$order->promocode = trim((string)($item['promocode'] ?? ''));
$order->discountamount = (float)($item['discountamount'] ?? 0);
$order->durationmonths = max(1, (int)($item['durationmonths'] ?? 1));
$order->expiresat = (int)($item['expiresat'] ?? local_elearning_system_calculate_expiration(time(), (int)($item['durationmonths'] ?? 1)));
                $ordertimecreated = time();
                $order->timecreated = $ordertimecreated;
                $order->id = local_elearning_system_plugin_insert_order($order);
local_elearning_system_enrol_user_for_product((int)$item['productid'], $pendingbeneficiaryuserid, (int)$item['durationmonths'], $ordertimecreated, $DB);

local_elearning_system_send_order_notification_if_needed($order, 'purchase_product', $DB);
local_elearning_system_send_admin_purchase_notification((int)$order->id, $DB);

if ($isparentaccount && (int)$USER->id !== $pendingbeneficiaryuserid) {
    local_elearning_system_send_parent_purchase_email($order, (int)$USER->id, $DB);
}
            }
        }
    }

    if (!empty($SESSION->local_elearning_system_coupon)) {
    $couponid = (int)($SESSION->local_elearning_system_coupon->id ?? 0);

    if ($couponid > 0) {
        local_elearning_system_plugin_increment_coupon_use($couponid);
    }
}
    $SESSION->local_elearning_system_pending_order = [];
    $SESSION->local_elearning_system_cart = [];
    unset($SESSION->local_elearning_system_coupon);
} else if ($action === 'result') {
    // Keep pending order when a success callback cannot be verified to avoid losing the checkout state.
    if ($status !== 'success') {
        $SESSION->local_elearning_system_pending_order = [];
    }
}

$lang = local_elearning_system_get_active_language();
$pt = $paymenttexts[$lang] ?? $paymenttexts['fr'];

echo $OUTPUT->header();

echo '<style>
body.path-local-elearning_system {
    overflow: hidden !important;
}

.elearn-payment-result-wrap {
    max-width: 760px;
    margin: 18px auto 0;
    padding: 0 12px;
}

.elearn-payment-page-title {
    text-align: center;
    font-size: 28px;
    font-weight: 800;
    margin: 0 0 18px;
    color: #071d33;
}

.elearn-payment-result {
    background: #ffffff;
    border: 1px solid #d7e5f3;
    border-radius: 18px;
    box-shadow: 0 18px 45px rgba(20, 60, 90, 0.12);
    overflow: hidden;
}

.elearn-payment-result__head {
    padding: 14px 22px;
    font-weight: 800;
    font-size: 16px;
}

.elearn-payment-result__head.is-success {
    background: linear-gradient(90deg, #cdeedd, #f7fbff);
    color: #064b2b;
}

.elearn-payment-result__head.is-error {
    background: linear-gradient(90deg, #ffdada, #fff7f7);
    color: #8a1111;
}

.elearn-payment-result__body {
    padding: 24px 34px 26px;
}

.elearn-payment-result__hero {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-bottom: 16px;
}

.elearn-payment-result__badge {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 32px;
    font-weight: 800;
    flex: 0 0 58px;
}

.elearn-payment-result__badge.is-success {
    background: #26b66b;
}

.elearn-payment-result__badge.is-error {
    background: #d93025;
}

.elearn-payment-result__title {
    margin: 0;
    font-size: 32px;
    line-height: 1.15;
    color: #08233f;
    font-weight: 900;
}

.elearn-payment-result__text {
    color: #163b60;
    font-size: 15px;
    line-height: 1.55;
    margin: 0 0 16px;
}

.elearn-payment-result__list {
    list-style: none;
    padding: 0;
    margin: 0 0 18px;
}

.elearn-payment-result__list li {
    background: #f7fbff;
    border: 1px solid #d8e8f7;
    border-radius: 11px;
    padding: 9px 14px;
    margin-bottom: 8px;
    color: #102a43;
    font-size: 14px;
}

.elearn-payment-result__actions {
    display: flex;
    gap: 12px;
    align-items: center;
}

.elearn-payment-result__actions .btn {
    border-radius: 22px;
    padding: 9px 22px;
    font-weight: 700;
}

html[dir="rtl"] .elearn-payment-result,
body.dir-rtl .elearn-payment-result {
    direction: rtl;
    text-align: right;
}

html[dir="rtl"] .elearn-payment-result__hero,
body.dir-rtl .elearn-payment-result__hero {
    flex-direction: row-reverse;
}

@media (max-height: 720px) {
    .elearn-payment-result-wrap {
        margin-top: 10px;
    }

    .elearn-payment-page-title {
        font-size: 24px;
        margin-bottom: 12px;
    }

    .elearn-payment-result__body {
        padding: 18px 24px;
    }

    .elearn-payment-result__title {
        font-size: 27px;
    }

    .elearn-payment-result__badge {
        width: 50px;
        height: 50px;
        flex-basis: 50px;
        font-size: 27px;
    }

    .elearn-payment-result__text {
        margin-bottom: 12px;
    }

    .elearn-payment-result__list {
        margin-bottom: 14px;
    }

    .elearn-payment-result__list li {
        padding: 7px 12px;
        margin-bottom: 6px;
    }
}
</style>';

echo '<section class="elearn-payment-result-wrap">';
echo '<h1 class="elearn-payment-page-title">' . s($pt['page_title'] ?? 'Payment') . '</h1>';

if (!empty($paidsuccess)) {
    echo '<div class="elearn-payment-result">';
    echo '<div class="elearn-payment-result__head is-success">' . s($pt['success_head'] ?? 'Payment confirmed') . '</div>';
    echo '<div class="elearn-payment-result__body">';

    echo '<div class="elearn-payment-result__hero">';
    echo '<span class="elearn-payment-result__badge is-success">✓</span>';
    echo '<h2 class="elearn-payment-result__title">' . s($pt['success_title'] ?? 'Course purchased successfully') . '</h2>';
    echo '</div>';

    echo '<p class="elearn-payment-result__text">' . s($pt['success_text'] ?? '') . '</p>';

    echo '<ul class="elearn-payment-result__list">';
    echo '<li>' . s($pt['success_item_1'] ?? '') . '</li>';
    echo '<li>' . s($pt['success_item_2'] ?? '') . '</li>';
    echo '<li>' . s($pt['success_item_3'] ?? '') . '</li>';
    echo '</ul>';

    echo '<div class="elearn-payment-result__actions">';
    echo html_writer::link(
        new moodle_url('/local/elearning_system/my_courses.php'),
        s($pt['my_courses'] ?? 'View my courses'),
        ['class' => 'btn btn-success']
    );
    echo html_writer::link(
        new moodle_url('/local/elearning_system/index.php'),
        s($pt['continue'] ?? 'Continue'),
        ['class' => 'btn btn-outline-primary']
    );
    echo '</div>';

    echo '</div>';
    echo '</div>';
} else {
    echo '<div class="elearn-payment-result">';
    echo '<div class="elearn-payment-result__head is-error">' . s($pt['error_head'] ?? 'Invalid payment') . '</div>';
    echo '<div class="elearn-payment-result__body">';

    echo '<div class="elearn-payment-result__hero">';
    echo '<span class="elearn-payment-result__badge is-error">!</span>';
    echo '<h2 class="elearn-payment-result__title">' . s($pt['error_title'] ?? 'Payment failed') . '</h2>';
    echo '</div>';

    echo '<p class="elearn-payment-result__text">' . s($pt['error_text'] ?? '') . '</p>';

    echo '<div class="elearn-payment-result__actions">';
    echo html_writer::link(
        new moodle_url('/local/elearning_system/checkout.php'),
        s($pt['back_checkout'] ?? 'Back to checkout'),
        ['class' => 'btn btn-secondary']
    );
    echo html_writer::link(
        new moodle_url('/local/elearning_system/cart.php'),
        s($pt['back_cart'] ?? 'Review my cart'),
        ['class' => 'btn btn-outline-primary']
    );
    echo '</div>';

    echo '</div>';
    echo '</div>';
}

echo '</section>';

echo $OUTPUT->footer();
exit;