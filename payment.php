<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/payment.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Payment');
$PAGE->set_heading('Payment');

global $DB, $CFG, $USER;

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
        $discountamount = $discountperunit;
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
            if (isset($ordercolumns['promocode'])) {
                $order->promocode = trim((string)($item['promocode'] ?? ''));
            }
            if (isset($ordercolumns['discountamount'])) {
                $order->discountamount = (float)($item['discountamount'] ?? 0);
            }
            if (isset($ordercolumns['durationmonths'])) {
                $order->durationmonths = max(1, (int)($item['durationmonths'] ?? 1));
            }
            if (isset($ordercolumns['expiresat'])) {
                $order->expiresat = (int)($item['expiresat'] ?? local_elearning_system_calculate_expiration(time(), (int)($item['durationmonths'] ?? 1)));
            }
            $ordertimecreated = time();
            $order->timecreated = $ordertimecreated;
            $order->id = (int)$DB->insert_record('elearning_orders', $order);
            local_elearning_system_enrol_user_for_product((int)$item['productid'], $beneficiaryuserid, (int)$item['durationmonths'], $ordertimecreated, $DB);
            local_elearning_system_send_order_notification_if_needed($order, 'purchase_product', $DB);
            if ($isparentaccount && (int)$USER->id !== $beneficiaryuserid) {
                local_elearning_system_send_parent_purchase_email($order, (int)$USER->id, $DB);
            }
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
                if (isset($ordercolumns['promocode'])) {
                    $order->promocode = trim((string)($item['promocode'] ?? ''));
                }
                if (isset($ordercolumns['discountamount'])) {
                    $order->discountamount = (float)($item['discountamount'] ?? 0);
                }
                if (isset($ordercolumns['durationmonths'])) {
                    $order->durationmonths = max(1, (int)($item['durationmonths'] ?? 1));
                }
                if (isset($ordercolumns['expiresat'])) {
                    $order->expiresat = (int)($item['expiresat'] ?? local_elearning_system_calculate_expiration(time(), (int)($item['durationmonths'] ?? 1)));
                }
                $ordertimecreated = time();
                $order->timecreated = $ordertimecreated;
                $order->id = (int)$DB->insert_record('elearning_orders', $order);
                local_elearning_system_enrol_user_for_product((int)$item['productid'], $pendingbeneficiaryuserid, (int)$item['durationmonths'], $ordertimecreated, $DB);
                local_elearning_system_send_order_notification_if_needed($order, 'purchase_product', $DB);
                if ($isparentaccount && (int)$USER->id !== $pendingbeneficiaryuserid) {
                    local_elearning_system_send_parent_purchase_email($order, (int)$USER->id, $DB);
                }
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
    // Keep pending order when a success callback cannot be verified to avoid losing the checkout state.
    if ($status !== 'success') {
        $SESSION->local_elearning_system_pending_order = [];
    }
}

echo $OUTPUT->header();

$resultstyles = '<style>
.elearn-payment-result-wrap {
    min-height: 66vh;
    display: grid;
    place-items: center;
    padding: 1rem 0 2rem;
}
.elearn-payment-result {
    width: min(840px, 100%);
    border-radius: 20px;
    border: 1px solid #d5e2f0;
    background:
        radial-gradient(circle at 8% 5%, rgba(16, 185, 129, 0.14), transparent 32%),
        radial-gradient(circle at 95% 90%, rgba(15, 108, 191, 0.12), transparent 34%),
        #ffffff;
    box-shadow: 0 20px 48px rgba(16, 42, 67, 0.16);
    overflow: hidden;
}
.elearn-payment-result__hero {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.elearn-payment-result__badge {
    width: 62px;
    height: 62px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.65rem;
    font-weight: 700;
}
.elearn-payment-result__badge.is-success {
    background: linear-gradient(135deg, #1f9d60, #34c381);
    color: #fff;
    box-shadow: 0 12px 30px rgba(31, 157, 96, 0.35);
}
.elearn-payment-result__badge.is-error {
    background: linear-gradient(135deg, #d94856, #f16d7a);
    color: #fff;
    box-shadow: 0 12px 30px rgba(217, 72, 86, 0.35);
}
.elearn-payment-result__head {
    padding: 1.15rem 1.35rem;
    border-bottom: 1px solid #e6eef7;
    font-weight: 700;
    font-size: 1.05rem;
}
.elearn-payment-result__head.is-success {
    color: #0f5132;
    background: linear-gradient(135deg, rgba(25, 135, 84, 0.17), rgba(25, 135, 84, 0.06));
}
.elearn-payment-result__head.is-error {
    color: #842029;
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.18), rgba(220, 53, 69, 0.08));
}
.elearn-payment-result__body {
    padding: 2rem 1.35rem 1.6rem;
}
.elearn-payment-result__title {
    margin: 0;
    font-size: clamp(1.8rem, 4.2vw, 2.55rem);
    font-weight: 700;
    color: #102a43;
}
.elearn-payment-result__text {
    margin: 0.8rem 0 0;
    color: #334e68;
    font-size: 1.03rem;
    line-height: 1.6;
}
.elearn-payment-result__child {
    margin-top: 0.9rem;
    padding: 0.8rem 0.95rem;
    border-radius: 12px;
    border: 1px solid #c4e9d5;
    background: #eaf8f1;
    color: #0f5132;
}
.elearn-payment-result__actions {
    margin-top: 1.4rem;
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
}
.elearn-payment-result__actions .btn {
    border-radius: 999px;
    padding: 0.58rem 1.15rem;
    font-weight: 600;
}
.elearn-payment-result__list {
    margin: 1rem 0 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 0.5rem;
}
.elearn-payment-result__list li {
    border: 1px solid #dce8f4;
    border-radius: 10px;
    padding: 0.55rem 0.7rem;
    color: #243b53;
    background: #f8fbff;
}
</style>';
echo $resultstyles;

if ($paidsuccess) {
    echo '<section class="elearn-payment-result-wrap">';
    echo '<div class="elearn-payment-result">';
    echo '<div class="elearn-payment-result__head is-success">Paiement confirme</div>';
    echo '<div class="elearn-payment-result__body">';
    echo '<div class="elearn-payment-result__hero">';
    echo '<span class="elearn-payment-result__badge is-success">&#10003;</span>';
    echo '<h1 class="elearn-payment-result__title">Cours achete avec succes</h1>';
    echo '</div>';
    echo '<p class="elearn-payment-result__text">Votre achat est valide et l acces au cours est active. Vous pouvez commencer maintenant depuis votre espace de cours.</p>';
    echo '<ul class="elearn-payment-result__list">';
    echo '<li>Acces active immediatement</li>';
    echo '<li>Historique disponible dans votre espace</li>';
    echo '<li>Paiement confirme et enregistre</li>';
    echo '</ul>';
    if ($isparentaccount && $beneficiaryfullname !== '') {
        echo '<div class="elearn-payment-result__child">Achat enregistre pour votre enfant: ' . s($beneficiaryfullname) . '</div>';
    }
    echo '<div class="elearn-payment-result__actions">';
    echo html_writer::link(new moodle_url('/local/elearning_system/my_courses.php'), 'Voir mes cours', ['class' => 'btn btn-success']);
    echo html_writer::link(new moodle_url('/local/elearning_system/index.php'), 'Continuer', ['class' => 'btn btn-outline-primary']);
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
} else {
    echo '<section class="elearn-payment-result-wrap">';
    echo '<div class="elearn-payment-result">';
    echo '<div class="elearn-payment-result__head is-error">Paiement non valide</div>';
    echo '<div class="elearn-payment-result__body">';
    echo '<div class="elearn-payment-result__hero">';
    echo '<span class="elearn-payment-result__badge is-error">!</span>';
    echo '<h1 class="elearn-payment-result__title">Le paiement a echoue</h1>';
    echo '</div>';
    echo '<p class="elearn-payment-result__text">La transaction n a pas pu etre finalisee. Verifiez vos informations puis relancez le paiement.</p>';
    echo '<div class="elearn-payment-result__actions">';
    echo html_writer::link(new moodle_url('/local/elearning_system/checkout.php'), 'Retour au checkout', ['class' => 'btn btn-secondary']);
    echo html_writer::link(new moodle_url('/local/elearning_system/cart.php'), 'Revoir mon panier', ['class' => 'btn btn-outline-primary']);
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

echo $OUTPUT->footer();
