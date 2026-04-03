<?php

require('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/invoice.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Invoice');
$PAGE->set_heading('Invoice');

global $DB;

$orderid = optional_param('id', 0, PARAM_INT);
if ($orderid <= 0) {
    throw new moodle_exception('invalidparameter');
}

$sql = "SELECT o.id, o.userid, o.productid, o.amount, o.timecreated,
               u.firstname, u.lastname, u.email,
               p.name AS productname
          FROM {elearning_orders} o
     LEFT JOIN {user} u ON u.id = o.userid
     LEFT JOIN {elearning_products} p ON p.id = o.productid
         WHERE o.id = :id";

$order = $DB->get_record_sql($sql, ['id' => $orderid], MUST_EXIST);

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
echo html_writer::tag('tr', html_writer::tag('th', 'Amount') . html_writer::tag('td', '$' . number_format((float)$order->amount, 2)));

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
