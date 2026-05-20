<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/plugin_db.php');
require_login();

$context = \context_system::instance();
require_capability('local/elearning_system:manage', \context_system::instance());

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/coupons.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Coupons');
$PAGE->set_heading('Manage Coupons');

global $DB, $CFG;
function local_elearning_system_coupons_db(): mysqli {
    return \local_elearning_system\plugin_db::get();
}

function local_elearning_system_coupon_get_by_id(int $id): ?stdClass {
    $db = local_elearning_system_coupons_db();

    $stmt = $db->prepare("SELECT * FROM el_coupons WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $coupon = $result ? $result->fetch_object() : null;

    $stmt->close();

    return $coupon ?: null;
}

function local_elearning_system_coupon_get_by_code(string $code): ?stdClass {
    $db = local_elearning_system_coupons_db();

    $stmt = $db->prepare("SELECT * FROM el_coupons WHERE code = ? LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();

    $result = $stmt->get_result();
    $coupon = $result ? $result->fetch_object() : null;

    $stmt->close();

    return $coupon ?: null;
}

function local_elearning_system_coupon_delete(int $id): void {
    $db = local_elearning_system_coupons_db();

    $stmt = $db->prepare("DELETE FROM el_coupons WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

function local_elearning_system_coupon_insert(stdClass $coupon): int {
    $db = local_elearning_system_coupons_db();

    $stmt = $db->prepare("
        INSERT INTO el_coupons
        (code, targetproductid, discounttype, discountvalue, minpurchase, maxuse, currentuse, status, expirydate, timecreated)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $code = (string)$coupon->code;
    $targetproductid = !empty($coupon->targetproductid) ? (int)$coupon->targetproductid : 0;
    $discounttype = (string)$coupon->discounttype;
    $discountvalue = (float)$coupon->discountvalue;
    $minpurchase = null;
    $maxuse = !empty($coupon->maxuse) ? (int)$coupon->maxuse : null;
    $currentuse = !empty($coupon->currentuse) ? (int)$coupon->currentuse : 0;
    $status = (string)$coupon->status;
    $expirydate = !empty($coupon->expirydate) ? (int)$coupon->expirydate : null;
    $timecreated = !empty($coupon->timecreated) ? (int)$coupon->timecreated : time();

    $stmt->bind_param(
        'sisddiisii',
        $code,
        $targetproductid,
        $discounttype,
        $discountvalue,
        $minpurchase,
        $maxuse,
        $currentuse,
        $status,
        $expirydate,
        $timecreated
    );

    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();

    return $id;
}

function local_elearning_system_coupon_update(stdClass $coupon): void {
    $db = local_elearning_system_coupons_db();

    $stmt = $db->prepare("
        UPDATE el_coupons
           SET code = ?,
               targetproductid = ?,
               discounttype = ?,
               discountvalue = ?,
               minpurchase = ?,
               maxuse = ?,
               status = ?,
               expirydate = ?
         WHERE id = ?
    ");

    $id = (int)$coupon->id;
    $code = (string)$coupon->code;
    $targetproductid = !empty($coupon->targetproductid) ? (int)$coupon->targetproductid : 0;
    $discounttype = (string)$coupon->discounttype;
    $discountvalue = (float)$coupon->discountvalue;
    $minpurchase = null;
    $maxuse = !empty($coupon->maxuse) ? (int)$coupon->maxuse : null;
    $status = (string)$coupon->status;
    $expirydate = !empty($coupon->expirydate) ? (int)$coupon->expirydate : null;

    $stmt->bind_param(
        'sisddisii',
        $code,
        $targetproductid,
        $discounttype,
        $discountvalue,
        $minpurchase,
        $maxuse,
        $status,
        $expirydate,
        $id
    );

    $stmt->execute();
    $stmt->close();
}

function local_elearning_system_coupon_get_all(): array {
    $db = local_elearning_system_coupons_db();

    $result = $db->query("SELECT * FROM el_coupons ORDER BY timecreated DESC");

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $coupons = [];
    while ($row = $result->fetch_object()) {
        $coupons[(int)$row->id] = $row;
    }

    return $coupons;
}

$action = optional_param('action', '', PARAM_ALPHA);
$couponid = optional_param('id', 0, PARAM_INT);
$page = max(1, optional_param('page', 1, PARAM_INT));
$perpage = 5;
$errors = [];
$showcoupondrawer = false;
$editingcoupon = null;

// =============================
// HANDLE DELETE
// =============================
if ($action === 'delete' && $couponid && confirm_sesskey()) {
    local_elearning_system_coupon_delete((int)$couponid);
    redirect(new \moodle_url('/local/elearning_system/admin/coupons.php'));
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
        $existing = local_elearning_system_coupon_get_by_code(strtoupper($code));
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
            local_elearning_system_coupon_update($coupon);
            redirect(new \moodle_url('/local/elearning_system/admin/coupons.php'), 'Coupon updated successfully');
        } else {
            // Create
            $coupon->timecreated = time();
            $coupon->currentuse = 0;
            local_elearning_system_coupon_insert($coupon);
            redirect(new \moodle_url('/local/elearning_system/admin/coupons.php'), 'Coupon created successfully');
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
    $editingcoupon = local_elearning_system_coupon_get_by_id((int)$couponid);
    if (!$editingcoupon) {
        redirect(new \moodle_url('/local/elearning_system/admin/coupons.php'));
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

function local_elearning_system_coupon_get_products(): array {
    $db = local_elearning_system_coupons_db();

    $result = $db->query("SELECT id, name FROM el_products ORDER BY name ASC");

    if (!$result) {
        throw new moodle_exception('Plugin DB query error products: ' . $db->error);
    }

    $products = [];
    while ($row = $result->fetch_object()) {
        $products[(int)$row->id] = $row;
    }

    return $products;
}

$targetproducts = [];
$targetproducts[] = [
    'id' => 0,
    'name' => 'All products',
    'selected' => empty($editingcoupon->targetproductid),
];

foreach (local_elearning_system_coupon_get_products() as $product) {
    $targetproducts[] = [
        'id' => (int)$product->id,
        'name' => format_string($product->name),
        'selected' => !empty($editingcoupon->targetproductid)
            && (int)$editingcoupon->targetproductid === (int)$product->id,
    ];
}

// =============================
// GET ALL COUPONS
// =============================
$coupons = [];
$records = local_elearning_system_coupon_get_all();

foreach ($records as $r) {
    $discountlabel = '';

$discounttype = strtolower((string)($r->discounttype ?? 'percentage'));
$discountvalue = (float)($r->discountvalue ?? 0);

if ($discounttype === 'percentage') {
    $discountlabel = number_format($discountvalue, 2) . '%';
} else {
    $discountlabel = local_elearning_system_format_price($discountvalue);
}

$currentuse = isset($r->currentuse) ? (int)$r->currentuse : 0;
$maxuse = !empty($r->maxuse) ? (int)$r->maxuse : 0;

$usagelabel = $maxuse > 0
    ? $currentuse . ' / ' . $maxuse
    : $currentuse . ' / Unlimited';

$expirytext = '';
if (!empty($r->expirydate)) {
    $expirytime = (int)$r->expirydate;
    $expirytext = userdate($expirytime);
    if ($expirytime < time()) {
        $expirytext .= ' (Expired)';
    }
}

$coupons[] = [
    'id' => (int)$r->id,
    'code' => s($r->code),

    'discount' => $discountlabel,
    'usage' => $usagelabel,

    'currentuse' => $currentuse,
    'maxuse' => $maxuse,

    'status' => s((string)$r->status),
    'isstatus_active' => strtolower((string)$r->status) === 'active',
    'expirydate' => $expirytext !== '' ? $expirytext : 'No expiry',

    'editurl' => (new \moodle_url('/local/elearning_system/admin/coupons.php', [
        'action' => 'edit',
        'id' => (int)$r->id,
    ]))->out(false),

    'deleteurl' => (new \moodle_url('/local/elearning_system/admin/coupons.php', [
        'action' => 'delete',
        'id' => (int)$r->id,
        'sesskey' => sesskey(),
    ]))->out(false),
];
}
$totalcoupons = count($coupons);
$totalpages = max(1, (int)ceil($totalcoupons / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
}
$offset = ($page - 1) * $perpage;
$coupons = array_slice($coupons, $offset, $perpage);

$pageitems = [];
if ($totalpages > 1) {
    $pageitems[] = [
        'label' => 'Precedent',
        'url' => $page > 1 ? (new \moodle_url('/local/elearning_system/admin/coupons.php', ['page' => $page - 1]))->out(false) : null,
        'disabled' => $page <= 1,
        'isnav' => true,
    ];

    $windowstart = max(1, $page - 1);
    $windowend = min($totalpages, $page + 1);
    $ellipsis = false;
    for ($i = 1; $i <= $totalpages; $i++) {
        $showpage = ($i === 1) || ($i === $totalpages) || ($i >= $windowstart && $i <= $windowend);
        if (!$showpage) {
            if (!$ellipsis) {
                $pageitems[] = ['isellipsis' => true];
                $ellipsis = true;
            }
            continue;
        }

        $ellipsis = false;
        $pageitems[] = [
            'ispage' => true,
            'label' => (string)$i,
            'url' => (new \moodle_url('/local/elearning_system/admin/coupons.php', ['page' => $i]))->out(false),
            'active' => $i === $page,
        ];
    }

    $pageitems[] = [
        'label' => 'Suivante',
        'url' => $page < $totalpages ? (new \moodle_url('/local/elearning_system/admin/coupons.php', ['page' => $page + 1]))->out(false) : null,
        'disabled' => $page >= $totalpages,
        'isnav' => true,
    ];
}

// =============================
// PREPARE TEMPLATE DATA
// =============================
$templatedata = [
    'coupons' => $coupons,
    'hascoupons' => !empty($coupons),
    'pageitems' => $pageitems,
    'haspagination' => ($totalpages > 1),

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

    'formurl' => (new \moodle_url('/local/elearning_system/admin/coupons.php', [
        'action' => 'save',
        'sesskey' => sesskey(),
    ] + ($editingcoupon ? ['id' => $editingcoupon->id] : [])))->out(false),

    'cancelurl' => (new \moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),

    // Sidebar navigation
    'dashboardurl' => (new \moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl' => (new \moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new \moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'parentsurl' => (new \moodle_url('/local/elearning_system/admin/parents.php'))->out(false),
    'couponsurl' => (new \moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new \moodle_url('/local/elearning_system/admin/payement.php'))->out(false),
    'emailtemplatesurl' => (new \moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),

    'isdashboard' => false,
    'isproducts' => false,
    'isorders' => false,
    'isparents' => false,
    'iscoupons' => true,
    'ispayement' => false,
    'isemailtemplates' => false,
    'showcoupondrawer' => $showcoupondrawer,
    'coupondrawertitle' => !empty($editingcoupon->id) ? 'Edit Coupon' : 'Create Coupon',
    'currentpage' => $page,
    'totalpages' => $totalpages,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();
