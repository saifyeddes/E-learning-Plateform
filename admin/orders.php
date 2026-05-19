<?php

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/plugin_db.php');
require_once(__DIR__ . '/../lib.php');
require_login();
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/orders.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Orders');
$PAGE->set_heading('Orders');

global $DB;
function local_elearning_system_plugin_orders_db(): mysqli {
    return \local_elearning_system\plugin_db::get();
}

function local_elearning_system_plugin_get_order_products_for_filter(): array {
    $db = local_elearning_system_plugin_orders_db();

    $result = $db->query("SELECT id, name FROM el_products ORDER BY name ASC");

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $products = [];
    while ($row = $result->fetch_object()) {
        $products[(int)$row->id] = $row;
    }

    return $products;
}

function local_elearning_system_plugin_get_admin_orders(): array {
    $db = local_elearning_system_plugin_orders_db();

    $sql = "SELECT o.id, o.userid, o.productid, o.amount, o.timecreated,
                   o.promocode, o.durationmonths, o.expiresat,
                   p.name AS productname
              FROM el_orders o
         LEFT JOIN el_products p ON p.id = o.productid
          ORDER BY o.id DESC";

    $result = $db->query($sql);

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $orders = [];
    while ($row = $result->fetch_object()) {
        $orders[(int)$row->id] = $row;
    }

    return $orders;
}

$searchquery = trim((string)optional_param('search', '', PARAM_TEXT));
$selectedproductid = optional_param('productid', 0, PARAM_INT);
$expirationfrom = trim((string)optional_param('expirationfrom', '', PARAM_RAW));
$expirationto = trim((string)optional_param('expirationto', '', PARAM_RAW));
$page = max(1, optional_param('page', 1, PARAM_INT));
$perpage = 5;
function local_elearning_system_parse_date_filter(string $date, bool $endofday = false): int {
    $date = trim($date);

    if ($date === '') {
        return 0;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);

    if (!$dt) {
        return 0;
    }

    if ($endofday) {
        $dt->setTime(23, 59, 59);
    } else {
        $dt->setTime(0, 0, 0);
    }

    return $dt->getTimestamp();
}
$listparams = [];
if ($searchquery !== '') {
    $listparams['search'] = $searchquery;
}
if ($selectedproductid !== 0) {
    $listparams['productid'] = $selectedproductid;
}
if ($expirationfrom !== '') {
    $listparams['expirationfrom'] = $expirationfrom;
}
if ($expirationto !== '') {
    $listparams['expirationto'] = $expirationto;
}

if ($expirationfrom !== '') {
    $listparams['expirationfrom'] = $expirationfrom;
}

if ($expirationto !== '') {
    $listparams['expirationto'] = $expirationto;
}

$expirationfromts = local_elearning_system_parse_date_filter($expirationfrom, false);
$expirationtots = local_elearning_system_parse_date_filter($expirationto, true);

if ($expirationfrom !== '') {
    $expirationfromts = strtotime($expirationfrom . ' 00:00:00');
    if ($expirationfromts === false) {
        $expirationfromts = 0;
    }
}

if ($expirationto !== '') {
    $expirationtots = strtotime($expirationto . ' 23:59:59');
    if ($expirationtots === false) {
        $expirationtots = 0;
    }
}
$orders = [];
$pageitems = [];
$productfilters = [[
    'value' => 0,
    'label' => 'All products',
    'selected' => $selectedproductid === 0,
]];

$productrecords = local_elearning_system_plugin_get_order_products_for_filter();

foreach ($productrecords as $productrecord) {
    $productfilters[] = [
        'value' => (int)$productrecord->id,
        'label' => format_string($productrecord->name),
        'selected' => $selectedproductid === (int)$productrecord->id,
    ];
}
$records = local_elearning_system_plugin_get_admin_orders();

foreach ($records as $r) {
    $user = $DB->get_record('user', ['id' => (int)$r->userid], 'id, firstname, lastname, email', IGNORE_MISSING);

    $firstname = $user ? (string)$user->firstname : '';
    $lastname = $user ? (string)$user->lastname : '';
    $email = $user ? (string)$user->email : '-';

    $fullname = trim($firstname . ' ' . $lastname);
    if ($fullname === '') {
        $fullname = '-';
    }

    $productname = !empty($r->productname) ? format_string($r->productname) : '-';
    $promocode = trim((string)($r->promocode ?? ''));
    $durationmonths = max(1, (int)($r->durationmonths ?? 1));

    if (!empty($r->expiresat)) {
        $expirationtimestamp = (int)$r->expiresat;
    } else {
        $expirationtimestamp = strtotime('+' . $durationmonths . ' months', (int)$r->timecreated);
        if ($expirationtimestamp === false) {
            $expirationtimestamp = (int)$r->timecreated;
        }
    }

    if ($expirationfromts > 0 && $expirationtimestamp < $expirationfromts) {
        continue;
    }

    if ($expirationtots > 0 && $expirationtimestamp > $expirationtots) {
        continue;
    }

    if ($selectedproductid !== 0 && (int)$r->productid !== $selectedproductid) {
        continue;
    }

    if ($searchquery !== '') {
        $haystack = core_text::strtolower(implode(' ', [
            (string)$r->id,
            $fullname,
            $email,
            $productname,
            $promocode,
            (string)$durationmonths,
            (string)$r->amount,
        ]));

        $needle = core_text::strtolower($searchquery);

        if (strpos($haystack, $needle) === false) {
            continue;
        }
    }

    $orders[] = [
        'id' => (int)$r->id,
        'user' => format_string($fullname),
        'email' => s($email),
        'product' => $productname,
        'promo' => $promocode !== '' ? ('Yes (' . s($promocode) . ')') : 'No',
        'durationmonths' => $durationmonths,
        'durationachetee' => $durationmonths . ' mois',
        'amount' => local_elearning_system_format_price((float)$r->amount),
        'timecreated' => userdate((int)$r->timecreated, '%d/%m/%Y %H:%M'),
        'expirationdate' => userdate($expirationtimestamp, '%d/%m/%Y %H:%M'),
        'invoiceurl' => (new \moodle_url('/local/elearning_system/admin/invoice.php', ['id' => (int)$r->id]))->out(false),
    ];
}

$totalorders = count($orders);
$totalpages = max(1, (int)ceil($totalorders / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
}
$offset = ($page - 1) * $perpage;
$orders = array_slice($orders, $offset, $perpage);

if ($totalpages > 1) {
    $pageitems[] = [
        'label' => 'Precedent',
        'url' => $page > 1 ? (new \moodle_url('/local/elearning_system/admin/orders.php', $listparams + ['page' => $page - 1]))->out(false) : null,
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
            'url' => (new \moodle_url('/local/elearning_system/admin/orders.php', $listparams + ['page' => $i]))->out(false),
            'active' => $i === $page,
        ];
    }

    $pageitems[] = [
        'label' => 'Suivante',
        'url' => $page < $totalpages ? (new \moodle_url('/local/elearning_system/admin/orders.php', $listparams + ['page' => $page + 1]))->out(false) : null,
        'disabled' => $page >= $totalpages,
        'isnav' => true,
    ];
}

$hasfilters = ($searchquery !== '' || $selectedproductid !== 0 || $expirationfrom !== '' || $expirationto !== '');

$templatedata = [
    'orders' => $orders,
    'hasorders' => !empty($orders),
    'hasfilters' => $hasfilters,
    'noordersmessage' => $hasfilters ? 'No orders match your filters.' : 'No orders yet.',
    'searchquery' => $searchquery,
    'expirationfrom' => $expirationfrom,
'expirationto' => $expirationto,
    'productfilters' => $productfilters,
    'filterurl' => (new \moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'pageitems' => $pageitems,
    'haspagination' => ($totalpages > 1),

    'dashboardurl' => (new \moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl' => (new \moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new \moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'parentsurl' => (new \moodle_url('/local/elearning_system/admin/parents.php'))->out(false),
    'couponsurl' => (new \moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new \moodle_url('/local/elearning_system/admin/payement.php'))->out(false),
    'emailtemplatesurl' => (new \moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),

    'isdashboard' => false,
    'isproducts' => false,
    'isorders' => true,
    'isparents' => false,
    'iscoupons' => false,
    'ispayement' => false,
    'isemailtemplates' => false,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();
