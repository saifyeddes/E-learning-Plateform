<?php

require('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/dashboard.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Dashboard');
$PAGE->set_heading('Dashboard');

global $DB;

// STATS
$totalproducts = $DB->count_records('elearning_products');

$totalorders = 0;
$totalrevenue = 0;

if ($DB->get_manager()->table_exists('elearning_orders')) {
    $totalorders = $DB->count_records('elearning_orders');

    $orders = $DB->get_records('elearning_orders');
    foreach ($orders as $o) {
        $totalrevenue += $o->amount;
    }
}

// TEMPLATE
$templatedata = [
    'dashboardurl' => (new moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl'  => (new moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'parentsurl' => (new moodle_url('/local/elearning_system/admin/parents.php'))->out(false),
    'couponsurl' => (new moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new moodle_url('/local/elearning_system/admin/payement.php'))->out(false),
    'emailtemplatesurl' => (new moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),

    'isdashboard' => true,
    'isproducts' => false,
    'isorders' => false,
    'isparents' => false,
    'iscoupons' => false,
    'ispayement' => false,
    'isemailtemplates' => false,

    'totalproducts' => $totalproducts,
    'totalorders' => $totalorders,
    'totalrevenue' => $totalrevenue
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();