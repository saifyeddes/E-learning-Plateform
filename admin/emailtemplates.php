<?php

require('../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/emailtemplates.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Email Templates');
$PAGE->set_heading('Email Templates');

global $DB;

$definitions = local_elearning_system_get_email_template_definitions();

$errors = [];
$successmessage = '';
$showdrawer = false;
$selectedtemplatekey = '';
$selectedrecipientuserids = [];

/**
 * Human readable template titles.
 */
$templateTitles = [
    'purchase_product' => 'Email après achat produit',
    'purchase_for_child' => 'Email achat pour enfant',
    'inactive_no_purchase_2_months' => 'Relance après 2 mois sans achat',
    'expiration_reminder' => 'Alerte accès bientôt expiré',
    'new_account' => 'Email création compte',
    'invoice' => 'Email facture',
    'renewal_account' => 'Email accès expiré',
    'payment_course' => 'Email paiement cours',
];

/**
 * Prepare student list for manual tests.
 */
$students = [];

$studentrecords = $DB->get_records_sql(
    "SELECT DISTINCT u.id,
            u.username,
            u.firstname,
            u.lastname,
            u.firstnamephonetic,
            u.lastnamephonetic,
            u.middlename,
            u.alternatename,
            u.email,
            u.mailformat,
            u.maildisplay,
            u.maildigest,
            u.lang,
            u.timezone
       FROM {user} u
       JOIN {role_assignments} ra ON ra.userid = u.id
       JOIN {role} r ON r.id = ra.roleid
      WHERE r.shortname = :shortname
        AND u.deleted = 0
        AND u.suspended = 0
        AND u.email <> ''
   ORDER BY u.firstname ASC, u.lastname ASC, u.id ASC",
    ['shortname' => 'student']
);

foreach ($studentrecords as $student) {
    $student = local_elearning_system_prepare_mail_user($student);

    $displayname = trim((string)$student->firstname . ' ' . (string)$student->lastname);
    if ($displayname === '') {
        $displayname = (string)$student->email;
    }

    $students[] = [
        'id' => (int)$student->id,
        'fullname' => s($displayname),
        'email' => s((string)$student->email),
        'label' => s($displayname . ' - ' . $student->email),
        'searchlabel' => s($displayname . ' ' . $student->email),
        'isselectedrecipient' => false,
    ];
}

/**
 * POST actions:
 * - save templates
 * - send test template to selected students
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $submittedtemplates = [];

    foreach ($definitions as $templatekey => $definition) {
        $subject = trim((string)optional_param($templatekey . '_subject', '', PARAM_TEXT));
        $body = trim((string)optional_param($templatekey . '_body', '', PARAM_RAW));

        if ($subject === '') {
            $errors[] = ucfirst(str_replace('_', ' ', $templatekey)) . ' subject is required.';
        }

        if ($body === '') {
            $errors[] = ucfirst(str_replace('_', ' ', $templatekey)) . ' body is required.';
        }

        $submittedtemplates[$templatekey] = [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    if (empty($errors)) {
        foreach ($submittedtemplates as $templatekey => $values) {
            set_config($templatekey . '_subject', $values['subject'], 'local_elearning_system');
            set_config($templatekey . '_body', $values['body'], 'local_elearning_system');
        }

        $successmessage = 'Email templates updated successfully.';
    } else {
        $showdrawer = true;
    }
}

/**
 * Build template data for Mustache.
 */
$templates = [];

foreach ($definitions as $templatekey => $definition) {
    $template = local_elearning_system_get_email_template($templatekey);

    $templates[] = [
        'key' => $templatekey,
        'title' => $templateTitles[$templatekey] ?? ucwords(str_replace('_', ' ', $templatekey)),
        'subject' => s($template['subject']),
        'body' => s($template['body']),
        'isselected' => $selectedtemplatekey === $templatekey,
    ];
}

$placeholdershelp = '{{firstname}}, {{lastname}}, {{fullname}}, {{email}}, {{parentfirstname}}, {{parentlastname}}, {{parentfullname}}, {{childfirstname}}, {{childlastname}}, {{childfullname}}, {{productname}}, {{coursename}}, {{amount}}, {{currency}}, {{durationmonths}}, {{expireslabel}}, {{orderid}}, {{invoiceurl}}, {{loginurl}}, {{sitefullname}}';

$templatedata = [
    'sesskey' => sesskey(),

    'dashboardurl' => (new moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl' => (new moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'parentsurl' => (new moodle_url('/local/elearning_system/admin/parents.php'))->out(false),
    'couponsurl' => (new moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new moodle_url('/local/elearning_system/admin/payement.php'))->out(false),
    'emailtemplatesurl' => (new moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),

    'isdashboard' => false,
    'isproducts' => false,
    'isorders' => false,
    'isparents' => false,
    'iscoupons' => false,
    'ispayement' => false,
    'isemailtemplates' => true,

    'errors' => $errors,
    'haserrors' => !empty($errors),
    'successmessage' => $successmessage,
    'hassuccessmessage' => $successmessage !== '',

    'templates' => $templates,
    'hastemplates' => !empty($templates),


    'formurl' => (new moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),

    'showdrawer' => $showdrawer,
    'selectedtemplatekey' => $selectedtemplatekey,
    'placeholdershelp' => $placeholdershelp,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();