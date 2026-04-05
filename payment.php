<?php

require('../../config.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/payment.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Payment');
$PAGE->set_heading('Payment');

global $DB, $CFG, $USER;

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
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_enrol_user_for_product(int $productid, int $userid, moodle_database $DB): void {
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

        // Prevent welcome email hook from trying to send from invalid noreply.
        $instanceforenrol = clone $manualinstance;
        $instanceforenrol->customint1 = ENROL_DO_NOT_SEND_EMAIL;
        $manualplugin->enrol_user($instanceforenrol, $userid, $studentroleid, time(), 0, ENROL_USER_ACTIVE);
    }
}

$ordercolumns = [];
if ($DB->get_manager()->table_exists('elearning_orders')) {
    $ordercolumns = $DB->get_columns('elearning_orders');
}

$action = optional_param('action', 'start', PARAM_ALPHA);
$status = optional_param('status', '', PARAM_ALPHA);
$stripesessionid = optional_param('session_id', '', PARAM_RAW_TRIMMED);

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

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}

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
    [$insql, $params] = $DB->get_in_or_equal($cartids, SQL_PARAMS_NAMED);
    $records = $DB->get_records_select('elearning_products', 'id ' . $insql, $params, 'id DESC');

    $pendingitems = [];
    $totalamount = 0.0;

    $appliedcoupon = null;
    if (!empty($SESSION->local_elearning_system_coupon)) {
        $couponid = (int)($SESSION->local_elearning_system_coupon->id ?? 0);
        if ($couponid > 0) {
            $coupon = $DB->get_record('elearning_coupons', ['id' => $couponid], '*', IGNORE_MISSING);
            if ($coupon && (string)$coupon->status === 'active' && (empty($coupon->expirydate) || (int)$coupon->expirydate >= time())) {
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
        $qty = (int)($SESSION->local_elearning_system_cart[$r->id] ?? 0);
        if ($qty <= 0) {
            continue;
        }

        if (local_elearning_system_is_product_covered_by_purchase((int)$USER->id, (int)$r->id, $DB)) {
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
        $lineamount = $pricewithtax * $qty;
        $discountamount = $discountperunit * $qty;
        $totalamount += $lineamount;

        $pendingitems[] = [
            'productid' => (int)$r->id,
            'amount' => number_format($lineamount, 2, '.', ''),
            'promocode' => $promocode,
            'discountamount' => number_format($discountamount, 2, '.', ''),
        ];

        $stripeunitamount = (int)round($pricewithtax * 100);
        if ($stripeunitamount < 0) {
            $stripeunitamount = 0;
        }
        $stripepostfields['line_items[' . $idx . '][price_data][currency]'] = $stripecurrency;
        $stripepostfields['line_items[' . $idx . '][price_data][product_data][name]'] = format_string($r->name);
        $stripepostfields['line_items[' . $idx . '][price_data][unit_amount]'] = $stripeunitamount;
        $stripepostfields['line_items[' . $idx . '][quantity]'] = $qty;
        $idx++;
    }

    if ($idx === 0) {
        redirect(new moodle_url('/local/elearning_system/cart.php'));
    }

    $SESSION->local_elearning_system_pending_order = [
        'userid' => (int)$USER->id,
        'items' => $pendingitems,
        'timecreated' => time(),
    ];

    // If all items are free, process enrollment directly without Stripe
    if ($totalamount <= 0) {
        require_once($CFG->libdir . '/enrollib.php');
        foreach ($pendingitems as $item) {
            if (local_elearning_system_is_product_covered_by_purchase((int)$USER->id, (int)$item['productid'], $DB)) {
                continue;
            }

            $order = new stdClass();
            $order->userid = (int)$USER->id;
            $order->productid = (int)$item['productid'];
            $order->amount = (float)$item['amount'];
            if (isset($ordercolumns['promocode'])) {
                $order->promocode = trim((string)($item['promocode'] ?? ''));
            }
            if (isset($ordercolumns['discountamount'])) {
                $order->discountamount = (float)($item['discountamount'] ?? 0);
            }
            $order->timecreated = time();
            $DB->insert_record('elearning_orders', $order);
            local_elearning_system_enrol_user_for_product((int)$item['productid'], (int)$USER->id, $DB);
        }

        if ($appliedcoupon) {
            $appliedcoupon->currentuse = ((int)$appliedcoupon->currentuse) + 1;
            $DB->update_record('elearning_coupons', $appliedcoupon);
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
        echo $OUTPUT->header();
        echo html_writer::div('Stripe secret key is not configured. Please set it in plugin settings.', 'alert alert-danger');
        echo html_writer::link(
            new moodle_url('/admin/settings.php', ['section' => 'local_elearning_system_paymentsettings']),
            'Open payment settings',
            ['class' => 'btn btn-primary me-2']
        );
        echo html_writer::link(new moodle_url('/local/elearning_system/checkout.php'), 'Back to checkout', ['class' => 'btn btn-secondary']);
        echo $OUTPUT->footer();
        exit;
    }

    if (!function_exists('curl_init')) {
        throw new moodle_exception('cURL is required for Stripe integration.');
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
    if (!empty($curlerror)) {
        echo html_writer::div('Stripe error: ' . s($curlerror), 'alert alert-warning');
    }
    echo html_writer::link(new moodle_url('/local/elearning_system/checkout.php'), 'Back to checkout', ['class' => 'btn btn-secondary']);
    echo $OUTPUT->footer();
    exit;
}

$paidsuccess = false;
if ($action === 'result' && $status === 'success') {
    if ($stripesessionid !== '' && $stripesk !== '' && function_exists('curl_init')) {
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($stripesessionid));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $stripesk,
        ]);
        $response = curl_exec($ch);
        $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode((string)$response, true);
        if ($httpcode >= 200 && $httpcode < 300 && !empty($result['payment_status']) && $result['payment_status'] === 'paid') {
            $paidsuccess = true;
        }
    }
}

if ($paidsuccess) {
    if (!empty($SESSION->local_elearning_system_pending_order['items'])) {
        $pending = $SESSION->local_elearning_system_pending_order;
        if (!empty($pending['userid']) && (int)$pending['userid'] === (int)$USER->id) {
            require_once($CFG->libdir . '/enrollib.php');
            foreach ($pending['items'] as $item) {
                if (local_elearning_system_is_product_covered_by_purchase((int)$USER->id, (int)$item['productid'], $DB)) {
                    continue;
                }

                $order = new stdClass();
                $order->userid = (int)$USER->id;
                $order->productid = (int)$item['productid'];
                $order->amount = (float)$item['amount'];
                if (isset($ordercolumns['promocode'])) {
                    $order->promocode = trim((string)($item['promocode'] ?? ''));
                }
                if (isset($ordercolumns['discountamount'])) {
                    $order->discountamount = (float)($item['discountamount'] ?? 0);
                }
                $order->timecreated = time();
                $DB->insert_record('elearning_orders', $order);
                local_elearning_system_enrol_user_for_product((int)$item['productid'], (int)$USER->id, $DB);
            }
        }
    }

    if (!empty($SESSION->local_elearning_system_coupon)) {
        $couponid = (int)($SESSION->local_elearning_system_coupon->id ?? 0);
        if ($couponid > 0) {
            $coupon = $DB->get_record('elearning_coupons', ['id' => $couponid], '*', IGNORE_MISSING);
            if ($coupon) {
                $coupon->currentuse = ((int)$coupon->currentuse) + 1;
                if (!empty($coupon->maxuse) && (int)$coupon->currentuse >= (int)$coupon->maxuse) {
                    $coupon->status = 'inactive';
                }
                if (!empty($coupon->maxuse) && (int)$coupon->currentuse >= (int)$coupon->maxuse) {
                    $coupon->status = 'inactive';
                }
                $DB->update_record('elearning_coupons', $coupon);
            }
        }
    }

    $SESSION->local_elearning_system_pending_order = [];
    $SESSION->local_elearning_system_cart = [];
    unset($SESSION->local_elearning_system_coupon);
} else if ($action === 'result') {
    $SESSION->local_elearning_system_pending_order = [];
}

echo $OUTPUT->header();
if ($paidsuccess) {
    echo html_writer::div('Cours achete', 'alert alert-success');
    echo html_writer::link(new moodle_url('/local/elearning_system/my_courses.php'), 'Voir mes cours', ['class' => 'btn btn-success']);
} else {
    echo html_writer::div('Achat failed', 'alert alert-danger');
    echo html_writer::link(new moodle_url('/local/elearning_system/checkout.php'), 'Retour au checkout', ['class' => 'btn btn-secondary']);
}
echo $OUTPUT->footer();
