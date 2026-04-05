<?php

require('../../config.php');
require_login();

$orderid = required_param('id', PARAM_INT);
$pdfgenerate = optional_param('pdf', 0, PARAM_INT);

global $DB, $USER, $CFG, $OUTPUT;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/invoice.php', ['id' => $orderid]);

// Fetch order with full details
$orderdata = null;
$product = null;
$course = null;
$user = null;

if ($DB->get_manager()->table_exists('elearning_orders')) {
    $sql = "SELECT o.id, o.userid, o.amount, o.timecreated, o.productid,
                   p.id AS productid, p.name AS productname, p.courseid, p.isbundle, p.bundleitems, p.image,
                   c.fullname AS coursename
              FROM {elearning_orders} o
         LEFT JOIN {elearning_products} p ON p.id = o.productid
         LEFT JOIN {course} c ON c.id = p.courseid
             WHERE o.id = :id";

    $orderdata = $DB->get_record_sql($sql, ['id' => $orderid]);
    
    if (!$orderdata) {
        throw new moodle_exception('ordernotfound', 'local_elearning_system', '', null, 'Order not found');
    }
    
    // Check permissions
    if ((int)$orderdata->userid !== (int)$USER->id && !has_capability('local/elearning_system:manage', $context)) {
        throw new moodle_exception('accessdenied', 'admin');
    }
    
    // Get user info
    $user = $DB->get_record('user', ['id' => (int)$orderdata->userid]);
    
    // Get product if exists
    if (!empty($orderdata->productid)) {
        $product = $DB->get_record('elearning_products', ['id' => (int)$orderdata->productid]);
    }
}

// Get TVA
$tvapercent = get_config('local_elearning_system', 'vat_percent');
if ($tvapercent === false) {
    $tvapercent = 0;
}
$tvapercent = (float)$tvapercent;

$subtotal = (float)$orderdata->amount;
$tax = $subtotal * ($tvapercent / 100);
$total = $subtotal + $tax;

// If PDF requested, generate PDF
if ($pdfgenerate == 1) {
    require_once($CFG->libdir . '/pdflib.php');
    
    $pdf = new pdf();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->AddPage('P', 'A4');
    
    // Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, get_site()->fullname, 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'FACTURE', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Invoice info
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Facture #' . $orderid, 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Date: ' . userdate($orderdata->timecreated, get_string('strftimedaydatetime', 'core_langconfig')), 0, 1);
    $pdf->Ln(3);
    
    // Client info
    if ($user) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 5, 'Client:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, fullname($user), 0, 1);
        $pdf->Cell(0, 5, $user->email, 0, 1);
        $pdf->Ln(5);
    }
    
    // Products table
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(200, 220, 255);
    $pdf->Cell(80, 6, 'Product / Service', 0, 0, 'L', true);
    $pdf->Cell(35, 6, 'Qty', 0, 0, 'C', true);
    $pdf->Cell(40, 6, 'Amount', 0, 1, 'R', true);
    
    $pdf->SetFont('helvetica', '', 10);
    
    if ($product) {
        $productname = format_string($product->name ?? 'Product', true, ['context' => $context]);
        if (!empty($product->courseid) && !empty($orderdata->coursename)) {
            $productname .= ' - ' . format_string($orderdata->coursename, true, ['context' => $context]);
        }
        
        $pdf->MultiCell(80, 6, $productname, 0, 'L');
        $pdf->SetXY(80, $pdf->GetY() - 6);
        $pdf->Cell(35, 6, '1', 0, 0, 'C');
        $pdf->Cell(40, 6, '$' . number_format($subtotal, 2), 0, 1, 'R');
    } else {
        $pdf->Cell(80, 6, 'Product', 0, 0, 'L');
        $pdf->Cell(35, 6, '1', 0, 0, 'C');
        $pdf->Cell(40, 6, '$' . number_format($subtotal, 2), 0, 1, 'R');
    }
    
    $pdf->Ln(3);
    
    // Totals
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(115, 6, 'Subtotal:', 0, 0, 'R');
    $pdf->Cell(40, 6, '$' . number_format($subtotal, 2), 0, 1, 'R');
    
    if ($tvapercent > 0) {
        $pdf->Cell(115, 6, 'TVA (' . number_format($tvapercent, 1) . '%):', 0, 0, 'R');
        $pdf->Cell(40, 6, '$' . number_format($tax, 2), 0, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(115, 6, 'TOTAL:', 0, 0, 'R');
    $pdf->Cell(40, 6, '$' . number_format($total, 2), 0, 1, 'R');
    
    $pdf->Output('facture_' . $orderid . '.pdf', 'D');
    exit;
}

// Otherwise, display HTML
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/invoice.php', ['id' => $orderid]);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Facture #' . $orderid);
$PAGE->set_heading('Facture');

$invoicehtml = [
    'id' => (int)$orderid,
    'timecreated' => userdate((int)$orderdata->timecreated),
    'subtotal' => number_format($subtotal, 2),
    'tvapercent' => number_format($tvapercent, 1),
    'taxamount' => number_format($tax, 2),
    'total' => number_format($total, 2),
    'hastvapercent' => ($tvapercent > 0),
    'backurl' => (new moodle_url('/local/elearning_system/my_courses.php'))->out(false),
    'pdfurl' => (new moodle_url('/local/elearning_system/invoice.php', ['id' => $orderid, 'pdf' => 1]))->out(false),
];

if ($user) {
    $invoicehtml['user'] = [
        'fullname' => fullname($user),
        'email' => $user->email,
    ];
}

if ($product) {
    $productname = format_string($product->name ?? 'Product', true, ['context' => $context]);
    $coursetext = '';
    if (!empty($product->courseid) && !empty($orderdata->coursename)) {
        $coursetext = ' - ' . format_string($orderdata->coursename, true, ['context' => $context]);
    }
    
    $invoicehtml['product'] = [
        'name' => $productname . $coursetext,
        'amount' => number_format($subtotal, 2),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/invoice', $invoicehtml);
echo $OUTPUT->footer();
