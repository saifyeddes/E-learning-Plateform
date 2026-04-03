<?php

require('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/payement.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Payement TVA');
$PAGE->set_heading('Payement TVA');

global $DB;

$errors = [];
$successmessage = '';

$rawtvapercent = trim((string)optional_param('tvapercent', '', PARAM_RAW_TRIMMED));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $tvapercent = 0.0;
    if ($rawtvapercent !== '') {
        if (!is_numeric($rawtvapercent)) {
            $errors[] = 'TVA must be a valid number.';
        } else {
            $tvapercent = (float)$rawtvapercent;
        }
    }

    if ($tvapercent < 0 || $tvapercent > 100) {
        $errors[] = 'TVA must be between 0 and 100.';
    }

    if (empty($errors)) {
        set_config('vat_percent', $tvapercent, 'local_elearning_system');
        $successmessage = 'TVA updated successfully.';
    }
}

$configtvapercent = (float)get_config('local_elearning_system', 'vat_percent');
if ($configtvapercent < 0 || $configtvapercent > 100) {
    $configtvapercent = 0.0;
}

$tvapercentfordisplay = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors))
    ? ($rawtvapercent === '' ? '0' : s($rawtvapercent))
    : number_format($configtvapercent, 2, '.', '');

$templatedata = [
    'dashboardurl' => (new moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl' => (new moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'couponsurl' => (new moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new moodle_url('/local/elearning_system/admin/payement.php'))->out(false),

    'isdashboard' => false,
    'isproducts' => false,
    'isorders' => false,
    'iscoupons' => false,
    'ispayement' => true,

    'errors' => $errors,
    'haserrors' => !empty($errors),
    'successmessage' => $successmessage,
    'hassuccessmessage' => $successmessage !== '',
    'tvapercent' => $tvapercentfordisplay,
    'sesskey' => sesskey(),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();
