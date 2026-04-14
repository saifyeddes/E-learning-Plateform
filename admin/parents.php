<?php

use moodle_url;

require('../../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once(__DIR__ . '/../lib.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/parents.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Parents');
$PAGE->set_heading('Parents');

global $DB, $USER, $CFG;

$errors = [];
$successmessage = '';
$students = [];
$parentlinks = [];
$page = max(1, optional_param('page', 1, PARAM_INT));
$perpage = 5;

$action = optional_param('action', '', PARAM_ALPHA);
$linkid = optional_param('id', 0, PARAM_INT);

$formaction = 'create';
$editinglinkid = 0;
$formparentfirstname = '';
$formparentlastname = '';
$formparentemail = '';
$formchilduserid = 0;

$childselectsql = "SELECT u.id, u.firstname, u.lastname, u.email
                                         FROM {user} u
                                        WHERE u.deleted = 0
                                            AND u.suspended = 0
                                            AND u.confirmed = 1
                                            AND u.email <> ''
                                            AND u.id <> :currentuserid
                                            AND u.username <> :guestusername";

$childparams = [
        'currentuserid' => (int)$USER->id,
        'guestusername' => 'guest',
];

// Only exclude users marked as parents through this admin interface.
if ($DB->get_manager()->table_exists('elearning_parent_links')) {
        $childselectsql .= " AND u.id NOT IN (
                                                        SELECT DISTINCT l.parentuserid
                                                            FROM {elearning_parent_links} l
                                                         WHERE l.parentuserid IS NOT NULL
                                                )";
}

$childselectsql .= ' ORDER BY u.firstname ASC, u.lastname ASC, u.id ASC';

$studentrecords = $DB->get_records_sql($childselectsql, $childparams);

foreach ($studentrecords as $studentrecord) {
    $fullname = trim((string)$studentrecord->firstname . ' ' . (string)$studentrecord->lastname);
    if ($fullname === '') {
        $fullname = (string)$studentrecord->email;
    }
    $students[] = [
        'id' => (int)$studentrecord->id,
        'fullname' => $fullname,
        'email' => (string)$studentrecord->email,
        'label' => $fullname . ' - ' . $studentrecord->email,
        'isselectedchild' => false,
    ];
}

if ($action === 'deletelink' && $linkid > 0 && confirm_sesskey()) {
    $DB->delete_records('elearning_parent_links', ['id' => $linkid]);
    redirect(new \moodle_url('/local/elearning_system/admin/parents.php'), 'Parent-child link removed successfully.');
}

if ($action === 'edit' && $linkid > 0) {
    $link = $DB->get_record('elearning_parent_links', ['id' => $linkid], '*', IGNORE_MISSING);
    if ($link) {
        $parentuser = $DB->get_record('user', ['id' => (int)$link->parentuserid], 'id,firstname,lastname,email', IGNORE_MISSING);
        if ($parentuser) {
            $formaction = 'update';
            $editinglinkid = (int)$link->id;
            $formparentfirstname = (string)$parentuser->firstname;
            $formparentlastname = (string)$parentuser->lastname;
            $formparentemail = (string)$parentuser->email;
            $formchilduserid = (int)$link->childuserid;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $formaction = optional_param('formaction', 'create', PARAM_ALPHA);
    $editinglinkid = (int)optional_param('linkid', 0, PARAM_INT);

    $formparentfirstname = trim((string)optional_param('parentfirstname', '', PARAM_TEXT));
    $formparentlastname = trim((string)optional_param('parentlastname', '', PARAM_TEXT));
    $formparentemail = core_text::strtolower(trim((string)optional_param('parentemail', '', PARAM_RAW_TRIMMED)));
    $parentpassword = (string)optional_param('parentpassword', '', PARAM_RAW);
    $formchilduserid = (int)optional_param('childuserid', 0, PARAM_INT);

    if ($formparentfirstname === '') {
        $errors[] = 'Parent first name is required.';
    }
    if ($formparentlastname === '') {
        $errors[] = 'Parent last name is required.';
    }
    if ($formparentemail === '' || !validate_email($formparentemail)) {
        $errors[] = 'A valid parent email is required.';
    }
    if ($formchilduserid <= 0) {
        $errors[] = 'Please choose a child to link.';
    }

    $childuser = null;
    if ($formchilduserid > 0) {
        $childuser = $DB->get_record('user', ['id' => $formchilduserid, 'deleted' => 0, 'suspended' => 0], '*', IGNORE_MISSING);
        if (!$childuser) {
            $errors[] = 'The selected child account could not be found.';
        }
    }

    if ($formaction === 'create') {
        if ($parentpassword === '') {
            $errors[] = 'Parent password is required.';
        } else {
            $passworderror = '';
            if (!check_password_policy($parentpassword, $passworderror)) {
                $errors[] = $passworderror;
            }
        }

        if (empty($errors)) {
            if ($DB->record_exists('user', ['email' => $formparentemail, 'deleted' => 0])) {
                $errors[] = 'A user with this email already exists. Use another email.';
            } else {
                $parentuser = new stdClass();
                $parentuser->auth = 'manual';
                $parentuser->confirmed = 1;
                $parentuser->mnethostid = $CFG->mnet_localhost_id;
                $parentuser->username = $formparentemail;
                $parentuser->password = $parentpassword;
                $parentuser->firstname = $formparentfirstname;
                $parentuser->lastname = $formparentlastname;
                $parentuser->email = $formparentemail;
                $parentuser->lang = current_language();
                $parentuser->timecreated = time();
                $parentuser->timemodified = time();

                $parentuserid = user_create_user($parentuser, true, true);

                if ($parentuserid && $childuser) {
                    if (!$DB->record_exists('elearning_parent_links', [
                        'parentuserid' => $parentuserid,
                        'childuserid' => (int)$childuser->id,
                    ])) {
                        $link = new stdClass();
                        $link->parentuserid = $parentuserid;
                        $link->childuserid = (int)$childuser->id;
                        $link->createdby = (int)$USER->id;
                        $link->timecreated = time();
                        $link->timemodified = time();
                        $DB->insert_record('elearning_parent_links', $link);
                    }
                }

                redirect(new \moodle_url('/local/elearning_system/admin/parents.php'), 'Parent account created and linked successfully.');
            }
        }
    } else if ($formaction === 'update') {
        $link = $DB->get_record('elearning_parent_links', ['id' => $editinglinkid], '*', IGNORE_MISSING);
        if (!$link) {
            $errors[] = 'The parent-child link could not be found.';
        }

        $parentuser = null;
        if ($link) {
            $parentuser = $DB->get_record('user', ['id' => (int)$link->parentuserid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$parentuser) {
                $errors[] = 'The parent account could not be found.';
            }
        }

        if ($parentpassword !== '') {
            $passworderror = '';
            if (!check_password_policy($parentpassword, $passworderror)) {
                $errors[] = $passworderror;
            }
        }

        if (empty($errors) && $parentuser) {
            $emailexists = $DB->record_exists_select(
                'user',
                'email = :email AND deleted = 0 AND id <> :id',
                ['email' => $formparentemail, 'id' => (int)$parentuser->id]
            );
            if ($emailexists) {
                $errors[] = 'Another user already uses this email.';
            }
        }

        if (empty($errors) && $parentuser && $link) {
            $parentupdate = new stdClass();
            $parentupdate->id = (int)$parentuser->id;
            $parentupdate->firstname = $formparentfirstname;
            $parentupdate->lastname = $formparentlastname;
            $parentupdate->email = $formparentemail;
            $parentupdate->username = $formparentemail;
            $parentupdate->timemodified = time();
            $DB->update_record('user', $parentupdate);

            if ($parentpassword !== '') {
                update_internal_user_password($parentupdate, $parentpassword);
            }

            $linkupdate = new stdClass();
            $linkupdate->id = (int)$link->id;
            $linkupdate->childuserid = (int)$formchilduserid;
            $linkupdate->timemodified = time();
            $DB->update_record('elearning_parent_links', $linkupdate);

            redirect(new \moodle_url('/local/elearning_system/admin/parents.php'), 'Parent link updated successfully.');
        }
    }
}

foreach ($students as &$student) {
    $student['isselectedchild'] = ((int)$student['id'] === (int)$formchilduserid);
}
unset($student);

if ($DB->get_manager()->table_exists('elearning_parent_links')) {
    $sql = "SELECT l.id, l.parentuserid, l.childuserid, l.createdby, l.timecreated,
                   pu.firstname AS parentfirstname, pu.lastname AS parentlastname, pu.email AS parentemail,
                   cu.firstname AS childfirstname, cu.lastname AS childlastname, cu.email AS childemail,
                   au.firstname AS adminfirstname, au.lastname AS adminlastname
              FROM {elearning_parent_links} l
         LEFT JOIN {user} pu ON pu.id = l.parentuserid
         LEFT JOIN {user} cu ON cu.id = l.childuserid
         LEFT JOIN {user} au ON au.id = l.createdby
          ORDER BY l.id DESC";

    $records = $DB->get_records_sql($sql);
    foreach ($records as $record) {
        $parentfullname = trim((string)$record->parentfirstname . ' ' . (string)$record->parentlastname);
        if ($parentfullname === '') {
            $parentfullname = '-';
        }

        $childfullname = trim((string)$record->childfirstname . ' ' . (string)$record->childlastname);
        if ($childfullname === '') {
            $childfullname = '-';
        }

        $adminfullname = trim((string)($record->adminfirstname ?? '') . ' ' . (string)($record->adminlastname ?? ''));
        if ($adminfullname === '') {
            $adminfullname = '-';
        }

        $parentlinks[] = [
            'id' => (int)$record->id,
            'parentfullname' => format_string($parentfullname),
            'parentemail' => s((string)($record->parentemail ?? '-')),
            'childfullname' => format_string($childfullname),
            'childemail' => s((string)($record->childemail ?? '-')),
            'adminfullname' => format_string($adminfullname),
            'timecreated' => userdate((int)$record->timecreated),
            'editurl' => (new \moodle_url('/local/elearning_system/admin/parents.php', [
                'action' => 'edit',
                'id' => (int)$record->id,
            ]))->out(false),
            'deleteurl' => (new \moodle_url('/local/elearning_system/admin/parents.php', [
                'action' => 'deletelink',
                'id' => (int)$record->id,
                'sesskey' => sesskey(),
            ]))->out(false),
        ];
    }
}

$totalparentlinks = count($parentlinks);
$totalpages = max(1, (int)ceil($totalparentlinks / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
}
$offset = ($page - 1) * $perpage;
$parentlinks = array_slice($parentlinks, $offset, $perpage);

$pageitems = [];
if ($totalpages > 1) {
    $pageitems[] = [
        'label' => 'Precedent',
        'url' => $page > 1 ? (new \moodle_url('/local/elearning_system/admin/parents.php', ['page' => $page - 1]))->out(false) : null,
        'disabled' => $page <= 1,
        'isnav' => true,
    ];

    $windowstart = max(1, $page - 1);
    $windowend = min($totalpages, $page + 1);
    $ellipsis = false;
    for ($i = 1; $i <= $totalpages; $i++) {
        $showpage = ($i === 1) || ($i === $totalpages) || ($i >= $windowstart && $i <= $windowend);
        if (!$showpage) {
            if (!$ellipsis) {
                $pageitems[] = ['isellipsis' => true];
                $ellipsis = true;
            }
            continue;
        }

        $ellipsis = false;
        $pageitems[] = [
            'ispage' => true,
            'label' => (string)$i,
            'url' => (new \moodle_url('/local/elearning_system/admin/parents.php', ['page' => $i]))->out(false),
            'active' => $i === $page,
        ];
    }

    $pageitems[] = [
        'label' => 'Suivante',
        'url' => $page < $totalpages ? (new \moodle_url('/local/elearning_system/admin/parents.php', ['page' => $page + 1]))->out(false) : null,
        'disabled' => $page >= $totalpages,
        'isnav' => true,
    ];
}

$templatedata = [
    'dashboardurl' => (new \moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl' => (new \moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new \moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'parentsurl' => (new \moodle_url('/local/elearning_system/admin/parents.php'))->out(false),
    'couponsurl' => (new \moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new \moodle_url('/local/elearning_system/admin/payement.php'))->out(false),
    'emailtemplatesurl' => (new \moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),

    'isdashboard' => false,
    'isproducts' => false,
    'isorders' => false,
    'isparents' => true,
    'iscoupons' => false,
    'ispayement' => false,
    'isemailtemplates' => false,

    'errors' => $errors,
    'haserrors' => !empty($errors),
    'successmessage' => $successmessage,
    'hassuccessmessage' => $successmessage !== '',
    'sesskey' => sesskey(),
    'isediting' => $formaction === 'update',
    'formaction' => $formaction,
    'editinglinkid' => $editinglinkid,
    'formparentfirstname' => s($formparentfirstname),
    'formparentlastname' => s($formparentlastname),
    'formparentemail' => s($formparentemail),
    'students' => $students,
    'hasstudents' => !empty($students),
    'parentlinks' => $parentlinks,
    'hasparentlinks' => !empty($parentlinks),
    'pageitems' => $pageitems,
    'haspagination' => ($totalpages > 1),
    'formurl' => (new \moodle_url('/local/elearning_system/admin/parents.php'))->out(false),
    'parentshelp' => 'Create a manual Moodle account for the parent using the email and password entered here, then link the account to one child.'
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();