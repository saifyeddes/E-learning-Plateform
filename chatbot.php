<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');

$context = context_system::instance();
$PAGE->set_context($context);

header('Content-Type: application/json; charset=utf-8');

$message = trim(optional_param('message', '', PARAM_RAW_TRIMMED));
if (!confirm_sesskey()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'reply' => 'Session invalide. Rechargez la page puis reessayez.',
    ]);
    exit;
}

if ($message === '') {
    echo json_encode([
        'ok' => false,
        'reply' => 'Ecrivez votre question (prix, bundle, achat, checkout).',
    ]);
    exit;
}

global $DB, $USER, $SESSION;

/**
 * Normalize text for loose matching.
 *
 * @param string $text
 * @return string
 */
function local_elearning_system_chatbot_normalize(string $text): string {
    $text = core_text::strtolower(trim($text));
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    return trim((string)$text);
}

/**
 * Extract requested duration in months.
 *
 * @param string $normalized
 * @return int
 */
function local_elearning_system_chatbot_extract_months(string $normalized): int {
    $months = 1;
    if (preg_match('/\b(\d{1,2})\s*(mois|month|months)\b/', $normalized, $m)) {
        $months = (int)$m[1];
    }
    return max(1, min(24, $months));
}

/**
 * Return best matching product by token overlap.
 *
 * @param string $normalizedmessage
 * @param array $catalog
 * @param bool $needbundle
 * @return array|null
 */
function local_elearning_system_chatbot_match_product(string $normalizedmessage, array $catalog, bool $needbundle): ?array {
    $tokens = array_values(array_filter(explode(' ', $normalizedmessage)));
    $best = null;
    $bestscore = 0;

    foreach ($catalog as $item) {
        if ($needbundle && empty($item['isbundle'])) {
            continue;
        }
        if (!$needbundle && !empty($item['isbundle']) && strpos($normalizedmessage, 'bundle') === false) {
            // Keep bundles lower-priority unless user explicitly asks for bundle.
        }

        $name = $item['normalizedname'];
        $score = 0;

        foreach ($tokens as $token) {
            if (strlen($token) < 3) {
                continue;
            }
            if (strpos($name, $token) !== false) {
                $score += 2;
            }
        }

        if ($name !== '' && strpos($normalizedmessage, $name) !== false) {
            $score += 5;
        }

        if ($score > $bestscore) {
            $bestscore = $score;
            $best = $item;
        }
    }

    if ($bestscore <= 0) {
        return null;
    }

    return $best;
}

/**
 * Build contextual recommended commands.
 *
 * @param array $catalog
 * @param array|null $matched
 * @param moodle_database $DB
 * @param stdClass|null $user
 * @return array
 */
function local_elearning_system_chatbot_recommended_commands(array $catalog, ?array $matched, moodle_database $DB, ?stdClass $user): array {
    $suggestions = [];

    $userid = 0;
    if ($user && !empty($user->id) && isloggedin() && !isguestuser()) {
        $effectiveuserctx = local_elearning_system_get_effective_user_context((int)$user->id, $DB);
        $userid = (int)($effectiveuserctx['targetuserid'] ?? 0);
    }

    $chosenmath = null;
    $chosenfallback = null;
    foreach ($catalog as $item) {
        if (!empty($item['isbundle'])) {
            continue;
        }
        if ($matched && (int)$matched['id'] === (int)$item['id']) {
            continue;
        }
        if ($userid > 0 && local_elearning_system_is_product_covered_by_active_purchase($userid, (int)$item['id'], $DB)) {
            continue;
        }

        if ($chosenfallback === null) {
            $chosenfallback = $item;
        }
        if (strpos((string)$item['normalizedname'], 'math') !== false || strpos((string)$item['normalizedname'], 'mathem') !== false) {
            $chosenmath = $item;
            break;
        }
    }

    if ($chosenmath) {
        $suggestions[] = 'acheter ' . $chosenmath['name'] . ' pour 1 mois';
        $suggestions[] = 'prix ' . $chosenmath['name'];
    } else if ($chosenfallback) {
        $suggestions[] = 'acheter ' . $chosenfallback['name'] . ' pour 1 mois';
        $suggestions[] = 'prix ' . $chosenfallback['name'];
    }

    if ($matched) {
        $suggestions[] = 'acheter ' . $matched['name'] . ' pour 2 mois';
        $suggestions[] = 'checkout';
    } else {
        $suggestions[] = 'voir les bundles';
        $suggestions[] = 'checkout';
    }

    return array_values(array_unique(array_slice($suggestions, 0, 3)));
}

$records = $DB->get_records('elearning_products', null, 'id DESC');
$catalog = [];

foreach ($records as $r) {
    $price = !empty($r->price) ? (float)$r->price : 0.0;
    $saleprice = !empty($r->saleprice) ? (float)$r->saleprice : 0.0;
    $displayprice = $saleprice > 0 ? $saleprice : $price;
    $status = strtolower(trim((string)($r->status ?? '')));

    $rawtype = strtolower(trim((string)($r->type ?? '')));
    if ($displayprice <= 0) {
        $type = 'free';
    } else if (in_array($rawtype, ['paid', 'subscription', 'subscroiption', 'subcription', 'subscribe', 'premium'])) {
        $type = 'paid';
    } else {
        $type = 'free';
    }

    $isbundle = !empty($r->isbundle);
    if (!$isbundle && $type === 'paid' && $status !== 'publish') {
        continue;
    }

    $name = format_string($r->name);
    $catalog[] = [
        'id' => (int)$r->id,
        'name' => $name,
        'normalizedname' => local_elearning_system_chatbot_normalize($name),
        'price' => $displayprice,
        'isfree' => ($type === 'free'),
        'isbundle' => $isbundle,
    ];
}

$normalized = local_elearning_system_chatbot_normalize($message);
$months = local_elearning_system_chatbot_extract_months($normalized);

$ispriceintent = (bool)preg_match('/\b(prix|combien|cout|tarif|price)\b/', $normalized);
$isbuyintent = (bool)preg_match('/\b(acheter|buy|payer|checkout|commander|prendre|inscrire)\b/', $normalized);
$isbundleintent = (bool)preg_match('/\b(bundle|pack)\b/', $normalized);

$matched = local_elearning_system_chatbot_match_product($normalized, $catalog, $isbundleintent);

if (!$matched && $isbundleintent) {
    $bundlecandidates = array_values(array_filter($catalog, function(array $item): bool {
        return !empty($item['isbundle']);
    }));
    if (!empty($bundlecandidates)) {
        usort($bundlecandidates, function(array $a, array $b): int {
            return $a['price'] <=> $b['price'];
        });
        $matched = $bundlecandidates[0];
    }
}

if (!$matched && !$isbundleintent && (bool)preg_match('/\b(cours|course)\b/', $normalized)) {
    $coursecandidates = array_values(array_filter($catalog, function(array $item): bool {
        return empty($item['isbundle']);
    }));
    if (!empty($coursecandidates)) {
        usort($coursecandidates, function(array $a, array $b): int {
            return $a['price'] <=> $b['price'];
        });
        $matched = $coursecandidates[0];
    }
}

if (!$matched && !$ispriceintent && !$isbuyintent) {
    echo json_encode([
        'ok' => true,
        'reply' => 'Je peux vous aider sur: prix des cours/bundles, et achat direct (ex: acheter Physique pour 2 mois).',
        'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER),
        'showrating' => true,
    ]);
    exit;
}

if (!$matched) {
    echo json_encode([
        'ok' => true,
        'reply' => 'Je n ai pas trouve ce cours. Essayez avec le nom exact visible sur la page.',
        'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER),
        'showrating' => true,
    ]);
    exit;
}

if ($ispriceintent && !$isbuyintent) {
    if (!empty($matched['isfree'])) {
        $reply = $matched['name'] . ' est gratuit.';
    } else {
        $total = $matched['price'] * $months;
        $reply = $matched['name'] . ' coute ' . number_format($matched['price'], 2) . ' par mois. Pour ' . $months . ' mois: ' . number_format($total, 2) . '.';
    }

    echo json_encode([
        'ok' => true,
        'reply' => $reply,
        'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, $matched, $DB, $USER),
        'showrating' => true,
    ]);
    exit;
}

if ($isbuyintent) {
    if (isloggedin() && !isguestuser()) {
        $effectiveuserctx = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
        $targetuserid = (int)($effectiveuserctx['targetuserid'] ?? 0);

        if ($targetuserid > 0 && local_elearning_system_is_product_covered_by_active_purchase($targetuserid, (int)$matched['id'], $DB)) {
            echo json_encode([
                'ok' => true,
                'reply' => 'Le cours ' . $matched['name'] . ' est deja achete.',
                'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, $matched, $DB, $USER),
                'showrating' => true,
            ]);
            exit;
        }
    }

    if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
        $SESSION->local_elearning_system_cart = [];
    }
    local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

    $SESSION->local_elearning_system_cart[(int)$matched['id']] = [
        'qty' => 1,
        'durationmonths' => $months,
    ];

    echo json_encode([
        'ok' => true,
        'reply' => 'Parfait. ' . $matched['name'] . ' a ete ajoute pour ' . $months . ' mois. Redirection vers checkout...',
        'redirecturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
        'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, $matched, $DB, $USER),
        'showrating' => true,
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'reply' => 'Dites par exemple: prix Physique, ou acheter Physique pour 2 mois.',
    'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER),
    'showrating' => true,
]);
