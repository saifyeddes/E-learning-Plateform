<?php

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

$listparams = [];
if ($searchquery !== '') {
    $listparams['search'] = $searchquery;
}
if ($selectedproductid !== 0) {
    $listparams['productid'] = $selectedproductid;
}

$orders = [];
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

    $sql = "SELECT o.id, o.userid, o.productid, o.amount, o.timecreated,
                   {$promoselect},
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
            'amount' => number_format((float)$r->amount, 2),
            'timecreated' => userdate((int)$r->timecreated),
            'invoiceurl' => (new moodle_url('/local/elearning_system/admin/invoice.php', ['id' => (int)$r->id]))->out(false),
        ];
    }
}

$hasfilters = ($searchquery !== '' || $selectedproductid !== 0);

$templatedata = [
    'orders' => $orders,
    'hasorders' => !empty($orders),
    'hasfilters' => $hasfilters,
    'noordersmessage' => $hasfilters ? 'No orders match your filters.' : 'No orders yet.',
    'searchquery' => $searchquery,
    'productfilters' => $productfilters,
    'filterurl' => (new moodle_url('/local/elearning_system/admin/orders.php'))->out(false),

    'dashboardurl' => (new moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl' => (new moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'couponsurl' => (new moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new moodle_url('/local/elearning_system/admin/payement.php'))->out(false),

    'isdashboard' => false,
    'isproducts' => false,
    'isorders' => true,
    'iscoupons' => false,
    'ispayement' => false,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();
