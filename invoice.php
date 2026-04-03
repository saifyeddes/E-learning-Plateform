<?php

require('../../config.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/invoice.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Facture');
$PAGE->set_heading('Facture');

global $DB, $USER;

$orderid = required_param('id', PARAM_INT);

$sql = "SELECT o.id, o.amount, o.timecreated,
               p.name AS productname,
               p.courseid,
               c.fullname AS coursename
          FROM {elearning_orders} o
     LEFT JOIN {elearning_products} p ON p.id = o.productid
     LEFT JOIN {course} c ON c.id = p.courseid
         WHERE o.id = :id AND o.userid = :userid";

$order = $DB->get_record_sql($sql, [
    'id' => $orderid,
    'userid' => (int)$USER->id,
], MUST_EXIST);

echo $OUTPUT->header();

echo html_writer::tag('h3', 'Facture #' . (int)$order->id, ['class' => 'mb-3']);
echo html_writer::start_div('card');
echo html_writer::start_div('card-body');

echo html_writer::start_tag('table', ['class' => 'table table-bordered']);
echo html_writer::start_tag('tbody');
echo html_writer::tag('tr', html_writer::tag('th', 'Commande') . html_writer::tag('td', '#' . (int)$order->id));
echo html_writer::tag('tr', html_writer::tag('th', 'Date') . html_writer::tag('td', userdate((int)$order->timecreated)));
echo html_writer::tag('tr', html_writer::tag('th', 'Produit') . html_writer::tag('td', !empty($order->productname) ? format_string($order->productname) : '-'));
echo html_writer::tag('tr', html_writer::tag('th', 'Cours') . html_writer::tag('td', !empty($order->coursename) ? format_string($order->coursename) : '-'));
echo html_writer::tag('tr', html_writer::tag('th', 'Montant') . html_writer::tag('td', '$' . number_format((float)$order->amount, 2)));
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

echo html_writer::link(
    new moodle_url('/local/elearning_system/my_courses.php'),
    'Retour à mes commandes',
    ['class' => 'btn btn-secondary me-2']
);
echo html_writer::tag('button', 'Imprimer', [
    'class' => 'btn btn-primary',
    'type' => 'button',
    'onclick' => 'window.print();',
]);

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
