<?php

require('../../../config.php');
require_once(__DIR__ . '/../classes/plugin_db.php');
require_once(__DIR__ . '/../lib.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/invoice.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Invoice');
$PAGE->set_heading('Invoice');

global $DB;
function local_elearning_system_plugin_get_invoice_order(int $orderid): ?stdClass {
    $db = \local_elearning_system\plugin_db::get();

    $stmt = $db->prepare("
        SELECT o.*, p.name AS productname, p.courseid, p.categoryid
          FROM el_orders o
     LEFT JOIN el_products p ON p.id = o.productid
         WHERE o.id = ?
         LIMIT 1
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param('i', $orderid);
    $stmt->execute();

    $result = $stmt->get_result();
    $order = $result ? $result->fetch_object() : null;

    $stmt->close();

    return $order ?: null;
}

$orderid = optional_param('id', 0, PARAM_INT);
if ($orderid <= 0) {
    throw new moodle_exception('invalidparameter');
}

$order = local_elearning_system_plugin_get_invoice_order($orderid);

if (!$order) {
    throw new moodle_exception('invalidrecord');
}

$user = $DB->get_record('user', ['id' => (int)$order->userid], 'id, firstname, lastname, email', MUST_EXIST);

$order->firstname = $user->firstname;
$order->lastname = $user->lastname;
$order->email = $user->email;

$fullname = trim((string)$order->firstname . ' ' . (string)$order->lastname);
if ($fullname === '') {
    $fullname = '-';
}

echo $OUTPUT->header();

echo html_writer::tag('h3', 'Invoice #' . (int)$order->id, ['class' => 'mb-3']);

echo html_writer::start_div('card');
echo html_writer::start_div('card-body');

echo html_writer::start_tag('table', ['class' => 'table table-bordered']);
echo html_writer::start_tag('tbody');

echo html_writer::tag('tr', html_writer::tag('th', 'Order ID') . html_writer::tag('td', (string)(int)$order->id));
echo html_writer::tag('tr', html_writer::tag('th', 'Date') . html_writer::tag('td', userdate((int)$order->timecreated)));
echo html_writer::tag('tr', html_writer::tag('th', 'Client') . html_writer::tag('td', s($fullname)));
echo html_writer::tag('tr', html_writer::tag('th', 'Email') . html_writer::tag('td', s((string)($order->email ?? '-'))));
echo html_writer::tag('tr', html_writer::tag('th', 'Course/Product') . html_writer::tag('td', !empty($order->productname) ? format_string($order->productname) : '-'));
echo html_writer::tag('tr', html_writer::tag('th', 'Amount') . html_writer::tag('td', local_elearning_system_format_price((float)$order->amount)));
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

echo html_writer::link(
    new moodle_url('/local/elearning_system/admin/orders.php'),
    'Back to orders',
    ['class' => 'btn btn-secondary me-2']
);

echo html_writer::tag('button', 'Print', [
    'class' => 'btn btn-primary',
    'type' => 'button',
    'onclick' => 'window.print();',
]);

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
