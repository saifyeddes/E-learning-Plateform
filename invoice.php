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
local_elearning_system_apply_requested_language();

$lang = local_elearning_system_get_active_language();

$invoicetexts = [
    'fr' => [
        'back' => 'Retour',
        'download_pdf' => 'Télécharger PDF',
        'child_invoice' => 'Facture de votre enfant :',
        'invoice' => 'FACTURE',
        'invoice_number' => 'Facture #',
        'issue_date' => 'Date d’émission',
        'client' => 'Client',
        'product_service' => 'Produit / Service',
        'quantity' => 'Quantité',
        'amount' => 'Montant',
        'subtotal' => 'Sous-total',
        'vat' => 'TVA',
        'total' => 'Total',
        'thank_you' => 'Merci pour votre achat !',
        'contact' => 'Pour toute question, veuillez nous contacter.',
    ],
    'en' => [
        'back' => 'Back',
        'download_pdf' => 'Download PDF',
        'child_invoice' => 'Invoice for your child:',
        'invoice' => 'INVOICE',
        'invoice_number' => 'Invoice #',
        'issue_date' => 'Issue date',
        'client' => 'Client',
        'product_service' => 'Product / Service',
        'quantity' => 'Quantity',
        'amount' => 'Amount',
        'subtotal' => 'Subtotal',
        'vat' => 'VAT',
        'total' => 'Total',
        'thank_you' => 'Thank you for your purchase!',
        'contact' => 'For any question, please contact us.',
    ],
    'ar' => [
        'back' => 'رجوع',
        'download_pdf' => 'تحميل PDF',
        'child_invoice' => 'فاتورة طفلك:',
        'invoice' => 'فاتورة',
        'invoice_number' => 'فاتورة رقم #',
        'issue_date' => 'تاريخ الإصدار',
        'client' => 'العميل',
        'product_service' => 'المنتج / الخدمة',
        'quantity' => 'الكمية',
        'amount' => 'المبلغ',
        'subtotal' => 'المجموع الفرعي',
        'vat' => 'ضريبة القيمة المضافة',
        'total' => 'الإجمالي',
        'thank_you' => 'شكرًا لشرائكم!',
        'contact' => 'لأي سؤال، يرجى التواصل معنا.',
    ],
];

$it = $invoicetexts[$lang] ?? $invoicetexts['fr'];
$isrtl = ($lang === 'ar');

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
    $pdf->SetCreator('Moodle');
    $pdf->SetAuthor(format_string(get_site()->fullname));
    $pdf->SetTitle($it['invoice_number'] . $orderid);
    $pdf->AddPage('P', 'A4');

    // Important pour l’arabe.
    $pdf->SetFont('freeserif', '', 10);

    $dir = $isrtl ? 'rtl' : 'ltr';
    $align = $isrtl ? 'right' : 'left';

    $productname = $product ? format_string($product->name ?? '', true, ['context' => $context]) : '';
    if (!empty($product->courseid) && !empty($orderdata->coursename)) {
        $productname .= ' - ' . format_string($orderdata->coursename, true, ['context' => $context]);
    }

    $html = '
    <div dir="' . $dir . '" style="text-align:' . $align . '; font-family: freeserif;">
        <h1 style="text-align:center;">' . s(format_string(get_site()->fullname)) . '</h1>
        <h2 style="text-align:center;">' . s($it['invoice']) . '</h2>

        <p><strong>' . s($it['invoice_number']) . '</strong>' . s($orderid) . '</p>
        <p><strong>' . s($it['issue_date']) . ':</strong> ' . s(userdate((int)$orderdata->timecreated, '%d/%m/%Y %H:%M')) . '</p>

        <br>';

    if ($user) {
        $html .= '
        <div style="border-top:1px solid #ddd; border-bottom:1px solid #ddd; padding:10px 0;">
            <p><strong>' . s($it['client']) . ':</strong></p>
            <p>' . s(fullname($user)) . '<br>' . s($user->email) . '</p>
        </div>
        <br>';
    }

    $html .= '
        <table border="1" cellpadding="7" cellspacing="0" width="100%">
            <thead>
                <tr style="background-color:#eaf3ff;">
                    <th width="55%"><strong>' . s($it['product_service']) . '</strong></th>
                    <th width="15%" style="text-align:center;"><strong>' . s($it['quantity']) . '</strong></th>
                    <th width="30%" style="text-align:right;"><strong>' . s($it['amount']) . '</strong></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>' . s($productname) . '</td>
                    <td style="text-align:center;">1</td>
                    <td style="text-align:right;">' . s(local_elearning_system_format_price((float)$orderdata->amount)) . '</td>
                </tr>
            </tbody>
        </table>

        <br><br>

        <table cellpadding="5" cellspacing="0" width="45%" align="' . ($isrtl ? 'left' : 'right') . '">
            <tr>
                <td><strong>' . s($it['subtotal']) . ':</strong></td>
                <td style="text-align:right;">' . s(local_elearning_system_format_price((float)$subtotal)) . '</td>
            </tr>';

    if (!empty($tvapercent)) {
        $html .= '
            <tr>
                <td><strong>' . s($it['vat']) . ' (' . s((string)$tvapercent) . '%):</strong></td>
                <td style="text-align:right;">' . s(local_elearning_system_format_price((float)$tax)) . '</td>
            </tr>';
    }

    $html .= '
            <tr>
                <td><strong>' . s($it['total']) . ':</strong></td>
                <td style="text-align:right;"><strong>' . s(local_elearning_system_format_price((float)$total)) . '</strong></td>
            </tr>
        </table>

        <br><br><br><br>

        <div style="text-align:center; color:#666; border-top:1px solid #ddd; padding-top:12px;">
            <p>' . s($it['thank_you']) . '</p>
            <p>' . s($it['contact']) . '</p>
        </div>
    </div>';

    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = 'invoice-' . $orderid . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// Otherwise, display HTML
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/invoice.php', ['id' => $orderid]);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Facture #' . $orderid);
$PAGE->set_heading('Facture');

$pdfurl = (new moodle_url('/local/elearning_system/invoice.php', [
    'id' => $orderid,
    'pdf' => 1,
    'lang' => $lang,
]))->out(false);

$backurl = (new moodle_url('/local/elearning_system/commandes.php', [
    'lang' => $lang,
]))->out(false);

$invoicehtml = [
    'isrtl' => $isrtl,

    't_back' => $it['back'],
    't_download_pdf' => $it['download_pdf'],
    't_child_invoice' => $it['child_invoice'],
    't_invoice' => $it['invoice'],
    't_invoice_number' => $it['invoice_number'],
    't_issue_date' => $it['issue_date'],
    't_client' => $it['client'],
    't_product_service' => $it['product_service'],
    't_quantity' => $it['quantity'],
    't_amount' => $it['amount'],
    't_subtotal' => $it['subtotal'],
    't_vat' => $it['vat'],
    't_total' => $it['total'],
    't_thank_you' => $it['thank_you'],
    't_contact' => $it['contact'],

    'id' => (int)$orderid,
    'timecreated' => userdate((int)$orderdata->timecreated),
    'tvapercent' => number_format($tvapercent, 1),

    'subtotal' => local_elearning_system_format_price((float)$subtotal),
    'taxamount' => local_elearning_system_format_price((float)$tax),
    'total' => local_elearning_system_format_price((float)$total),

    'hastvapercent' => ($tvapercent > 0),
    'isparentaccount' => $isparentaccount,
    'targetfullname' => $targetfullname,

    'pdfurl' => $pdfurl,
    'backurl' => $backurl,
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
