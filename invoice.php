<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');
require_login();

$orderid = required_param('id', PARAM_INT);
$pdfgenerate = optional_param('pdf', 0, PARAM_INT);

global $DB, $USER, $CFG, $OUTPUT;

function local_elearning_system_client_invoice_get_order(int $orderid): ?stdClass {
    $db = \local_elearning_system\plugin_db::get();

    $stmt = $db->prepare("
        SELECT o.id, o.userid, o.amount, o.timecreated, o.productid,
               o.expiresat, o.durationmonths,
               p.id AS productid,
               p.name AS productname,
               p.courseid,
               p.isbundle,
               p.bundleitems,
               p.image
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

$usercontext = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
$isparentaccount = !empty($usercontext['isparentaccount']);
$childids = !empty($usercontext['childids']) && is_array($usercontext['childids']) ? $usercontext['childids'] : [];
$targetfullname = trim((string)($usercontext['targetfullname'] ?? ''));

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/invoice.php', ['id' => $orderid]);

// Fetch order with full details
$orderdata = null;
$product = null;
$course = null;
$user = null;

$orderdata = local_elearning_system_client_invoice_get_order($orderid);

if (!$orderdata) {
    throw new moodle_exception('ordernotfound', 'local_elearning_system', '', null, 'Order not found');
}

// Check permissions.
$canvieworder = ((int)$orderdata->userid === (int)$USER->id);

if (!$canvieworder && $isparentaccount) {
    $canvieworder = in_array((int)$orderdata->userid, array_map('intval', $childids), true);
}

if (!$canvieworder && !has_capability('local/elearning_system:manage', $context)) {
    throw new moodle_exception('accessdenied', 'admin');
}

// Get user info from Moodle DB.
$user = $DB->get_record('user', ['id' => (int)$orderdata->userid], '*', IGNORE_MISSING);

// Create product object from plugin DB data.
if (!empty($orderdata->productid)) {
    $product = (object)[
        'id' => (int)$orderdata->productid,
        'name' => (string)($orderdata->productname ?? ''),
        'courseid' => (int)($orderdata->courseid ?? 0),
        'isbundle' => (int)($orderdata->isbundle ?? 0),
        'bundleitems' => (string)($orderdata->bundleitems ?? ''),
        'image' => (string)($orderdata->image ?? ''),
    ];
}

// Get course name from Moodle DB.
$orderdata->coursename = '';

if (!empty($orderdata->courseid)) {
    $course = $DB->get_record('course', ['id' => (int)$orderdata->courseid], 'id,fullname', IGNORE_MISSING);
    if ($course) {
        $orderdata->coursename = $course->fullname;
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
        $pdf->Cell(40, 6, local_elearning_system_format_price($subtotal), 0, 1, 'R');
    } else {
        $pdf->Cell(80, 6, 'Product', 0, 0, 'L');
        $pdf->Cell(35, 6, '1', 0, 0, 'C');
        $pdf->Cell(40, 6, local_elearning_system_format_price($subtotal), 0, 1, 'R');
    }
    
    $pdf->Ln(3);
    
    // Totals
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(115, 6, 'Subtotal:', 0, 0, 'R');
    $pdf->Cell(40, 6, local_elearning_system_format_price($subtotal), 0, 1, 'R');
    
    if ($tvapercent > 0) {
        $pdf->Cell(115, 6, 'TVA (' . number_format($tvapercent, 1) . '%):', 0, 0, 'R');
        $pdf->Cell(40, 6, local_elearning_system_format_price($tax), 0, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(115, 6, 'TOTAL:', 0, 0, 'R');
    $pdf->Cell(40, 6, local_elearning_system_format_price($total), 0, 1, 'R');
    
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
    'tvapercent' => number_format($tvapercent, 1),
    'subtotal' => local_elearning_system_format_price($subtotal),
'taxamount' => local_elearning_system_format_price($tax),
'total' => local_elearning_system_format_price($total),

    'hastvapercent' => ($tvapercent > 0),
    'isparentaccount' => $isparentaccount,
    'targetfullname' => $targetfullname,
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
        'amount' => local_elearning_system_format_price($subtotal),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/invoice', $invoicehtml);
echo $OUTPUT->footer();
