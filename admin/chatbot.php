<?php

require('../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);

header('Content-Type: application/json; charset=utf-8');

if (!confirm_sesskey()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'reply' => 'Session invalide. Rechargez la page puis reessayez.',
    ]);
    exit;
}

$message = trim(optional_param('message', '', PARAM_RAW_TRIMMED));
if ($message === '') {
    echo json_encode([
        'ok' => false,
        'reply' => 'Ecrivez une demande admin. Exemple: saif cours physique, ou facture saif pour cours physique.',
        'suggestions' => [
            'saif cours physique',
            'facture saif pour cours physique',
            'donne moi facture saif',
        ],
    ]);
    exit;
}

global $DB;

/**
 * Normalize free text.
 *
 * @param string $text
 * @return string
 */
function local_elearning_system_admin_chatbot_normalize(string $text): string {
    $text = core_text::strtolower(trim($text));
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    return trim((string)$text);
}

/**
 * Guess best user from admin message.
 *
 * @param string $normalized
 * @param moodle_database $DB
 * @return stdClass|null
 */
function local_elearning_system_admin_chatbot_find_user(string $normalized, moodle_database $DB): ?stdClass {
    $tokens = array_values(array_filter(explode(' ', $normalized), function(string $token): bool {
        return strlen($token) >= 3;
    }));

    if (empty($tokens)) {
        return null;
    }

    $fields = 'id,firstname,lastname,email,username';
    $best = null;
    $bestscore = 0;

    foreach ($tokens as $token) {
        $like = '%' . $DB->sql_like_escape($token) . '%';
        $sql = "SELECT {$fields}
                  FROM {user}
                 WHERE deleted = 0
                   AND suspended = 0
                   AND (" . $DB->sql_like('LOWER(firstname)', ':tfirstname') . "
                        OR " . $DB->sql_like('LOWER(lastname)', ':tlastname') . "
                        OR " . $DB->sql_like('LOWER(email)', ':temail') . "
                        OR " . $DB->sql_like('LOWER(username)', ':tusername') . ")";

        $params = [
            'tfirstname' => core_text::strtolower($like),
            'tlastname' => core_text::strtolower($like),
            'temail' => core_text::strtolower($like),
            'tusername' => core_text::strtolower($like),
        ];

        $records = $DB->get_records_sql($sql, $params, 0, 20);
        foreach ($records as $user) {
            $fullname = local_elearning_system_admin_chatbot_normalize(trim((string)$user->firstname . ' ' . (string)$user->lastname));
            $score = 0;
            foreach ($tokens as $tok) {
                if (strpos($fullname, $tok) !== false) {
                    $score += 3;
                }
                if (strpos(local_elearning_system_admin_chatbot_normalize((string)$user->email), $tok) !== false) {
                    $score += 2;
                }
                if (strpos(local_elearning_system_admin_chatbot_normalize((string)$user->username), $tok) !== false) {
                    $score += 2;
                }
            }

            if ($score > $bestscore) {
                $bestscore = $score;
                $best = $user;
            }
        }
    }

    return $best;
}

/**
 * Guess best product from admin message.
 *
 * @param string $normalized
 * @param moodle_database $DB
 * @return stdClass|null
 */
function local_elearning_system_admin_chatbot_find_product(string $normalized, moodle_database $DB): ?stdClass {
    $records = $DB->get_records('elearning_products', null, 'id DESC', 'id,name,isbundle');
    if (empty($records)) {
        return null;
    }

    $tokens = array_values(array_filter(explode(' ', $normalized), function(string $token): bool {
        return strlen($token) >= 3;
    }));

    $best = null;
    $bestscore = 0;
    foreach ($records as $product) {
        $name = local_elearning_system_admin_chatbot_normalize(format_string((string)$product->name));
        $score = 0;
        foreach ($tokens as $token) {
            if (strpos($name, $token) !== false) {
                $score += 2;
            }
        }
        if ($name !== '' && strpos($normalized, $name) !== false) {
            $score += 5;
        }

        if ($score > $bestscore) {
            $bestscore = $score;
            $best = $product;
        }
    }

    if ($bestscore <= 0) {
        return null;
    }

    return $best;
}

/**
 * Build recommended admin commands.
 *
 * @param string $usernamehint
 * @param string $producthint
 * @return array
 */
function local_elearning_system_admin_chatbot_suggestions(string $usernamehint = 'saif', string $producthint = 'physique'): array {
    return [
        $usernamehint . ' cours ' . $producthint,
        'facture ' . $usernamehint . ' pour cours ' . $producthint,
        'donne moi facture ' . $usernamehint,
    ];
}

$normalized = local_elearning_system_admin_chatbot_normalize($message);
$isinvoiceintent = (bool)preg_match('/\b(facture|invoice|pdf|download|telecharger)\b/', $normalized);
$islistintent = (bool)preg_match('/\b(liste|list|tous|all|voir|show|affiche|display)\b/', $normalized);

$user = local_elearning_system_admin_chatbot_find_user($normalized, $DB);
$product = local_elearning_system_admin_chatbot_find_product($normalized, $DB);

if (!$user) {
    echo json_encode([
        'ok' => true,
        'reply' => 'Je n ai pas trouve l etudiant. Essayez avec un nom, email ou username exact.',
        'suggestions' => local_elearning_system_admin_chatbot_suggestions(),
    ]);
    exit;
}

$ordercolumns = [];
if ($DB->get_manager()->table_exists('elearning_orders')) {
    $ordercolumns = $DB->get_columns('elearning_orders');
}

if (empty($ordercolumns)) {
    echo json_encode([
        'ok' => true,
        'reply' => 'Table des commandes indisponible.',
        'suggestions' => local_elearning_system_admin_chatbot_suggestions($user->username ?? 'etudiant'),
    ]);
    exit;
}

if ($islistintent && !$product) {
    $durationselect = isset($ordercolumns['durationmonths']) ? 'o.durationmonths AS durationmonths' : '1 AS durationmonths';
    $expireselect = isset($ordercolumns['expiresat']) ? 'o.expiresat AS expiresat' : '0 AS expiresat';

    $allorders = $DB->get_records_sql(
        "SELECT o.id, o.timecreated, o.amount, {$durationselect}, {$expireselect}, p.name AS productname
            FROM {elearning_orders} o
       LEFT JOIN {elearning_products} p ON p.id = o.productid
           WHERE o.userid = ?
        ORDER BY o.id DESC",
        [(int)$user->id]
    );

    if (empty($allorders)) {
        echo json_encode([
            'ok' => true,
            'reply' => 'Aucune commande pour cet etudiant.',
            'suggestions' => local_elearning_system_admin_chatbot_suggestions((string)$user->username),
        ]);
        exit;
    }

    $studentname = trim((string)$user->firstname . ' ' . (string)$user->lastname);
    if ($studentname === '') {
        $studentname = (string)$user->username;
    }

    $listlines = [];
    foreach ($allorders as $o) {
        $productname = !empty($o->productname) ? format_string((string)$o->productname) : 'Cours';
        $months = max(1, (int)($o->durationmonths ?? 1));
        $purchasedate = userdate((int)$o->timecreated, get_string('strftimedate', 'core_langconfig'));
        $listlines[] = '- ' . $productname . ' (' . $months . ' mois, achete le ' . $purchasedate . ')';
    }

    $reply = $studentname . ' a ' . count($allorders) . ' commande(s):\n' . implode('\n', $listlines);
    echo json_encode([
        'ok' => true,
        'reply' => $reply,
        'suggestions' => local_elearning_system_admin_chatbot_suggestions((string)$user->username),
    ]);
    exit;
}

$durationselect = isset($ordercolumns['durationmonths']) ? 'o.durationmonths AS durationmonths' : '1 AS durationmonths';
$expireselect = isset($ordercolumns['expiresat']) ? 'o.expiresat AS expiresat' : '0 AS expiresat';

$params = ['userid' => (int)$user->id];
$sql = "SELECT o.id, o.userid, o.productid, o.timecreated, o.amount,
               {$durationselect},
               {$expireselect},
               p.name AS productname
          FROM {elearning_orders} o
     LEFT JOIN {elearning_products} p ON p.id = o.productid
         WHERE o.userid = :userid";

if ($product) {
    $sql .= ' AND o.productid = :productid';
    $params['productid'] = (int)$product->id;
}

$sql .= ' ORDER BY o.id DESC';
$orders = $DB->get_records_sql($sql, $params);

if (empty($orders)) {
    $studentname = trim((string)$user->firstname . ' ' . (string)$user->lastname);
    if ($studentname === '') {
        $studentname = (string)$user->username;
    }

    $productname = $product ? format_string((string)$product->name) : 'ce cours';
    echo json_encode([
        'ok' => true,
        'reply' => $studentname . ' n a pas d achat pour ' . $productname . '.',
        'suggestions' => local_elearning_system_admin_chatbot_suggestions((string)$user->username, $product ? format_string((string)$product->name) : 'physique'),
    ]);
    exit;
}

$order = reset($orders);
$durationmonths = max(1, (int)($order->durationmonths ?? 1));
$expiresat = (int)($order->expiresat ?? 0);
if ($expiresat <= 0) {
    $expiresat = local_elearning_system_get_order_expiresat((object)[
        'timecreated' => (int)$order->timecreated,
        'durationmonths' => $durationmonths,
    ]);
}

$studentname = trim((string)$user->firstname . ' ' . (string)$user->lastname);
if ($studentname === '') {
    $studentname = (string)$user->username;
}

$productname = !empty($order->productname) ? format_string((string)$order->productname) : 'Cours';
$purchasedate = userdate((int)$order->timecreated);
$expiredate = userdate($expiresat);

$invoiceurl = (new moodle_url('/local/elearning_system/invoice.php', [
    'id' => (int)$order->id,
    'pdf' => 1,
]))->out(false);

if ($isinvoiceintent) {
    echo json_encode([
        'ok' => true,
        'reply' => 'Facture prete pour ' . $studentname . ' - ' . $productname . '. Duree: ' . $durationmonths . ' mois.',
        'invoiceurl' => $invoiceurl,
        'invoicelabel' => 'Telecharger facture',
        'suggestions' => [
            $studentname . ' cours ' . $productname,
            'facture ' . $studentname . ' pour cours ' . $productname,
            'checkout',
        ],
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'reply' => $studentname . ' a achete ' . $productname . ' le ' . $purchasedate . ', expire le ' . $expiredate . ' (' . $durationmonths . ' mois).',
    'invoiceurl' => $invoiceurl,
    'invoicelabel' => 'Telecharger facture',
    'suggestions' => [
        'facture ' . $studentname . ' pour cours ' . $productname,
        $studentname . ' cours ' . $productname,
        'donne moi facture ' . $studentname,
    ],
]);
