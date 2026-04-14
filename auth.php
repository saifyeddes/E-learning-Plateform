<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/auth.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Connexion');
$PAGE->set_heading('Connexion');
local_elearning_system_force_auth_login_url('/local/elearning_system/auth.php');

global $DB;

$isloggedin = isloggedin() && !isguestuser();

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

$defaultreturn = '/local/elearning_system/checkout.php';
if (empty($SESSION->local_elearning_system_cart)) {
    $defaultreturn = '/local/elearning_system/index.php';
}
$returnurl = optional_param('return', $defaultreturn, PARAM_LOCALURL);
if ($returnurl === '' || $returnurl === '/') {
    $returnurl = $defaultreturn;
}

if ($isloggedin) {
    redirect(new moodle_url($returnurl));
}

$loginerrors = [];
$loginidentifier = '';

if (optional_param('loginsubmit', 0, PARAM_BOOL) && confirm_sesskey()) {
    $loginidentifier = trim(optional_param('login_username', '', PARAM_RAW_TRIMMED));
    $loginpassword = optional_param('login_password', '', PARAM_RAW_TRIMMED);

    if ($loginidentifier === '' || $loginpassword === '') {
        $loginerrors[] = ['message' => get_string('invalidlogin')];
    } else {
        $username = core_text::strtolower($loginidentifier);
        if (strpos($loginidentifier, '@') !== false) {
            $userbyemail = $DB->get_record('user', [
                'email' => core_text::strtolower($loginidentifier),
                'deleted' => 0,
            ], 'id,username');
            if ($userbyemail) {
                $username = $userbyemail->username;
            }
        }

        $failurereason = null;
        $user = authenticate_user_login($username, $loginpassword, false, $failurereason);

        if ($user) {
            complete_user_login($user);
            redirect(new moodle_url($returnurl));
        }

        $loginerrors[] = ['message' => get_string('invalidlogin')];
    }
}

$registererrors = [];
$registerdata = [
    'username' => '',
    'firstname' => '',
    'lastname' => '',
    'email' => '',
    'city' => '',
    'country' => '',
];

if (optional_param('registersubmit', 0, PARAM_BOOL) && confirm_sesskey()) {
    require_once($CFG->dirroot . '/user/lib.php');

    $registerdata['username'] = trim(optional_param('reg_username', '', PARAM_USERNAME));
    $registerdata['firstname'] = trim(optional_param('reg_firstname', '', PARAM_TEXT));
    $registerdata['lastname'] = trim(optional_param('reg_lastname', '', PARAM_TEXT));
    $registerdata['email'] = trim(optional_param('reg_email', '', PARAM_EMAIL));
    $registerdata['city'] = trim(optional_param('reg_city', '', PARAM_TEXT));
    $registerdata['country'] = trim(optional_param('reg_country', '', PARAM_ALPHA));
    $regpassword = optional_param('reg_password', '', PARAM_RAW_TRIMMED);

    if ($registerdata['username'] === '') {
        $registererrors[] = ['message' => get_string('missingusername')];
    }
    if ($registerdata['firstname'] === '') {
        $registererrors[] = ['message' => get_string('missingfirstname')];
    }
    if ($registerdata['lastname'] === '') {
        $registererrors[] = ['message' => get_string('missinglastname')];
    }
    if ($registerdata['email'] === '') {
        $registererrors[] = ['message' => get_string('missingemail')];
    }
    if ($regpassword === '') {
        $registererrors[] = ['message' => get_string('missingpassword')];
    }

    if ($registerdata['username'] !== '' && $DB->record_exists('user', ['username' => $registerdata['username'], 'deleted' => 0])) {
        $registererrors[] = ['message' => get_string('usernameexists')];
    }
    if ($registerdata['email'] !== '' && empty($CFG->allowaccountssameemail) && $DB->record_exists('user', ['email' => $registerdata['email'], 'deleted' => 0])) {
        $registererrors[] = ['message' => get_string('emailexists')];
    }

    if ($regpassword !== '') {
        $errmsg = '';
        if (!check_password_policy($regpassword, $errmsg)) {
            $registererrors[] = ['message' => $errmsg];
        }
    }

    if (empty($registererrors)) {
        $newuser = new stdClass();
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $CFG->mnet_localhost_id;
        $newuser->username = $registerdata['username'];
        $newuser->password = $regpassword;
        $newuser->firstname = $registerdata['firstname'];
        $newuser->lastname = $registerdata['lastname'];
        $newuser->email = $registerdata['email'];
        $newuser->city = $registerdata['city'];
        $newuser->country = $registerdata['country'];
        $newuser->lang = current_language();
        $newuser->timecreated = time();
        $newuser->timemodified = time();

        $newuserid = user_create_user($newuser, true, true);
        complete_user_login(core_user::get_user($newuserid));
        redirect(new moodle_url($returnurl));
    }
}

$countrylist = [];
foreach (get_string_manager()->get_list_of_countries() as $code => $name) {
    $countrylist[] = [
        'code' => $code,
        'name' => $name,
        'selected' => ($registerdata['country'] === $code),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/auth', [
    'isloggedin' => false,
    'cartcount' => local_elearning_system_cart_count($SESSION->local_elearning_system_cart),
    'loginerrors' => $loginerrors,
    'hasloginerrors' => !empty($loginerrors),
    'loginidentifier' => s($loginidentifier),
    'registererrors' => $registererrors,
    'hasregistererrors' => !empty($registererrors),
    'registerusername' => s($registerdata['username']),
    'registerfirstname' => s($registerdata['firstname']),
    'registerlastname' => s($registerdata['lastname']),
    'registeremail' => s($registerdata['email']),
    'registercity' => s($registerdata['city']),
    'countries' => $countrylist,
    'sesskey' => sesskey(),
    'returnurl' => $returnurl,
    'authurl' => (new moodle_url('/local/elearning_system/auth.php'))->out(false),
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
]);
echo $OUTPUT->footer();
