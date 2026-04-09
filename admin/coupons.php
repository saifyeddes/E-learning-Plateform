<?php

require('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/coupons.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Coupons');
$PAGE->set_heading('Manage Coupons');

global $DB, $CFG;

$action = optional_param('action', '', PARAM_ALPHA);
$couponid = optional_param('id', 0, PARAM_INT);
$errors = [];
$showcoupondrawer = false;
$editingcoupon = null;

// =============================
// HANDLE DELETE
// =============================
if ($action === 'delete' && $couponid && confirm_sesskey()) {
    $DB->delete_records('elearning_coupons', ['id' => $couponid]);
    redirect(new moodle_url('/local/elearning_system/admin/coupons.php'));
}

// =============================
// HANDLE CREATE/UPDATE
// =============================
if ($action === 'save' && confirm_sesskey()) {
    $code = trim((string)optional_param('code', '', PARAM_TEXT));
    $discountpercent = optional_param('discountpercent', 0, PARAM_FLOAT);
    $maxuse = optional_param('maxuse', 0, PARAM_INT);
    $status = optional_param('status', 'active', PARAM_ALPHA);
    $expirydate = optional_param('expirydate', '', PARAM_TEXT);

    if (empty($code)) {
        $errors[] = 'Coupon code is required';
    } else if (strlen($code) < 3) {
        $errors[] = 'Coupon code must be at least 3 characters';
    }

    // Check if code already exists (for new coupons)
    if (empty($couponid) && !empty($code)) {
        $existing = $DB->get_record('elearning_coupons', ['code' => strtoupper($code)]);
        if ($existing) {
            $errors[] = 'This coupon code already exists';
        }
    }

    if ($discountpercent <= 0) {
        $errors[] = 'Discount percentage must be greater than 0';
    }

    if ($discountpercent > 100) {
        $errors[] = 'Percentage discount cannot exceed 100%';
    }

    if ($maxuse < 0) {
        $errors[] = 'Usage limit must be zero or greater';
    }

    if (!empty($expirydate)) {
        $expirytimestamp = strtotime($expirydate);
        if ($expirytimestamp === false || $expirytimestamp < time()) {
            $errors[] = 'Expiry date must be a valid future date';
        }
    }

    if (empty($errors)) {
        $coupon = (object)[
            'code' => strtoupper($code),
            'targetproductid' => 0,
            'discounttype' => 'percentage',
            'discountvalue' => (float)$discountpercent,
            'minpurchase' => null,
            'maxuse' => $maxuse > 0 ? (int)$maxuse : null,
            'status' => $status,
            'expirydate' => !empty($expirydate) ? strtotime($expirydate) : null,
        ];

        if ($couponid) {
            // Update
            $coupon->id = $couponid;
            $DB->update_record('elearning_coupons', $coupon);
            redirect(new moodle_url('/local/elearning_system/admin/coupons.php'), 'Coupon updated successfully');
        } else {
            // Create
            $coupon->timecreated = time();
            $coupon->currentuse = 0;
            $DB->insert_record('elearning_coupons', $coupon);
            redirect(new moodle_url('/local/elearning_system/admin/coupons.php'), 'Coupon created successfully');
        }
    } else {
        $editingcoupon = (object)[
            'id' => $couponid > 0 ? $couponid : 0,
            'code' => $code,
            'discountpercent' => number_format((float)$discountpercent, 2),
            'maxuse' => $maxuse > 0 ? (int)$maxuse : 0,
            'status' => $status,
            'expirydate' => $expirydate,
        ];
        $showcoupondrawer = true;
    }
}

// =============================
// GET EDIT DATA (if editing)
// =============================
if (($action === 'edit' || $action === 'add') && $couponid) {
    $editingcoupon = $DB->get_record('elearning_coupons', ['id' => $couponid]);
    if (!$editingcoupon) {
        redirect(new moodle_url('/local/elearning_system/admin/coupons.php'));
    }
    $editingcoupon->discountpercent = number_format((float)$editingcoupon->discountvalue, 2);
    $editingcoupon->maxuse = !empty($editingcoupon->maxuse) ? (int)$editingcoupon->maxuse : 0;
    $editingcoupon->expirydate = !empty($editingcoupon->expirydate) ? date('Y-m-d', (int)$editingcoupon->expirydate) : '';
    $showcoupondrawer = true;
}

if ($action === 'add') {
    $editingcoupon = (object)[
        'id' => 0,
        'code' => '',
        'discountpercent' => 0,
        'discountvalue' => 0,
        'maxuse' => 0,
        'status' => 'active',
        'expirydate' => '',
    ];
    $showcoupondrawer = true;
}

// =============================
// GET ALL COUPONS
// =============================
$coupons = [];
if ($DB->get_manager()->table_exists('elearning_coupons')) {
    $sql = "SELECT c.*
              FROM {elearning_coupons} c
          ORDER BY c.timecreated DESC";
    $records = $DB->get_records_sql($sql);
    foreach ($records as $r) {
        $expirytext = '';
        if (!empty($r->expirydate)) {
            $expirytime = (int)$r->expirydate;
            $isexpired = $expirytime < time();
            $expirytext = userdate($expirytime) . ($isexpired ? ' (EXPIRED)' : '');
        }

        $coupons[] = [
            'id' => (int)$r->id,
            'code' => s($r->code),
            'target' => 'All products and bundles',
            'discount' => number_format((float)$r->discountvalue, 2) . '%',
            'currentuse' => isset($r->currentuse) ? (int)$r->currentuse : 0,
            'maxuse' => !empty($r->maxuse) ? (int)$r->maxuse : 0,
            'usage' => !empty($r->maxuse)
                ? ((int)($r->currentuse ?? 0) . ' / ' . (int)$r->maxuse)
                : ((int)($r->currentuse ?? 0) . ' / Unlimited'),
            'status' => ucfirst($r->status),
            'isstatus_active' => $r->status === 'active',
            'expirydate' => $expirytext ?: 'No expiry',
            'editurl' => (new moodle_url('/local/elearning_system/admin/coupons.php', [
                'action' => 'edit',
                'id' => (int)$r->id,
            ]))->out(false),
            'deleteurl' => (new moodle_url('/local/elearning_system/admin/coupons.php', [
                'action' => 'delete',
                'id' => (int)$r->id,
                'sesskey' => sesskey(),
            ]))->out(false),
        ];
    }
}

// =============================
// PREPARE TEMPLATE DATA
// =============================
$templatedata = [
    'coupons' => $coupons,
    'hascoupons' => !empty($coupons),

    'iseditingcoupon' => !empty($editingcoupon),
    'editingcoupon' => $editingcoupon ? [
        'id' => (int)$editingcoupon->id,
        'code' => format_string($editingcoupon->code),
        'discountpercent' => !empty($editingcoupon->discountpercent) ? $editingcoupon->discountpercent : number_format((float)($editingcoupon->discountvalue ?? 0), 2),
        'maxuse' => !empty($editingcoupon->maxuse) ? (int)$editingcoupon->maxuse : 0,
        'status' => $editingcoupon->status,
        'isstatusactive' => (string)$editingcoupon->status === 'active',
        'isstatusinactive' => (string)$editingcoupon->status === 'inactive',
        'expirydate' => !empty($editingcoupon->expirydate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$editingcoupon->expirydate)
            ? date('Y-m-d', (int)$editingcoupon->expirydate)
            : (string)$editingcoupon->expirydate,
    ] : null,

    'errors' => $errors ?? [],
    'haserrors' => !empty($errors ?? []),

    'formurl' => (new moodle_url('/local/elearning_system/admin/coupons.php', [
        'action' => 'save',
        'sesskey' => sesskey(),
    ] + ($editingcoupon ? ['id' => $editingcoupon->id] : [])))->out(false),

    'cancelurl' => (new moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),

    // Sidebar navigation
    'dashboardurl' => (new moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl' => (new moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'parentsurl' => (new moodle_url('/local/elearning_system/admin/parents.php'))->out(false),
    'couponsurl' => (new moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new moodle_url('/local/elearning_system/admin/payement.php'))->out(false),

    'isdashboard' => false,
    'isproducts' => false,
    'isorders' => false,
    'isparents' => false,
    'iscoupons' => true,
    'ispayement' => false,
    'showcoupondrawer' => $showcoupondrawer,
    'coupondrawertitle' => !empty($editingcoupon->id) ? 'Edit Coupon' : 'Create Coupon',
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/coupons_layout', $templatedata);
echo $OUTPUT->footer();
