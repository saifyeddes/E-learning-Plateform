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

/**
 * Return the active language for the plugin frontend.
 *
 * Falls back to the first two letters of Moodle current language.
 *
 * @return string
 */
function local_elearning_system_get_active_language(): string {
    global $SESSION, $USER;

    $supported = ['en', 'fr', 'ar'];

    $lang = '';
    if (!empty($_GET['lang']) && is_string($_GET['lang'])) {
        $lang = core_text::strtolower(substr(clean_param($_GET['lang'], PARAM_ALPHANUMEXT), 0, 2));
    }

    if ($lang === '' && !empty($_POST['lang']) && is_string($_POST['lang'])) {
        $lang = core_text::strtolower(substr(clean_param($_POST['lang'], PARAM_ALPHANUMEXT), 0, 2));
    }

    if ($lang === '') {
        if (!empty($SESSION->forcelang) && is_string($SESSION->forcelang)) {
            $lang = core_text::strtolower(substr($SESSION->forcelang, 0, 2));
        } else if (!empty($SESSION->local_elearning_system_lang) && is_string($SESSION->local_elearning_system_lang)) {
            $lang = core_text::strtolower(substr($SESSION->local_elearning_system_lang, 0, 2));
        } else if (!empty($_COOKIE['local_elearning_system_lang']) && is_string($_COOKIE['local_elearning_system_lang'])) {
            $lang = core_text::strtolower(substr($_COOKIE['local_elearning_system_lang'], 0, 2));
        } else if (!empty($USER->lang) && is_string($USER->lang)) {
            $lang = core_text::strtolower(substr($USER->lang, 0, 2));
        } else {
            $lang = core_text::strtolower(substr(current_language(), 0, 2));
        }
    }

    if (!in_array($lang, $supported, true)) {
        $lang = 'en';
    }

    return $lang;
}

/**
 * Load flat language strings for the plugin frontend.
 *
 * Files are stored as /lang/en.php, /lang/fr.php, /lang/ar.php.
 * English is used as the fallback.
 *
 * @return array<string, string>
 */
function local_elearning_system_get_flat_language_strings(): array {
    $lang = local_elearning_system_get_active_language();

    $loadstrings = static function(string $code): array {
        $filepath = __DIR__ . '/lang/' . $code . '.php';
        if (!file_exists($filepath)) {
            return [];
        }

        $strings = include($filepath);
        return is_array($strings) ? $strings : [];
    };

    $base = $loadstrings('en');
    if ($lang !== 'en') {
        $base = array_merge($base, $loadstrings($lang));
    }

    return $base;
}

/**
 * Decide whether the language switcher should be visible for the current user.
 * It is shown only for student and parent interfaces.
 *
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_should_show_language_switcher(moodle_database $DB): bool {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return false;
    }

    $userid = (int)($USER->id ?? 0);
    if ($userid <= 0) {
        return false;
    }

    $usercontext = local_elearning_system_get_effective_user_context($userid, $DB);
    if (!empty($usercontext['isparentaccount'])) {
        return true;
    }

    $sql = "SELECT 1
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = :userid
               AND r.shortname = :shortname";
    $params = [
        'userid' => $userid,
        'shortname' => 'student',
    ];

    return $DB->record_exists_sql($sql, $params);
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
            'subject' => 'Your purchase has been confirmed - {{productname}}',
            'body' => "Hello {{firstname}},\n\nYour purchase of {{productname}} has been confirmed.\n\nOrder number: {{orderid}}\nAmount: {{currency}} {{amount}}\nAccess duration: {{durationmonths}} month(s)\nExpiration date: {{expireslabel}}\n\nYour invoice is attached to this email.\n\nThank you for learning with {{sitefullname}}.",
        ],

        'purchase_for_child' => [
            'subject' => 'Purchase confirmed for your child - {{productname}}',
            'body' => "Hello {{parentfirstname}},\n\nYour purchase of {{productname}} for {{childfullname}} has been confirmed.\n\nOrder number: {{orderid}}\nAmount: {{currency}} {{amount}}\nAccess duration: {{durationmonths}} month(s)\nExpiration date: {{expireslabel}}\n\nThe invoice is attached to this email.\n\nThank you for learning with {{sitefullname}}.",
        ],

        'expiration_reminder' => [
            'subject' => 'Your course access will expire in 7 days - {{productname}}',
            'body' => "Hello {{firstname}},\n\nYour access to {{productname}} will expire in 7 days.\n\nExpiration date: {{expireslabel}}\n\nPlease renew your access if you want to continue learning without interruption.\n\n{{sitefullname}}",
        ],

        'inactive_no_purchase_2_months' => [
            'subject' => 'Discover our latest courses',
            'body' => "Hello {{firstname}},\n\nYou have not purchased a course for 2 months.\n\nNew courses are available on the platform. Visit your learning space to discover them:\n{{loginurl}}\n\n{{sitefullname}}",
        ],
    ];
}

function local_elearning_system_generate_invoice_pdf_file(stdClass $order, stdClass $product, stdClass $user, ?string $lang = null): array {
    global $CFG;

    require_once($CFG->libdir . '/pdflib.php');

    if ($lang === null || $lang === '') {
        $lang = local_elearning_system_get_preferred_email_lang((int)$user->id);
    }

    $tmpdir = make_request_directory();
    $filename = 'invoice-order-' . $order->id . '.pdf';
    $filepath = $tmpdir . '/' . $filename;

    $pdf = new pdf();
    $pdf->SetCreator('Moodle');
    $pdf->SetAuthor(format_string(get_site()->fullname));
    $pdf->SetTitle('Invoice #' . $order->id);
    $pdf->AddPage();

    // Police Unicode importante pour l’arabe.
    $pdf->SetFont('dejavusans', '', 10);

    $currency = local_elearning_system_get_site_currency_code();
    $amount = local_elearning_system_format_invoice_amount((float)$order->amount);
    $durationmonths = max(1, (int)($order->durationmonths ?? 1));
    $durationlabel = local_elearning_system_format_email_duration($durationmonths, $lang);

    $purchasedate = local_elearning_system_format_email_datetime((int)($order->timecreated ?? time()), $lang);
    $expirationdate = !empty($order->expiresat)
        ? local_elearning_system_format_email_datetime((int)$order->expiresat, $lang)
        : local_elearning_system_format_email_datetime(0, $lang);

    $sitefullname = format_string(get_site()->fullname);
    $productname = format_string($product->name);
    $username = fullname($user);
    $useremail = (string)$user->email;

    if ($lang === 'fr') {
        $html = '
            <h1 style="text-align:center;">' . s($sitefullname) . '</h1>
            <h2 style="text-align:center;">Facture</h2>

            <p><strong>Facture n° :</strong> #' . s($order->id) . '</p>
            <p><strong>Date d’achat :</strong> ' . s($purchasedate) . '</p>
            <p><strong>Date d’expiration :</strong> ' . s($expirationdate) . '</p>

            <p><strong>Client :</strong><br>' . s($username) . '<br>' . s($useremail) . '</p>

            <table border="1" cellpadding="6" cellspacing="0" width="100%">
                <thead>
                    <tr style="background-color:#d9e8ff;">
                        <th><strong>Produit / Service</strong></th>
                        <th><strong>Durée</strong></th>
                        <th><strong>Montant</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>' . s($productname) . '</td>
                        <td>' . s($durationlabel) . '</td>
                        <td>' . s($currency) . ' ' . s($amount) . '</td>
                    </tr>
                </tbody>
            </table>

            <br><br>
            <p style="text-align:right;"><strong>Total :</strong> ' . s($currency) . ' ' . s($amount) . '</p>
        ';
    } else if ($lang === 'ar') {
        $html = '
            <div dir="rtl" style="text-align:right;">
                <h1 style="text-align:center;">' . s($sitefullname) . '</h1>
                <h2 style="text-align:center;">فاتورة</h2>

                <p><strong>رقم الفاتورة:</strong> #' . s($order->id) . '</p>
                <p><strong>تاريخ الشراء:</strong> ' . s($purchasedate) . '</p>
                <p><strong>تاريخ انتهاء الصلاحية:</strong> ' . s($expirationdate) . '</p>

                <p><strong>العميل:</strong><br>' . s($username) . '<br>' . s($useremail) . '</p>

                <table border="1" cellpadding="6" cellspacing="0" width="100%">
                    <thead>
                        <tr style="background-color:#d9e8ff;">
                            <th><strong>المنتج / الخدمة</strong></th>
                            <th><strong>المدة</strong></th>
                            <th><strong>المبلغ</strong></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>' . s($productname) . '</td>
                            <td>' . s($durationlabel) . '</td>
                            <td>' . s($currency) . ' ' . s($amount) . '</td>
                        </tr>
                    </tbody>
                </table>

                <br><br>
                <p style="text-align:left;"><strong>الإجمالي:</strong> ' . s($currency) . ' ' . s($amount) . '</p>
            </div>
        ';
    } else {
        $html = '
            <h1 style="text-align:center;">' . s($sitefullname) . '</h1>
            <h2 style="text-align:center;">Invoice</h2>

            <p><strong>Invoice #:</strong> #' . s($order->id) . '</p>
            <p><strong>Purchase date:</strong> ' . s($purchasedate) . '</p>
            <p><strong>Expiration date:</strong> ' . s($expirationdate) . '</p>

            <p><strong>Client:</strong><br>' . s($username) . '<br>' . s($useremail) . '</p>

            <table border="1" cellpadding="6" cellspacing="0" width="100%">
                <thead>
                    <tr style="background-color:#d9e8ff;">
                        <th><strong>Product / Service</strong></th>
                        <th><strong>Duration</strong></th>
                        <th><strong>Amount</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>' . s($productname) . '</td>
                        <td>' . s($durationlabel) . '</td>
                        <td>' . s($currency) . ' ' . s($amount) . '</td>
                    </tr>
                </tbody>
            </table>

            <br><br>
            <p style="text-align:right;"><strong>Total:</strong> ' . s($currency) . ' ' . s($amount) . '</p>
        ';
    }

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filepath, 'F');

    return [$filepath, $filename];
}

function local_elearning_system_send_purchase_email_with_invoice(int $orderid): bool {
    global $DB;

    $order = $DB->get_record('elearning_orders', ['id' => $orderid], '*', MUST_EXIST);
    $product = $DB->get_record('elearning_products', ['id' => $order->productid], '*', MUST_EXIST);
    $user = $DB->get_record('user', [
        'id' => $order->userid,
        'deleted' => 0,
        'suspended' => 0
    ], '*', MUST_EXIST);

    $user = local_elearning_system_prepare_mail_user($user);
    $fromuser = local_elearning_system_get_valid_from_user($user);

    $lang = local_elearning_system_get_preferred_email_lang((int)$user->id);

    $currency = local_elearning_system_get_site_currency_code();
    $amount = number_format((float)$order->amount, 2);
    $durationmonths = max(1, (int)($order->durationmonths ?? 1));
    $durationlabel = local_elearning_system_format_email_duration($durationmonths, $lang);

    $purchasedate = local_elearning_system_format_email_datetime((int)($order->timecreated ?? time()), $lang);
    $expirationdate = !empty($order->expiresat)
        ? local_elearning_system_format_email_datetime((int)$order->expiresat, $lang)
        : local_elearning_system_format_email_datetime(0, $lang);

    $productname = format_string($product->name);
    $sitename = format_string(get_site()->fullname);
    $fullname = fullname($user);

    if ($lang === 'fr') {
        $subject = 'Confirmation de votre achat - ' . $productname;

        $body = "Bonjour " . $fullname . ",\n\n"
            . "Votre achat du cours " . $productname . " a été confirmé avec succès.\n\n"
            . "Numéro de commande : " . (int)$order->id . "\n"
            . "Montant : " . $currency . " " . $amount . "\n"
            . "Durée d’accès : " . $durationlabel . "\n"
            . "Date d’achat : " . $purchasedate . "\n"
            . "Date d’expiration : " . $expirationdate . "\n\n"
            . "Votre facture est jointe à cet email.\n\n"
            . "Merci d’apprendre avec " . $sitename . ".";

        $messagehtml = nl2br(s($body));

    } else if ($lang === 'ar') {
        $subject = 'تأكيد عملية الشراء - ' . $productname;

        $body = "مرحبًا " . $fullname . "،\n\n"
            . "تم تأكيد شراء الدورة " . $productname . " بنجاح.\n\n"
            . "رقم الطلب: " . (int)$order->id . "\n"
            . "المبلغ: " . $currency . " " . $amount . "\n"
            . "مدة الوصول: " . $durationlabel . "\n"
            . "تاريخ الشراء: " . $purchasedate . "\n"
            . "تاريخ انتهاء الصلاحية: " . $expirationdate . "\n\n"
            . "الفاتورة مرفقة بهذا البريد الإلكتروني.\n\n"
            . "شكرًا لتعلمك مع " . $sitename . ".";

        $messagehtml = '<div dir="rtl" style="text-align:right;">' . nl2br(s($body)) . '</div>';

    } else {
        $subject = 'Your purchase has been confirmed - ' . $productname;

        $body = "Hello " . $fullname . ",\n\n"
            . "Your purchase of " . $productname . " has been confirmed.\n\n"
            . "Order number: " . (int)$order->id . "\n"
            . "Amount: " . $currency . " " . $amount . "\n"
            . "Access duration: " . $durationlabel . "\n"
            . "Purchase date: " . $purchasedate . "\n"
            . "Expiration date: " . $expirationdate . "\n\n"
            . "Your invoice is attached to this email.\n\n"
            . "Thank you for learning with " . $sitename . ".";

        $messagehtml = nl2br(s($body));
    }

    [$invoicepath, $invoicename] = local_elearning_system_generate_invoice_pdf_file($order, $product, $user, $lang);

    return (bool)email_to_user(
        $user,
        $fromuser,
        $subject,
        $body,
        $messagehtml,
        $invoicepath,
        $invoicename
    );
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
    $noreply = trim((string)get_config('core', 'noreplyaddress'));
    $smtpuser = trim((string)get_config('core', 'smtpuser'));

    $email = '';
    if ($noreply !== '' && validate_email($noreply)) {
        $email = $noreply;
    } else if ($smtpuser !== '' && validate_email($smtpuser)) {
        $email = $smtpuser;
    } else {
        $support = core_user::get_support_user();
        if ($support && !empty($support->email) && validate_email((string)$support->email)) {
            return local_elearning_system_prepare_mail_user($support);
        }
    }

    $from = new stdClass();
    $from->id = -99;
    $from->username = 'local_elearning_system_notifier';
    $from->firstname = 'Dourouss';
    $from->lastname = 'E-learning';
    $from->email = $email;
    $from->mailformat = 1;
    $from->maildisplay = 1;
    $from->maildigest = 0;
    $from->lang = !empty($recipient->lang) ? $recipient->lang : current_language();
    $from->timezone = !empty($recipient->timezone) ? $recipient->timezone : '99';

    return local_elearning_system_prepare_mail_user($from);
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
    $loginurl = (new moodle_url('/local/elearning_system/auth.php'))->out(false);

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

    $lang = local_elearning_system_get_preferred_email_lang((int)$user->id);

if (in_array($templatekey, ['purchase_product', 'purchase_confirmation'], true)) {
    $emailcontent = local_elearning_system_build_email_content('purchase_confirmation', $lang, [
        'coursename' => $productname,
        'duration' => local_elearning_system_format_email_duration($months, $lang),
        'invoiceurl' => $invoiceurl,
    ]);

    $subject = $emailcontent['subject'];

    $purchasedate = local_elearning_system_format_email_datetime((int)($order->timecreated ?? time()), $lang);
    $expirationdate = !empty($order->expiresat)
        ? local_elearning_system_format_email_datetime((int)$order->expiresat, $lang)
        : local_elearning_system_format_email_datetime(0, $lang);

    if ($lang === 'fr') {
        $messagehtml = '
            <p>Bonjour ' . s(fullname($user)) . ',</p>
            <p>Votre achat du cours <strong>' . s($productname) . '</strong> a été confirmé avec succès.</p>
            <p><strong>Numéro de commande :</strong> ' . s((string)(int)($order->id ?? 0)) . '</p>
            <p><strong>Montant :</strong> ' . s(local_elearning_system_get_site_currency_code()) . ' ' . s($amount) . '</p>
            <p><strong>Durée d’accès :</strong> ' . s(local_elearning_system_format_email_duration($months, $lang)) . '</p>
            <p><strong>Date d’achat :</strong> ' . s($purchasedate) . '</p>
            <p><strong>Date d’expiration :</strong> ' . s($expirationdate) . '</p>
            <p><a href="' . s($invoiceurl) . '">Télécharger la facture</a></p>
            <p>Cordialement,<br>Équipe E-learning</p>
        ';
    } else if ($lang === 'ar') {
        $messagehtml = '
            <div dir="rtl" style="text-align:right;">
                <p>مرحبًا ' . s(fullname($user)) . '،</p>
                <p>تم تأكيد شراء الدورة <strong>' . s($productname) . '</strong> بنجاح.</p>
                <p><strong>رقم الطلب:</strong> ' . s((string)(int)($order->id ?? 0)) . '</p>
                <p><strong>المبلغ:</strong> ' . s(local_elearning_system_get_site_currency_code()) . ' ' . s($amount) . '</p>
                <p><strong>مدة الوصول:</strong> ' . s(local_elearning_system_format_email_duration($months, $lang)) . '</p>
                <p><strong>تاريخ الشراء:</strong> ' . s($purchasedate) . '</p>
                <p><strong>تاريخ انتهاء الصلاحية:</strong> ' . s($expirationdate) . '</p>
                <p><a href="' . s($invoiceurl) . '">تحميل الفاتورة</a></p>
                <p>مع تحياتنا،<br>فريق التعلم الإلكتروني</p>
            </div>
        ';
    } else {
        $messagehtml = '
            <p>Hello ' . s(fullname($user)) . ',</p>
            <p>Your purchase of <strong>' . s($productname) . '</strong> has been confirmed.</p>
            <p><strong>Order number:</strong> ' . s((string)(int)($order->id ?? 0)) . '</p>
            <p><strong>Amount:</strong> ' . s(local_elearning_system_get_site_currency_code()) . ' ' . s($amount) . '</p>
            <p><strong>Access duration:</strong> ' . s(local_elearning_system_format_email_duration($months, $lang)) . '</p>
            <p><strong>Purchase date:</strong> ' . s($purchasedate) . '</p>
            <p><strong>Expiration date:</strong> ' . s($expirationdate) . '</p>
            <p><a href="' . s($invoiceurl) . '">Download invoice</a></p>
            <p>Best regards,<br>E-learning Team</p>
        ';
    }

    $body = html_to_text($messagehtml);

} else if ($templatekey === 'expiration_reminder') {
    $emailcontent = local_elearning_system_build_email_content('expiration_reminder', $lang, [
        'coursename' => $variables['coursename'] ?? '',
        'duration' => !empty($variables['durationmonths'])
            ? local_elearning_system_format_email_duration((int)$variables['durationmonths'], $lang)
            : '',
        'checkouturl' => $variables['checkouturl'] ?? '',
    ]);

    $subject = $emailcontent['subject'];
    $messagehtml = $emailcontent['html'];
    $body = html_to_text($messagehtml);

} else {
    $subject = local_elearning_system_render_template_string($template['subject'], $variables);
    $body = local_elearning_system_render_template_string($template['body'], $variables);
    $messagehtml = nl2br(s($body));
}
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

/**
 * Get Moodle user's preferred language.
 *
 * @param int $userid
 * @return string en|fr|ar
 */
function local_elearning_system_get_preferred_email_lang(int $userid): string {
    global $DB;

    $user = $DB->get_record('user', ['id' => $userid], 'id, lang', IGNORE_MISSING);

    if (!$user || empty($user->lang)) {
        return 'en';
    }

    $lang = strtolower((string)$user->lang);

    if (strpos($lang, 'fr') === 0) {
        return 'fr';
    }

    if (strpos($lang, 'ar') === 0) {
        return 'ar';
    }

    return 'en';
}

function local_elearning_system_format_email_duration(int $months, string $lang): string {
    $months = max(1, $months);

    if ($lang === 'fr') {
        return $months . ' mois';
    }

    if ($lang === 'ar') {
        return $months . ' شهر';
    }

    return $months . ' month' . ($months > 1 ? 's' : '');
}
/**
 * Build multilingual email content.
 *
 * @param string $type
 * @param string $lang
 * @param array $data
 * @return array
 */

function local_elearning_system_format_email_datetime(int $timestamp, string $lang): string {
    if ($timestamp <= 0) {
        if ($lang === 'fr') {
            return 'Illimité';
        }

        if ($lang === 'ar') {
            return 'غير محدود';
        }

        return 'Unlimited';
    }

    // Format numérique stable pour éviter les caractères illisibles dans les PDF.
    return userdate($timestamp, '%d/%m/%Y %H:%M');
}

function local_elearning_system_format_invoice_amount(float $amount): string {
    return number_format($amount, 2);
}
function local_elearning_system_build_email_content(string $type, string $lang, array $data = []): array {
    $coursename = $data['coursename'] ?? '';
    $duration = $data['duration'] ?? '';
    $studentname = $data['studentname'] ?? '';
    $invoiceurl = $data['invoiceurl'] ?? '';
    $checkouturl = $data['checkouturl'] ?? '';

    if ($type === 'purchase_confirmation') {
        if ($lang === 'fr') {
            return [
                'subject' => 'Confirmation de votre achat',
                'html' => '
                    <p>Bonjour,</p>
                    <p>Votre achat du cours <strong>' . s($coursename) . '</strong> a été confirmé avec succès.</p>
                    <p>Durée : <strong>' . s($duration) . '</strong></p>
                    ' . ($invoiceurl ? '<p><a href="' . s($invoiceurl) . '">Télécharger la facture</a></p>' : '') . '
                    <p>Cordialement,<br>Équipe E-learning</p>
                ',
            ];
        }

        if ($lang === 'ar') {
            return [
                'subject' => 'تأكيد عملية الشراء',
                'html' => '
                    <div dir="rtl" style="text-align:right;">
                        <p>مرحبًا،</p>
                        <p>تم تأكيد شراء الدورة <strong>' . s($coursename) . '</strong> بنجاح.</p>
                        <p>المدة: <strong>' . s($duration) . '</strong></p>
                        ' . ($invoiceurl ? '<p><a href="' . s($invoiceurl) . '">تحميل الفاتورة</a></p>' : '') . '
                        <p>مع تحياتنا،<br>فريق التعلم الإلكتروني</p>
                    </div>
                ',
            ];
        }

        return [
            'subject' => 'Purchase confirmation',
            'html' => '
                <p>Hello,</p>
                <p>Your purchase of the course <strong>' . s($coursename) . '</strong> has been successfully confirmed.</p>
                <p>Duration: <strong>' . s($duration) . '</strong></p>
                ' . ($invoiceurl ? '<p><a href="' . s($invoiceurl) . '">Download invoice</a></p>' : '') . '
                <p>Best regards,<br>E-learning Team</p>
            ',
        ];
    }

    if ($type === 'expiration_reminder') {
        if ($lang === 'fr') {
            return [
                'subject' => 'Votre cours expire bientôt',
                'html' => '
                    <p>Bonjour,</p>
                    <p>Votre accès au cours <strong>' . s($coursename) . '</strong> expirera dans 7 jours.</p>
                    <p>Vous pouvez renouveler votre accès depuis votre espace étudiant.</p>
                    ' . ($checkouturl ? '<p><a href="' . s($checkouturl) . '">Renouveler maintenant</a></p>' : '') . '
                    <p>Cordialement,<br>Équipe E-learning</p>
                ',
            ];
        }

        if ($lang === 'ar') {
            return [
                'subject' => 'ستنتهي صلاحية دورتك قريبًا',
                'html' => '
                    <div dir="rtl" style="text-align:right;">
                        <p>مرحبًا،</p>
                        <p>سينتهي وصولك إلى الدورة <strong>' . s($coursename) . '</strong> خلال 7 أيام.</p>
                        <p>يمكنك تجديد الوصول من فضاء الطالب.</p>
                        ' . ($checkouturl ? '<p><a href="' . s($checkouturl) . '">التجديد الآن</a></p>' : '') . '
                        <p>مع تحياتنا،<br>فريق التعلم الإلكتروني</p>
                    </div>
                ',
            ];
        }

        return [
            'subject' => 'Your course will expire soon',
            'html' => '
                <p>Hello,</p>
                <p>Your access to the course <strong>' . s($coursename) . '</strong> will expire in 7 days.</p>
                <p>You can renew your access from your student space.</p>
                ' . ($checkouturl ? '<p><a href="' . s($checkouturl) . '">Renew now</a></p>' : '') . '
                <p>Best regards,<br>E-learning Team</p>
            ',
        ];
    }
    if ($type === 'purchase_for_child') {
    if ($lang === 'fr') {
        return [
            'subject' => 'Confirmation d’achat pour votre enfant',
            'html' => '
                <p>Bonjour,</p>
                <p>L’achat du cours <strong>' . s($coursename) . '</strong> pour <strong>' . s($studentname) . '</strong> a été confirmé avec succès.</p>
                <p>Durée : <strong>' . s($duration) . '</strong></p>
                ' . ($invoiceurl ? '<p><a href="' . s($invoiceurl) . '">Télécharger la facture</a></p>' : '') . '
                <p>Cordialement,<br>Équipe E-learning</p>
            ',
        ];
    }

    if ($lang === 'ar') {
        return [
            'subject' => 'تأكيد شراء دورة لطفلك',
            'html' => '
                <div dir="rtl" style="text-align:right;">
                    <p>مرحبًا،</p>
                    <p>تم تأكيد شراء الدورة <strong>' . s($coursename) . '</strong> للطالب <strong>' . s($studentname) . '</strong> بنجاح.</p>
                    <p>المدة: <strong>' . s($duration) . '</strong></p>
                    ' . ($invoiceurl ? '<p><a href="' . s($invoiceurl) . '">تحميل الفاتورة</a></p>' : '') . '
                    <p>مع تحياتنا،<br>فريق التعلم الإلكتروني</p>
                </div>
            ',
        ];
    }

    return [
        'subject' => 'Purchase confirmation for your child',
        'html' => '
            <p>Hello,</p>
            <p>The purchase of the course <strong>' . s($coursename) . '</strong> for <strong>' . s($studentname) . '</strong> has been successfully confirmed.</p>
            <p>Duration: <strong>' . s($duration) . '</strong></p>
            ' . ($invoiceurl ? '<p><a href="' . s($invoiceurl) . '">Download invoice</a></p>' : '') . '
            <p>Best regards,<br>E-learning Team</p>
        ',
    ];
}

    return [
        'subject' => 'E-learning notification',
        'html' => '<p>Hello,</p><p>You have a new notification.</p>',
    ];
}
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
    $loginurl = (new moodle_url('/local/elearning_system/auth.php'))->out(false);

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

 $lang = local_elearning_system_get_preferred_email_lang((int)$child->id);

$emailcontent = local_elearning_system_build_email_content('purchase_for_child', $lang, [
    'coursename' => $productname,
    'duration' => local_elearning_system_format_email_duration($months, $lang),
    'studentname' => fullname($child),
    'invoiceurl' => $invoiceurl,
]);

$subject = $emailcontent['subject'];
$messagehtml = $emailcontent['html'];
$body = html_to_text($messagehtml);

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
        'loginurl' => (new moodle_url('/local/elearning_system/auth.php'))->out(false),
        'sitefullname' => format_string(get_site()->fullname),
    ];

    $subject = local_elearning_system_render_template_string($template['subject'], $variables);
    $body = local_elearning_system_render_template_string($template['body'], $variables);
    $messagehtml = nl2br(s($body));
    $fromuser = local_elearning_system_get_valid_from_user($recipient);

    return (bool)email_to_user($recipient, $fromuser, $subject, $body, $messagehtml);
}

/**
 * Check if a user-level notification was sent recently.
 *
 * @param int $userid
 * @param string $notificationtype
 * @param int $since
 * @param moodle_database $DB
 * @return bool
 */
function local_elearning_system_user_notification_sent_since(
    int $userid,
    string $notificationtype,
    int $since,
    moodle_database $DB
): bool {
    if (!local_elearning_system_has_notification_log_table($DB)) {
        return false;
    }

    return $DB->record_exists_select(
        'elearning_notification_log',
        'userid = :userid AND notificationtype = :notificationtype AND timecreated >= :since',
        [
            'userid' => $userid,
            'notificationtype' => $notificationtype,
            'since' => $since,
        ]
    );
}

/**
 * Mark a user-level notification as sent.
 *
 * @param int $userid
 * @param string $notificationtype
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_mark_user_notification_sent(
    int $userid,
    string $notificationtype,
    moodle_database $DB
): void {
    if (!local_elearning_system_has_notification_log_table($DB)) {
        return;
    }

    $record = new stdClass();
    $record->orderid = 0;
    $record->userid = $userid;
    $record->notificationtype = $notificationtype;
    $record->timecreated = time();

    $DB->insert_record('elearning_notification_log', $record);
}

/**
 * Send a generic template email to a user without an order.
 *
 * @param stdClass $user
 * @param string $templatekey
 * @param array $extra
 * @return bool
 */
function local_elearning_system_send_user_email_with_template(
    stdClass $user,
    string $templatekey,
    array $extra = []
): bool {
    if (empty($user->email) || !validate_email((string)$user->email)) {
        return false;
    }

    $user = local_elearning_system_prepare_mail_user($user);
    $template = local_elearning_system_get_email_template($templatekey);

    if ($template['subject'] === '' || $template['body'] === '') {
        return false;
    }

    $variables = [
        'firstname' => (string)($user->firstname ?? ''),
        'lastname' => (string)($user->lastname ?? ''),
        'fullname' => fullname($user),
        'email' => (string)$user->email,
        'productname' => '',
        'coursename' => '',
        'amount' => '',
        'currency' => local_elearning_system_get_site_currency_code(),
        'durationmonths' => '',
        'expireslabel' => '',
        'orderid' => '',
        'invoiceurl' => '',
        'loginurl' => (new moodle_url('/local/elearning_system/auth.php'))->out(false),
        'sitefullname' => format_string(get_site()->fullname),
    ];

    foreach ($extra as $key => $value) {
        $variables[$key] = (string)$value;
    }

    $subject = local_elearning_system_render_template_string($template['subject'], $variables);
    $body = local_elearning_system_render_template_string($template['body'], $variables);
    $messagehtml = nl2br(s($body));

    $fromuser = local_elearning_system_get_valid_from_user($user);

    return (bool)email_to_user($user, $fromuser, $subject, $body, $messagehtml);
}

/**
 * Process users who did not purchase anything for 2 months.
 *
 * @param moodle_database $DB
 * @return void
 */
function local_elearning_system_process_inactive_purchase_reminders(moodle_database $DB): void {
    if (!$DB->get_manager()->table_exists('elearning_orders')) {
        return;
    }

    $now = time();
    $twomonthsago = $now - (60 * DAYSECS);
    $donotrepeatsince = $now - (30 * DAYSECS);
    $templatekey = 'inactive_no_purchase_2_months';

    $users = $DB->get_records_select(
        'user',
        'deleted = 0 AND suspended = 0 AND email <> :emptyemail',
        ['emptyemail' => ''],
        'id ASC',
        'id,username,firstname,lastname,email,auth,confirmed,deleted,suspended,mailformat,maildisplay,maildigest,lang,timezone'
    );

    foreach ($users as $user) {
        if (empty($user->email) || !validate_email((string)$user->email)) {
            continue;
        }

        if ((int)($user->confirmed ?? 1) === 0) {
            continue;
        }

        // Last purchase by this user.
        $lastorder = $DB->get_record_sql(
            "SELECT id, timecreated
               FROM {elearning_orders}
              WHERE userid = :userid
           ORDER BY timecreated DESC, id DESC",
            ['userid' => (int)$user->id],
            IGNORE_MULTIPLE
        );

        // If the user purchased recently, skip.
        if ($lastorder && (int)$lastorder->timecreated > $twomonthsago) {
            continue;
        }

        // Avoid sending the same reminder every day.
        if (local_elearning_system_user_notification_sent_since((int)$user->id, $templatekey, $donotrepeatsince, $DB)) {
            continue;
        }

        if (local_elearning_system_send_user_email_with_template($user, $templatekey)) {
            local_elearning_system_mark_user_notification_sent((int)$user->id, $templatekey, $DB);
        }
    }
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

    if (!$DB->get_manager()->table_exists('elearning_notification_log')) {
        return;
    }

    $now = time();
    $sevenDays = 7 * DAYSECS;
    $start = $now;
    $end = $now + $sevenDays;

    $orders = $DB->get_records_select(
        'elearning_orders',
        'expiresat IS NOT NULL AND expiresat > :starttime AND expiresat <= :endtime',
        [
            'starttime' => $start,
            'endtime' => $end,
        ],
        'expiresat ASC'
    );

    foreach ($orders as $order) {
        $alreadySent = $DB->record_exists('elearning_notification_log', [
            'orderid' => (int)$order->id,
            'userid' => (int)$order->userid,
            'notificationtype' => 'expiration_reminder',
        ]);

        if ($alreadySent) {
            continue;
        }

        $product = $DB->get_record('elearning_products', ['id' => $order->productid], '*', IGNORE_MISSING);
        $user = $DB->get_record('user', ['id' => $order->userid, 'deleted' => 0, 'suspended' => 0], '*', IGNORE_MISSING);

        if (!$product || !$user || empty($user->email) || !validate_email((string)$user->email)) {
            continue;
        }

        $sent = local_elearning_system_send_user_email_with_template($user, 'expiration_reminder', [
    'productname' => (string)$product->name,
    'coursename' => (string)$product->name,
    'expireslabel' => userdate((int)$order->expiresat),
    'durationmonths' => (string)($order->durationmonths ?? ''),
    'orderid' => (string)$order->id,
    'amount' => number_format((float)$order->amount, 2),
    'currency' => local_elearning_system_get_site_currency_code(),
    'checkouturl' => (new moodle_url('/local/elearning_system/mycourses.php'))->out(false),
]);

        if ($sent) {
            $record = new stdClass();
            $record->orderid = (int)$order->id;
            $record->userid = (int)$order->userid;
            $record->notificationtype = 'expiration_reminder';
            $record->timecreated = time();

            $DB->insert_record('elearning_notification_log', $record);
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
    global $DB, $SESSION;

    if (!local_elearning_system_should_show_language_switcher($DB)) {
        return '';
    }

    $supported = [
        'en' => ['flagclass' => 'es-flag-en', 'label' => 'English (America)'],
        'fr' => ['flagclass' => 'es-flag-fr', 'label' => 'Français (France)'],
        'ar' => ['flagclass' => 'es-flag-ar', 'label' => 'العربية (Saudi Arabia)'],
    ];

    $currentcode = local_elearning_system_get_active_language();

    $returnpath = $_SERVER['REQUEST_URI'] ?? '/';
    if ($returnpath !== '' && strpos($returnpath, 'lang=') !== false) {
        $returnpath = preg_replace('/([?&])lang=[^&]*(&)?/', '$1', $returnpath);
        $returnpath = rtrim($returnpath, '?&');
        if ($returnpath === '') {
            $returnpath = '/';
        }
    }

    $buildbutton = static function(string $langcode, array $langmeta, string $extra = '') use ($returnpath): string {
        $targeturl = new moodle_url($returnpath, [
            'lang' => $langcode,
        ]);

        return html_writer::link(
            $targeturl,
            '<span class="es-lang-flag ' . s($langmeta['flagclass']) . '" aria-hidden="true"></span>'
            . '<span class="es-lang-code">' . s(strtoupper($langcode)) . '</span>',
            [
                'class' => 'es-lang-btn ' . trim($extra),
                'title' => $langmeta['label'],
                'aria-label' => $langmeta['label'],
            ],
        );
    };

    $html = html_writer::start_div('es-lang-switcher');
    $html .= $buildbutton($currentcode, $supported[$currentcode], 'es-lang-btn-current');
    foreach ($supported as $langcode => $langmeta) {
        if ($langcode === $currentcode) {
            continue;
        }
        $html .= $buildbutton($langcode, $langmeta, 'es-lang-btn-option');
    }
    $html .= html_writer::end_div();

    $html .= '<style>
        .es-lang-switcher {
            position: fixed;
            left: 14px;
            bottom: 14px;
            z-index: 9999;
            display: inline-flex;
            flex-direction: row;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid #d9dee8;
            border-radius: 999px;
            padding: 8px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.12);
        }
        .es-lang-btn {
            min-width: 58px;
            height: 44px;
            border-radius: 999px;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid transparent;
            transition: transform .15s ease, border-color .15s ease, background .15s ease;
            background: transparent;
            flex: 0 0 auto;
            padding: 3px 8px;
            line-height: 1;
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
        .es-lang-btn-option {
            opacity: 0.95;
        }
        .es-lang-btn-option:hover,
        .es-lang-btn-option:focus {
            opacity: 1;
        }
        .es-lang-flag {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-block;
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35);
        }
        .es-lang-code {
            display: block;
            margin-top: 2px;
            font-size: 0.68rem;
            font-weight: 700;
            color: #2f3b52;
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
        @media (max-width: 768px) {
            .es-lang-switcher {
                left: 10px;
                bottom: 10px;
                padding: 7px;
                gap: 6px;
            }
            .es-lang-btn {
                min-width: 52px;
                height: 40px;
            }
            .es-lang-flag {
                width: 22px;
                height: 22px;
            }
            .es-lang-code {
                font-size: 0.62rem;
            }
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
function local_elearning_system_get_product_purchase_status(int $userid, int $productid, moodle_database $DB): string {
    // Achat direct : l'étudiant a payé ce produit exactement.
    if ($DB->record_exists('elearning_orders', [
        'userid' => $userid,
        'productid' => $productid,
    ])) {
        return 'direct';
    }

    // Achat indirect : le produit est accessible via un bundle déjà acheté.
    if (function_exists('local_elearning_system_is_product_covered_by_purchase')
        && local_elearning_system_is_product_covered_by_purchase($userid, $productid, $DB)) {
        return 'bundle';
    }

    return 'none';
}