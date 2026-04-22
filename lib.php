<?php

defined('MOODLE_INTERNAL') || die();

function local_elearning_system_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    if ($filearea !== 'productimage') {
        return false;
    }

    $fs = get_file_storage();
    $filename = array_pop($args);

    $filepath = '/';

    $file = $fs->get_file($context->id, 'local_elearning_system', $filearea, 0, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

function local_elearning_system_extends_navigation(global_navigation $navigation) {
    // Show Store only for site admins; regular users only see Accueil.
    if (!isloggedin() || isguestuser() || !is_siteadmin()) {
        return;
    }

    $navigation->add(
        'Store',
        new moodle_url('/local/elearning_system/store.php'),
        global_navigation::TYPE_CUSTOM,
        'store'
    );
}

/**
 * Normalize cart structure to ['qty' => int, 'durationmonths' => int].
 *
 * @param array $cart
 * @return void
 */
function local_elearning_system_normalise_cart_structure(array &$cart): void {
    foreach ($cart as $productid => $value) {
        if (is_array($value)) {
            $qty = max(1, (int)($value['qty'] ?? 1));
            $months = max(1, min(24, (int)($value['durationmonths'] ?? 1)));
            $cart[$productid] = [
                'qty' => $qty,
                'durationmonths' => $months,
            ];
            continue;
        }

        $qty = max(1, (int)$value);
        $cart[$productid] = [
            'qty' => $qty,
            'durationmonths' => 1,
        ];
    }
}

/**
 * Get a normalized cart item.
 *
 * @param array $cart
 * @param int $productid
 * @return array{qty:int,durationmonths:int}
 */
function local_elearning_system_get_cart_item(array $cart, int $productid): array {
    $raw = $cart[$productid] ?? ['qty' => 1, 'durationmonths' => 1];
    if (!is_array($raw)) {
        $raw = ['qty' => (int)$raw, 'durationmonths' => 1];
    }

    return [
        'qty' => max(1, (int)($raw['qty'] ?? 1)),
        'durationmonths' => max(1, min(24, (int)($raw['durationmonths'] ?? 1))),
    ];
}

/**
 * Sum all cart quantities.
 *
 * @param array $cart
 * @return int
 */
function local_elearning_system_cart_count(array $cart): int {
    $count = 0;
    foreach ($cart as $value) {
        if (is_array($value)) {
            $count += max(1, (int)($value['qty'] ?? 1));
        } else {
            $count += max(1, (int)$value);
        }
    }
    return $count;
}

/**
 * Return linked child user ids for a parent account.
 *
 * @param int $parentuserid
 * @param moodle_database $DB
 * @return int[]
 */
function local_elearning_system_get_parent_child_ids(int $parentuserid, moodle_database $DB): array {
    if ($parentuserid <= 0 || !$DB->get_manager()->table_exists('elearning_parent_links')) {
        return [];
    }

    $links = $DB->get_records('elearning_parent_links', ['parentuserid' => $parentuserid], 'id ASC', 'id,childuserid');
    if (empty($links)) {
        return [];
    }

    $childids = [];
    foreach ($links as $link) {
        $childid = (int)($link->childuserid ?? 0);
        if ($childid > 0) {
            $childids[$childid] = $childid;
        }
    }

    return array_values($childids);
}

/**
 * Resolve effective user context for parent/child linked accounts.
 *
 * If the current user is linked as a parent, this returns the first active child as target user.
 * Otherwise, target user is the current user.
 *
 * @param int $currentuserid
 * @param moodle_database $DB
 * @return array<string,mixed>
 */
function local_elearning_system_get_effective_user_context(int $currentuserid, moodle_database $DB): array {
    $result = [
        'isparentaccount' => false,
        'currentuserid' => $currentuserid,
        'targetuserid' => $currentuserid,
        'childids' => [],
        'targetfullname' => '',
        'targetemail' => '',
    ];

    if ($currentuserid <= 0) {
        return $result;
    }

    $childids = local_elearning_system_get_parent_child_ids($currentuserid, $DB);
    if (empty($childids)) {
        $self = core_user::get_user($currentuserid, 'id,firstname,lastname,email', IGNORE_MISSING);
        if ($self) {
            $result['targetfullname'] = trim((string)$self->firstname . ' ' . (string)$self->lastname);
            $result['targetemail'] = (string)($self->email ?? '');
        }
        return $result;
    }

    [$insql, $params] = $DB->get_in_or_equal($childids, SQL_PARAMS_NAMED);
    $childrecords = $DB->get_records_select(
        'user',
        'id ' . $insql . ' AND deleted = 0 AND suspended = 0',
        $params,
        'id ASC',
        'id,firstname,lastname,email',
        0,
        1
    );
    $child = !empty($childrecords) ? reset($childrecords) : null;

    if (!$child) {
        $self = core_user::get_user($currentuserid, 'id,firstname,lastname,email', IGNORE_MISSING);
        if ($self) {
            $result['targetfullname'] = trim((string)$self->firstname . ' ' . (string)$self->lastname);
            $result['targetemail'] = (string)($self->email ?? '');
        }
        return $result;
    }

    $result['isparentaccount'] = true;
    $result['targetuserid'] = (int)$child->id;
    $result['childids'] = $childids;
    $result['targetfullname'] = trim((string)$child->firstname . ' ' . (string)$child->lastname);
    $result['targetemail'] = (string)($child->email ?? '');

    return $result;
}

/**
 * Calculate expiration timestamp from purchase time and months.
 *
 * @param int $purchasetime
 * @param int $months
 * @return int
 */
function local_elearning_system_calculate_expiration(int $purchasetime, int $months): int {
    $months = max(1, min(24, $months));
    $date = new DateTime('@' . $purchasetime);
    $date->setTimezone(core_date::get_server_timezone_object());
    $date->modify('+' . $months . ' months');
    return (int)$date->getTimestamp();
}

/**
 * Return the selected site currency code.
 *
 * @return string
 */
function local_elearning_system_get_site_currency_code(): string {
    $code = core_text::strtoupper(trim((string)get_config('local_elearning_system', 'stripe_currency')));
    if ($code === '') {
        $code = 'USD';
    }

    return $code;
}

/**
 * Return built-in email template definitions.
 *
 * @return array<string, array{subject:string,body:string}>
 */
function local_elearning_system_get_email_template_definitions(): array {
    return [
        'purchase_product' => [
            'subject' => 'Purchase confirmed - {{productname}}',
            'body' => "Hello {{firstname}},\n\nYour purchase for {{productname}} has been confirmed.\nDuration: {{durationmonths}} months\nExpiry date: {{expireslabel}}\nAmount: {{currency}} {{amount}}\nInvoice: {{invoiceurl}}\n\n{{sitefullname}}",
        ],
        'purchase_for_child' => [
            'subject' => 'Purchase confirmed for your child - {{productname}}',
            'body' => "Hello {{parentfirstname}},\n\nYou purchased {{productname}} for your child {{childfullname}}.\nDuration: {{durationmonths}} months\nExpiry date: {{expireslabel}}\nAmount: {{currency}} {{amount}}\nInvoice: {{invoiceurl}}\n\n{{sitefullname}}",
        ],
        'new_account' => [
            'subject' => 'Welcome to {{sitefullname}}',
            'body' => "Hello {{firstname}},\n\nYour account has been created successfully.\nLogin: {{loginurl}}\n\n{{sitefullname}}",
        ],
        'invoice' => [
            'subject' => 'Invoice #{{orderid}} - {{productname}}',
            'body' => "Hello {{firstname}},\n\nPlease find your invoice for {{productname}}.\nInvoice number: {{orderid}}\nAmount: {{currency}} {{amount}}\nInvoice link: {{invoiceurl}}\n\n{{sitefullname}}",
        ],
        'renewal_account' => [
            'subject' => 'Your access has expired - {{productname}}',
            'body' => "Hello {{firstname}},\n\nYour access to {{productname}} has expired on {{expireslabel}}.\nTo continue, please renew your account.\n\n{{sitefullname}}",
        ],
        'expiration_reminder' => [
            'subject' => 'Your course access expires soon - {{productname}}',
            'body' => "Hello {{firstname}},\n\nYour access to {{productname}} will expire in 5 days.\nExpiry date: {{expireslabel}}\n\nRenew now to keep your access: {{loginurl}}\n\n{{sitefullname}}",
        ],
        'payment_course' => [
            'subject' => 'Payment received - {{productname}}',
            'body' => "Hello {{firstname}},\n\nWe received your payment for {{productname}}.\nAmount: {{currency}} {{amount}}\nDuration: {{durationmonths}} months\nExpiry date: {{expireslabel}}\n\n{{sitefullname}}",
        ],
    ];
}

/**
 * Render a template string using moustache-like placeholders.
 *
 * @param string $template
 * @param array<string, string> $variables
 * @return string
 */
function local_elearning_system_render_template_string(string $template, array $variables): string {
    $replacements = [];
    foreach ($variables as $key => $value) {
        $replacements['{{' . $key . '}}'] = (string)$value;
    }

    return strtr($template, $replacements);
}

/**
 * Load the configured or default email template.
 *
 * @param string $templatekey
 * @return array{subject:string,body:string}
 */
function local_elearning_system_get_email_template(string $templatekey): array {
    $definitions = local_elearning_system_get_email_template_definitions();
    if (!isset($definitions[$templatekey])) {
        return ['subject' => '', 'body' => ''];
    }

    $subject = trim((string)get_config('local_elearning_system', $templatekey . '_subject'));
    $body = trim((string)get_config('local_elearning_system', $templatekey . '_body'));

    if ($subject === '') {
        $subject = $definitions[$templatekey]['subject'];
    }
    if ($body === '') {
        $body = $definitions[$templatekey]['body'];
    }

    return ['subject' => $subject, 'body' => $body];
}

/**
 * Ensure a user object has the minimum fields required by email_to_user().
 *
 * @param stdClass $user
 * @return stdClass
 */
function local_elearning_system_prepare_mail_user(stdClass $user): stdClass {
    if (empty($user->username)) {
        $user->username = 'user' . (int)($user->id ?? 0);
    }
    if (!isset($user->firstname)) {
        $user->firstname = '';
    }
    if (!isset($user->lastname)) {
        $user->lastname = '';
    }
    if (!isset($user->firstnamephonetic)) {
        $user->firstnamephonetic = '';
    }
    if (!isset($user->lastnamephonetic)) {
        $user->lastnamephonetic = '';
    }
    if (!isset($user->middlename)) {
        $user->middlename = '';
    }
    if (!isset($user->alternatename)) {
        $user->alternatename = '';
    }
    if (!isset($user->mailformat)) {
        $user->mailformat = 1;
    }
    if (!isset($user->maildisplay)) {
        $user->maildisplay = 1;
    }
    if (!isset($user->maildigest)) {
        $user->maildigest = 0;
    }
    if (!isset($user->lang)) {
        $user->lang = current_language();
    }
    if (!isset($user->timezone)) {
        $user->timezone = '99';
    }

    return $user;
}

/**
 * Build a valid sender for email_to_user().
 *
 * @param stdClass $recipient
 * @return stdClass
 */
function local_elearning_system_get_valid_from_user(stdClass $recipient): stdClass {
    $admin = get_admin();
    if ($admin && !empty($admin->email) && validate_email((string)$admin->email)) {
        return local_elearning_system_prepare_mail_user($admin);
    }

    $support = core_user::get_support_user();
    if ($support && !empty($support->email) && validate_email((string)$support->email)) {
        return local_elearning_system_prepare_mail_user($support);
    }

    $recipientdomain = 'example.com';
    if (!empty($recipient->email) && strpos((string)$recipient->email, '@') !== false) {
        $parts = explode('@', (string)$recipient->email);
        $domain = core_text::strtolower((string)end($parts));
        if ($domain !== '') {
            $recipientdomain = $domain;
        }
    }

    $fallback = new stdClass();
    $fallback->id = 0;
    $fallback->username = 'local_elearning_system_notifier';
    $fallback->firstname = 'E-learning';
    $fallback->lastname = 'Notifier';
    $fallback->email = 'no-reply@' . $recipientdomain;
    $fallback->mailformat = 1;
    $fallback->maildisplay = 1;
    $fallback->maildigest = 0;
    $fallback->lang = !empty($recipient->lang) ? $recipient->lang : current_language();
    $fallback->timezone = !empty($recipient->timezone) ? $recipient->timezone : '99';

    return local_elearning_system_prepare_mail_user($fallback);
}

/**
 * Check if notification log table exists.
 *
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_has_notification_log_table(moodle_database $DB): bool {
    return $DB->get_manager()->table_exists('elearning_notification_log');
}

/**
 * Check if a specific notification type was already sent for an order.
 *
 * @param int $orderid
 * @param string $notificationtype
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_notification_already_sent(int $orderid, string $notificationtype, moodle_database $DB): bool {
    if (!local_elearning_system_has_notification_log_table($DB)) {
        return false;
    }

    return $DB->record_exists('elearning_notification_log', [
        'orderid' => $orderid,
        'notificationtype' => $notificationtype,
    ]);
}

/**
 * Store notification log after successful send.
 *
 * @param int $orderid
 * @param int $userid
 * @param string $notificationtype
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_mark_notification_sent(int $orderid, int $userid, string $notificationtype, moodle_database $DB): void {
    if (!local_elearning_system_has_notification_log_table($DB)) {
        return;
    }

    if (local_elearning_system_notification_already_sent($orderid, $notificationtype, $DB)) {
        return;
    }

    $log = new stdClass();
    $log->orderid = $orderid;
    $log->userid = $userid;
    $log->notificationtype = $notificationtype;
    $log->timecreated = time();
    $DB->insert_record('elearning_notification_log', $log);
}

/**
 * Send a configured email notification for an order event.
 *
 * @param stdClass $order
 * @param string $templatekey
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_send_order_email_with_template(stdClass $order, string $templatekey, moodle_database $DB): bool {
    $user = core_user::get_user((int)$order->userid, '*', IGNORE_MISSING);
    if (!$user || empty($user->email) || !validate_email((string)$user->email)) {
        return false;
    }
    $user = local_elearning_system_prepare_mail_user($user);

    $template = local_elearning_system_get_email_template($templatekey);
    if ($template['subject'] === '' || $template['body'] === '') {
        return false;
    }

    $productname = 'Produit';
    if (!empty($order->productid)) {
        $product = $DB->get_record('elearning_products', ['id' => (int)$order->productid], 'id,name', IGNORE_MISSING);
        if ($product && !empty($product->name)) {
            $productname = format_string($product->name);
        }
    }

    $months = max(1, (int)($order->durationmonths ?? 1));
    $expiresat = (int)($order->expiresat ?? local_elearning_system_get_order_expiresat($order));
    $expireslabel = userdate($expiresat);
    $amount = number_format((float)($order->amount ?? 0), 2);
    $sitefullname = format_string(get_site()->fullname);
    $invoiceurl = (new moodle_url('/local/elearning_system/invoice.php', ['id' => (int)($order->id ?? 0), 'pdf' => 1]))->out(false);
    $loginurl = (new moodle_url('/login/index.php'))->out(false);

    $variables = [
        'firstname' => (string)$user->firstname,
        'lastname' => (string)$user->lastname,
        'fullname' => fullname($user),
        'email' => (string)$user->email,
        'productname' => $productname,
        'coursename' => $productname,
        'amount' => $amount,
        'currency' => local_elearning_system_get_site_currency_code(),
        'durationmonths' => (string)$months,
        'expireslabel' => $expireslabel,
        'orderid' => (string)(int)($order->id ?? 0),
        'invoiceurl' => $invoiceurl,
        'loginurl' => $loginurl,
        'sitefullname' => $sitefullname,
    ];

    $subject = local_elearning_system_render_template_string($template['subject'], $variables);
    $body = local_elearning_system_render_template_string($template['body'], $variables);
    $messagehtml = nl2br(s($body));

    $fromuser = local_elearning_system_get_valid_from_user($user);
    return (bool)email_to_user($user, $fromuser, $subject, $body, $messagehtml);
}

/**
 * Send a parent-specific purchase confirmation for child purchases.
 *
 * @param stdClass $order
 * @param int $parentuserid
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_send_parent_purchase_email(stdClass $order, int $parentuserid, moodle_database $DB): bool {
    if ($parentuserid <= 0) {
        return false;
    }

    $parent = core_user::get_user($parentuserid, '*', IGNORE_MISSING);
    $child = core_user::get_user((int)$order->userid, '*', IGNORE_MISSING);
    if (!$parent || empty($parent->email) || !validate_email((string)$parent->email) || !$child) {
        return false;
    }

    $parent = local_elearning_system_prepare_mail_user($parent);
    $child = local_elearning_system_prepare_mail_user($child);

    $template = local_elearning_system_get_email_template('purchase_for_child');
    if ($template['subject'] === '' || $template['body'] === '') {
        return false;
    }

    $productname = 'Produit';
    if (!empty($order->productid)) {
        $product = $DB->get_record('elearning_products', ['id' => (int)$order->productid], 'id,name', IGNORE_MISSING);
        if ($product && !empty($product->name)) {
            $productname = format_string($product->name);
        }
    }

    $months = max(1, (int)($order->durationmonths ?? 1));
    $expiresat = (int)($order->expiresat ?? local_elearning_system_get_order_expiresat($order));
    $expireslabel = userdate($expiresat);
    $amount = number_format((float)($order->amount ?? 0), 2);
    $sitefullname = format_string(get_site()->fullname);
    $invoiceurl = (new moodle_url('/local/elearning_system/invoice.php', ['id' => (int)($order->id ?? 0), 'pdf' => 1]))->out(false);
    $loginurl = (new moodle_url('/login/index.php'))->out(false);

    $variables = [
        'firstname' => (string)$parent->firstname,
        'lastname' => (string)$parent->lastname,
        'fullname' => fullname($parent),
        'email' => (string)$parent->email,
        'parentfirstname' => (string)$parent->firstname,
        'parentlastname' => (string)$parent->lastname,
        'parentfullname' => fullname($parent),
        'childfirstname' => (string)$child->firstname,
        'childlastname' => (string)$child->lastname,
        'childfullname' => fullname($child),
        'productname' => $productname,
        'coursename' => $productname,
        'amount' => $amount,
        'currency' => local_elearning_system_get_site_currency_code(),
        'durationmonths' => (string)$months,
        'expireslabel' => $expireslabel,
        'orderid' => (string)(int)($order->id ?? 0),
        'invoiceurl' => $invoiceurl,
        'loginurl' => $loginurl,
        'sitefullname' => $sitefullname,
    ];

    $subject = local_elearning_system_render_template_string($template['subject'], $variables);
    $body = local_elearning_system_render_template_string($template['body'], $variables);
    $messagehtml = nl2br(s($body));

    $fromuser = local_elearning_system_get_valid_from_user($parent);
    return (bool)email_to_user($parent, $fromuser, $subject, $body, $messagehtml);
}

/**
 * Send a preview email for a configured template to a recipient.
 *
 * @param stdClass $recipient
 * @param string $templatekey
 * @return bool
 */
function local_elearning_system_send_template_preview(stdClass $recipient, string $templatekey): bool {
    if (empty($recipient->email) || !validate_email((string)$recipient->email)) {
        return false;
    }

    $recipient = local_elearning_system_prepare_mail_user($recipient);
    $template = local_elearning_system_get_email_template($templatekey);
    if ($template['subject'] === '' || $template['body'] === '') {
        return false;
    }

    $variables = [
        'firstname' => (string)($recipient->firstname ?? ''),
        'lastname' => (string)($recipient->lastname ?? ''),
        'fullname' => fullname($recipient),
        'email' => (string)$recipient->email,
        'productname' => 'Sample product',
        'coursename' => 'Sample course',
        'amount' => '0.00',
        'currency' => local_elearning_system_get_site_currency_code(),
        'durationmonths' => '1',
        'expireslabel' => userdate(time() + DAYSECS),
        'orderid' => 'preview',
        'invoiceurl' => (new moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),
        'loginurl' => (new moodle_url('/login/index.php'))->out(false),
        'sitefullname' => format_string(get_site()->fullname),
    ];

    $subject = local_elearning_system_render_template_string($template['subject'], $variables);
    $body = local_elearning_system_render_template_string($template['body'], $variables);
    $messagehtml = nl2br(s($body));
    $fromuser = local_elearning_system_get_valid_from_user($recipient);

    return (bool)email_to_user($recipient, $fromuser, $subject, $body, $messagehtml);
}

/**
 * Process and send expiration reminder emails for orders expiring in 5 days.
 *
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_process_expiration_reminders(moodle_database $DB): void {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return;
    }

    $ordercolumns = $DB->get_columns('elearning_orders');
    if (!isset($ordercolumns['expiresat'])) {
        return;
    }

    $fivedays = 5 * DAYSECS;
    $now = time();
    $fivedasayfrom = $now + $fivedays;
    $fiveday_end = $fivedasayfrom + DAYSECS;

    $orders = $DB->get_records_select(
        'elearning_orders',
        'expiresat > :now AND expiresat <= :fiveday_end',
        ['now' => $now, 'fiveday_end' => $fiveday_end],
        'id ASC',
        'id, userid, productid, amount, durationmonths, expiresat, timecreated'
    );

    foreach ($orders as $order) {
        if (!local_elearning_system_notification_already_sent((int)$order->id, 'expiration_reminder', $DB)) {
            if (local_elearning_system_send_order_email_with_template($order, 'expiration_reminder', $DB)) {
                local_elearning_system_mark_notification_sent((int)$order->id, (int)$order->userid, 'expiration_reminder', $DB);
            }
        }
    }
}

/**
 * Send email notification once per order and type.
 *
 * @param stdClass $order
 * @param string $templatekey
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_send_order_notification_if_needed(stdClass $order, string $templatekey, moodle_database $DB): bool {
    $orderid = (int)($order->id ?? 0);
    $userid = (int)($order->userid ?? 0);
    if ($orderid <= 0 || $userid <= 0) {
        return false;
    }

    if (local_elearning_system_notification_already_sent($orderid, $templatekey, $DB)) {
        return true;
    }

    $sent = local_elearning_system_send_order_email_with_template($order, $templatekey, $DB);
    if ($sent) {
        local_elearning_system_mark_notification_sent($orderid, $userid, $templatekey, $DB);
    }

    return $sent;
}

/**
 * Force Moodle header login link to use local auth page.
 *
 * @param string $returnlocalurl local URL path where user should be returned after login.
 * @return void
 */
function local_elearning_system_force_auth_login_url(string $returnlocalurl): void {
    global $CFG;

    if ($returnlocalurl === '' || $returnlocalurl[0] !== '/') {
        $returnlocalurl = '/local/elearning_system/index.php';
    }

    $CFG->alternateloginurl = (new moodle_url('/local/elearning_system/auth.php', [
        'return' => $returnlocalurl,
    ]))->out(false);
}

/**
 * Build the fixed language switcher HTML shown on all pages.
 *
 * @return string
 */
function local_elearning_system_before_standard_top_of_body_html(): string {
    global $SESSION;

    $supported = [
        'en' => ['flagclass' => 'es-flag-en', 'label' => 'English (America)'],
        'fr' => ['flagclass' => 'es-flag-fr', 'label' => 'Français (France)'],
        'ar' => ['flagclass' => 'es-flag-ar', 'label' => 'العربية (Saudi Arabia)'],
    ];

    $currentlang = core_text::strtolower(current_language());
    $currentcode = substr($currentlang, 0, 2);
    if (!isset($supported[$currentcode])) {
        $currentcode = 'en';
    }

    $returnpath = $_SERVER['REQUEST_URI'] ?? '/';

    $buildbutton = static function(string $langcode, array $langmeta, string $extra = '') use ($returnpath): string {
        $targeturl = new moodle_url('/local/elearning_system/changelang.php', [
            'lang' => $langcode,
            'return' => $returnpath,
        ]);

        return html_writer::link(
            $targeturl,
            '<span class="es-lang-flag ' . s($langmeta['flagclass']) . '" aria-hidden="true"></span>',
            [
                'class' => 'es-lang-btn ' . trim($extra),
                'title' => $langmeta['label'],
                'aria-label' => $langmeta['label'],
            ],
        );
    };

    $html = html_writer::start_div('es-lang-switcher');
    $html .= $buildbutton($currentcode, $supported[$currentcode], 'es-lang-btn-current');
    $html .= html_writer::start_div('es-lang-options');
    foreach ($supported as $langcode => $langmeta) {
        if ($langcode === $currentcode) {
            continue;
        }
        $html .= $buildbutton($langcode, $langmeta, 'es-lang-btn-option');
    }
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    $html .= '<style>
        .es-lang-switcher {
            position: fixed;
            left: 14px;
            bottom: 14px;
            z-index: 9999;
            display: inline-flex;
            flex-direction: column-reverse;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid #d9dee8;
            border-radius: 24px;
            padding: 8px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.12);
        }
        .es-lang-options {
            display: none;
            flex-direction: column;
            gap: 8px;
        }
        .es-lang-switcher:hover .es-lang-options,
        .es-lang-switcher:focus-within .es-lang-options {
            display: flex;
        }
        .es-lang-btn {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid transparent;
            transition: transform .15s ease, border-color .15s ease, background .15s ease;
            background: transparent;
        }
        .es-lang-btn:hover {
            transform: translateY(-1px);
            border-color: #b8c5dd;
            background: #eef4ff;
            text-decoration: none;
        }
        .es-lang-btn-current {
            border-color: #6b84ff;
            background: #e9efff;
        }
        .es-lang-flag {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35);
        }
        .es-flag-en {
            background:
                linear-gradient(0deg, transparent 46%, #ffffff 46% 54%, transparent 54%),
                linear-gradient(90deg, transparent 46%, #ffffff 46% 54%, transparent 54%),
                linear-gradient(0deg, transparent 47%, #c8102e 47% 53%, transparent 53%),
                linear-gradient(90deg, transparent 47%, #c8102e 47% 53%, transparent 53%),
                #1f4aa8;
        }
        .es-flag-fr {
            background: linear-gradient(90deg, #244aa5 0 33.33%, #ffffff 33.33% 66.66%, #e33b32 66.66% 100%);
        }
        .es-flag-ar {
            background:
                linear-gradient(0deg, #0a8f3f 0 34%, #ffffff 34% 67%, #111111 67% 100%);
        }
    </style>';

    return $html;
}

/**
 * Return true when order is active considering expiresat column.
 *
 * @param stdClass $order
 * @param array $ordercolumns
 * @return bool
 */
function local_elearning_system_is_order_active(stdClass $order, array $ordercolumns): bool {
    if (!isset($ordercolumns['expiresat'])) {
        return true;
    }

    $expiresat = (int)($order->expiresat ?? 0);
    if ($expiresat <= 0) {
        return true;
    }

    return time() <= $expiresat;
}

/**
 * Return all course ids unlocked by a product or bundle.
 *
 * @param int $productid
 * @param moodle_database $DB
 * @return int[]
 */
function local_elearning_system_get_product_courseids_by_id(int $productid, moodle_database $DB): array {
    $product = $DB->get_record('elearning_products', ['id' => $productid], 'id,courseid,isbundle,bundleitems', IGNORE_MISSING);
    if (!$product) {
        return [];
    }

    $courseids = [];
    if (!empty($product->courseid)) {
        $courseids[] = (int)$product->courseid;
    }

    if (!empty($product->isbundle) && !empty($product->bundleitems)) {
        $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$product->bundleitems)))));
        if (!empty($bundleitemids)) {
            $bundleproducts = $DB->get_records_list('elearning_products', 'id', $bundleitemids, '', 'id,courseid');
            foreach ($bundleproducts as $bundleproduct) {
                if (!empty($bundleproduct->courseid)) {
                    $courseids[] = (int)$bundleproduct->courseid;
                }
            }
        }
    }

    return array_values(array_unique(array_filter($courseids)));
}

/**
 * Get the exact enrolment end timestamp for an order.
 *
 * @param stdClass $order
 * @return int
 */
function local_elearning_system_get_order_expiresat(stdClass $order): int {
    $purchasetime = (int)($order->timecreated ?? 0);
    $months = max(1, min(24, (int)($order->durationmonths ?? 1)));

    if ($purchasetime <= 0) {
        $purchasetime = time();
    }

    return local_elearning_system_calculate_expiration($purchasetime, $months);
}

/**
 * Update manual enrolments so their end date matches the course end date.
 *
 * @param int $courseid
 * @param int $userid
 * @param int $timeend
 * @return void
 */
function local_elearning_system_update_manual_enrolment_enddate(int $courseid, int $userid, int $timeend): void {
    global $DB;

    if ($courseid <= 0 || $userid <= 0) {
        return;
    }

    $instances = enrol_get_instances($courseid, true);
    foreach ($instances as $instance) {
        if ($instance->enrol !== 'manual') {
            continue;
        }

        $ue = $DB->get_record('user_enrolments', [
            'enrolid' => (int)$instance->id,
            'userid' => $userid,
        ], '*', IGNORE_MISSING);

        if (!$ue) {
            continue;
        }

        if ((int)$ue->timeend !== $timeend) {
            $ue->timeend = $timeend;
            $DB->update_record('user_enrolments', $ue);
        }
    }
}

/**
 * Sync all manual enrolments created by this plugin to exact order end dates.
 *
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_sync_enrolments_to_course_enddates(moodle_database $DB): void {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return;
    }

    $products = $DB->get_records('elearning_products', null, '', 'id,courseid,isbundle,bundleitems');
    if (empty($products)) {
        return;
    }

    foreach ($products as $product) {
        $courseids = [];
        if (!empty($product->courseid)) {
            $courseids[] = (int)$product->courseid;
        }

        if (!empty($product->isbundle) && !empty($product->bundleitems)) {
            $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$product->bundleitems)))));
            if (!empty($bundleitemids)) {
                $bundleproducts = $DB->get_records_list('elearning_products', 'id', $bundleitemids, '', 'id,courseid');
                foreach ($bundleproducts as $bundleproduct) {
                    if (!empty($bundleproduct->courseid)) {
                        $courseids[] = (int)$bundleproduct->courseid;
                    }
                }
            }
        }

        $courseids = array_values(array_unique(array_filter($courseids)));
        if (empty($courseids)) {
            continue;
        }

        $orders = $DB->get_records('elearning_orders', ['productid' => (int)$product->id], 'id ASC', 'id,userid,timecreated,durationmonths');
        foreach ($orders as $order) {
            foreach ($courseids as $courseid) {
                $timeend = local_elearning_system_get_order_expiresat($order);
                local_elearning_system_update_manual_enrolment_enddate((int)$courseid, (int)$order->userid, $timeend);
            }
        }
    }
}

/**
 * Unenrol user from courses unlocked by a product/bundle.
 *
 * @param int $userid
 * @param int $productid
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_unenrol_user_for_product(int $userid, int $productid, moodle_database $DB): void {
    require_once($GLOBALS['CFG']->libdir . '/enrollib.php');

    $courseids = local_elearning_system_get_product_courseids_by_id($productid, $DB);
    if (empty($courseids)) {
        return;
    }

    $manualplugin = enrol_get_plugin('manual');
    if (!$manualplugin) {
        return;
    }

    foreach ($courseids as $courseid) {
        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $instance) {
            if ($instance->enrol !== 'manual') {
                continue;
            }

            $ue = $DB->get_record('user_enrolments', [
                'enrolid' => (int)$instance->id,
                'userid' => $userid,
            ], '*', IGNORE_MISSING);

            if ($ue) {
                $manualplugin->unenrol_user($instance, $userid);
            }
        }
    }
}

/**
 * Remove access for expired orders of a user.
 *
 * @param int $userid
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_cleanup_expired_orders_for_user(int $userid, moodle_database $DB): void {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return;
    }

    $ordercolumns = $DB->get_columns('elearning_orders');
    if (!isset($ordercolumns['expiresat'])) {
        return;
    }

    $orders = $DB->get_records_select('elearning_orders', 'userid = :userid AND expiresat > 0 AND expiresat < :now', [
        'userid' => $userid,
        'now' => time(),
    ], '', 'id,productid,expiresat');

    foreach ($orders as $order) {
        local_elearning_system_unenrol_user_for_product($userid, (int)$order->productid, $DB);
    }
}

/**
 * Check active purchase coverage (direct or bundle item).
 *
 * @param int $userid
 * @param int $productid
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_is_product_covered_by_active_purchase(int $userid, int $productid, moodle_database $DB): bool {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return false;
    }

    $ordercolumns = $DB->get_columns('elearning_orders');

    $orders = $DB->get_records('elearning_orders', ['userid' => $userid], '', 'id,productid,expiresat');
    foreach ($orders as $order) {
        if (!local_elearning_system_is_order_active($order, $ordercolumns)) {
            continue;
        }

        if ((int)$order->productid === $productid) {
            return true;
        }

        $bundleproduct = $DB->get_record('elearning_products', ['id' => (int)$order->productid], 'id,isbundle,bundleitems', IGNORE_MISSING);
        if (!$bundleproduct || empty($bundleproduct->isbundle) || empty($bundleproduct->bundleitems)) {
            continue;
        }

        $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$bundleproduct->bundleitems)))));
        if (in_array($productid, $bundleitemids, true)) {
            return true;
        }
    }

    return false;
}

function local_elearning_system_send_custom_email($toemail, $toname, $subject, $htmlbody, $altbody = '') {
    $mailer = new \local_elearning_system\mailer();

    return $mailer::send_mail($toemail, $toname, $subject, $htmlbody, $altbody);
}
