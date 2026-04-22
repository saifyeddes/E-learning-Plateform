<?php

require('../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/emailtemplates.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Email Templates');
$PAGE->set_heading('Email Templates');

global $DB;

$definitions = local_elearning_system_get_email_template_definitions();
$errors = [];
$successmessage = '';
$showdrawer = false;
$selectedtemplatekey = '';
$selectedrecipientuserids = [];

$students = [];
$studentrecords = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email
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
        'fullname' => $displayname,
        'email' => (string)$student->email,
        'label' => $displayname . ' - ' . $student->email,
        'searchlabel' => $displayname . ' ' . $student->email,
        'isselectedrecipient' => in_array((int)$student->id, $selectedrecipientuserids),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = optional_param('action', 'save', PARAM_ALPHA);

    if ($action === 'sendtest') {
        $templatekey = trim((string)optional_param('templatekey', '', PARAM_RAW_TRIMMED));
        $recipientuseridsstr = trim((string)optional_param('recipientuserids', '', PARAM_TEXT));
        
        if (!isset($definitions[$templatekey])) {
            $errors[] = 'Unknown template selected.';
        } else if ($recipientuseridsstr === '') {
            $errors[] = 'Please add at least one student before sending.';
        } else {
            $recipientuserids = array_filter(array_map('intval', explode(',', $recipientuseridsstr)));
            
            if (empty($recipientuserids)) {
                $errors[] = 'Invalid student selection.';
            } else {
                $sentcount = 0;
                $failedemails = [];
                
                foreach ($recipientuserids as $recipientuserid) {
                    $recipient = $DB->get_record('user', ['id' => $recipientuserid, 'deleted' => 0, 'suspended' => 0], '*', IGNORE_MISSING);
                    
                    if (!$recipient) {
                        $failedemails[] = 'Student ID ' . $recipientuserid . ' not found.';
                        continue;
                    }
                    
                    if (empty($recipient->email) || !validate_email((string)$recipient->email)) {
                        $failedemails[] = (isset($recipient->firstname) ? $recipient->firstname . ' ' . $recipient->lastname : 'User ' . $recipientuserid) . ' has no valid email.';
                        continue;
                    }
                    
                    if (local_elearning_system_send_template_preview($recipient, $templatekey)) {
                        $sentcount++;
                    } else {
                        $failedemails[] = (isset($recipient->firstname) ? $recipient->firstname . ' ' . $recipient->lastname : 'User ' . $recipientuserid) . ' - send failed.';
                    }
                }
                
                if ($sentcount > 0) {
                    $message = 'Test email sent to ' . $sentcount . ' student' . ($sentcount > 1 ? 's' : '') . ' for ' . ucwords(str_replace('_', ' ', $templatekey)) . '.';
                    $successmessage = $message;
                    $selectedtemplatekey = $templatekey;
                }
                
                if (!empty($failedemails)) {
                    foreach ($failedemails as $failmsg) {
                        $errors[] = $failmsg;
                    }
                }
                
                if ($sentcount === 0 && !empty($failedemails)) {
                    $successmessage = '';
                }
            }
        }
    } else {
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
}

$templates = [];
foreach ($definitions as $templatekey => $definition) {
    $subject = (string)get_config('local_elearning_system', $templatekey . '_subject');
    $body = (string)get_config('local_elearning_system', $templatekey . '_body');
    if ($subject === '') {
        $subject = $definition['subject'];
    }
    if ($body === '') {
        $body = $definition['body'];
    }

    $templates[] = [
        'key' => $templatekey,
        'title' => ucwords(str_replace('_', ' ', $templatekey)),
        'subject' => $subject,
        'body' => $body,
        'isselected' => $selectedtemplatekey === $templatekey,
    ];
}

foreach ($students as &$student) {
    $student['isselectedrecipient'] = in_array((int)$student['id'], $selectedrecipientuserids);
}
unset($student);

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
    'students' => $students,
    'hasstudents' => !empty($students),
    'selectedrecipientuserids' => implode(',', $selectedrecipientuserids),
    'formurl' => (new moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),
    'showdrawer' => $showdrawer,
    'selectedtemplatekey' => $selectedtemplatekey,
    'placeholdershelp' => '{{firstname}}, {{lastname}}, {{fullname}}, {{email}}, {{parentfirstname}}, {{parentlastname}}, {{parentfullname}}, {{childfirstname}}, {{childlastname}}, {{childfullname}}, {{productname}}, {{coursename}}, {{amount}}, {{currency}}, {{durationmonths}}, {{expireslabel}}, {{orderid}}, {{invoiceurl}}, {{loginurl}}, {{sitefullname}} - Templates: purchase_product, purchase_for_child, new_account, invoice, renewal_account, expiration_reminder, payment_course',
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();