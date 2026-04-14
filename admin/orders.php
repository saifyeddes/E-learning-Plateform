<?php

use moodle_url;

require('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/orders.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Orders');
$PAGE->set_heading('Orders');

global $DB;

$searchquery = trim((string)optional_param('search', '', PARAM_TEXT));
$selectedproductid = optional_param('productid', 0, PARAM_INT);
$page = max(1, optional_param('page', 1, PARAM_INT));
$perpage = 5;

$listparams = [];
if ($searchquery !== '') {
    $listparams['search'] = $searchquery;
}
if ($selectedproductid !== 0) {
    $listparams['productid'] = $selectedproductid;
}

$orders = [];
$pageitems = [];
$productfilters = [[
    'value' => 0,
    'label' => 'All products',
    'selected' => $selectedproductid === 0,
]];

if ($DB->get_manager()->table_exists('elearning_products')) {
    $productrecords = $DB->get_records('elearning_products', null, 'name ASC', 'id, name');
    foreach ($productrecords as $productrecord) {
        $productfilters[] = [
            'value' => (int)$productrecord->id,
            'label' => format_string($productrecord->name),
            'selected' => $selectedproductid === (int)$productrecord->id,
        ];
    }
}

if ($DB->get_manager()->table_exists('elearning_orders')) {
    $ordercolumns = $DB->get_columns('elearning_orders');
    $promoselect = isset($ordercolumns['promocode']) ? 'o.promocode AS promocode' : "'' AS promocode";
    $durationselect = isset($ordercolumns['durationmonths']) ? 'o.durationmonths AS durationmonths' : '1 AS durationmonths';

    $sql = "SELECT o.id, o.userid, o.productid, o.amount, o.timecreated,
                   {$promoselect},
                   {$durationselect},
                   u.firstname, u.lastname, u.email,
                   p.name AS productname
              FROM {elearning_orders} o
         LEFT JOIN {user} u ON u.id = o.userid
         LEFT JOIN {elearning_products} p ON p.id = o.productid
          ORDER BY o.id DESC";

    $records = $DB->get_records_sql($sql);
    foreach ($records as $r) {
        $fullname = trim((string)$r->firstname . ' ' . (string)$r->lastname);
        if ($fullname === '') {
            $fullname = '-';
        }

        $productname = !empty($r->productname) ? format_string($r->productname) : '-';
        $email = (string)($r->email ?? '-');
        $promocode = trim((string)($r->promocode ?? ''));
        $durationmonths = max(1, (int)($r->durationmonths ?? 1));

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
                (string)($r->durationmonths ?? 1),
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
            'amount' => number_format((float)$r->amount, 2),
            'timecreated' => userdate((int)$r->timecreated),
            'invoiceurl' => (new \moodle_url('/local/elearning_system/admin/invoice.php', ['id' => (int)$r->id]))->out(false),
        ];
    }
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

$hasfilters = ($searchquery !== '' || $selectedproductid !== 0);

$templatedata = [
    'orders' => $orders,
    'hasorders' => !empty($orders),
    'hasfilters' => $hasfilters,
    'noordersmessage' => $hasfilters ? 'No orders match your filters.' : 'No orders yet.',
    'searchquery' => $searchquery,
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
