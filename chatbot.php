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
 * Expand bilingual aliases to improve EN/FR course matching.
 *
 * @param string $normalized
 * @return string
 */
function local_elearning_system_chatbot_expand_aliases(string $normalized): string {
    $map = [
        'physics' => 'physique',
        'physic' => 'physique',
        'mathematics' => 'mathematique',
        'maths' => 'mathematique',
        'math' => 'mathematique',
        'courses' => 'cours',
        'course' => 'cours',
        'training' => 'formation',
        'trainings' => 'formations',
    ];

    $expanded = ' ' . $normalized . ' ';
    foreach ($map as $from => $to) {
        $expanded = preg_replace('/\\b' . preg_quote($from, '/') . '\\b/', $to, $expanded);
    }

    return trim(preg_replace('/\s+/', ' ', (string)$expanded));
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
    } else if (preg_match('/\b(acheter|achete|buy|purchase|enroll|inscrire|payer)\b.*?\b(\d{1,2})\b/', $normalized, $m)) {
        $months = (int)$m[2];
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
    $expandedmessage = local_elearning_system_chatbot_expand_aliases($normalizedmessage);
    $tokens = array_values(array_filter(explode(' ', $expandedmessage)));
    $stopwords = [
        'what', 'which', 'is', 'the', 'of', 'for', 'to', 'in', 'me', 'i', 'want', 'a',
        'how', 'much', 'price', 'prix', 'tarif', 'buy', 'purchase', 'enroll', 'acheter',
        'achete', 'inscrire', 'course', 'courses', 'cours', 'month', 'months', 'mois',
    ];
    $tokens = array_values(array_filter($tokens, function(string $token) use ($stopwords): bool {
        if (strlen($token) < 3) {
            return false;
        }
        return !in_array($token, $stopwords, true);
    }));
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
            if (strlen($token) < 2) {
                continue;
            }
            // Exact substring match
            if (strpos($name, $token) !== false) {
                $score += 2;
            }
            // Prefix match (for abbreviations like phys, math, etc)
            if (strlen($token) <= 5 && strpos($name, $token) === 0) {
                $score += 3;
            }
        }

        if ($name !== '' && (strpos($normalizedmessage, $name) !== false || strpos($expandedmessage, $name) !== false)) {
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

/**
 * Build contextual guide/help response for student questions.
 *
 * @param string $normalized
 * @param array $catalog
 * @return array
 */
function local_elearning_system_chatbot_build_guide_response(string $normalized, array $catalog): array {
    $months = local_elearning_system_chatbot_extract_months($normalized);
    $course = local_elearning_system_chatbot_guess_course_from_text($normalized);

    if (preg_match('/\b(facture|fature|facure|invoice|receipt|recu|justificatif)\b/', $normalized)) {
        return [
            'reply' => "Guide facture:\n1. Ecrivez: donne moi ma facture\n2. Si besoin, precisez le produit: facture Physique\n3. Cliquez sur Telecharger facture.",
            'suggestions' => ['donne moi ma facture', 'facture Physique', 'voir mes cours'],
        ];
    }

    if (preg_match('/\b(bundle|bundles|pack|packs)\b/', $normalized)) {
        return [
            'reply' => "Guide bundles:\n1. Ecrivez: voir les bundles\n2. Choisissez un bundle\n3. Ecrivez checkout pour payer.",
            'suggestions' => ['voir les bundles', 'prix Bundle 1', 'checkout'],
        ];
    }

    if (preg_match('/\b(mes cours|my courses|formations|inscrire|enroll|acheter|buy|purchase|payer|checkout)\b/', $normalized)) {
        if ($course !== null) {
            $buycmd = 'acheter ' . $course . ' pour ' . $months . ' mois';
            return [
                'reply' => "Guide achat inscription:\n1. Verifiez le prix: prix " . $course . "\n2. Ajoutez au panier: " . $buycmd . "\n3. Finalisez: checkout\n4. Apres paiement, verifiez: voir mes cours.",
                'suggestions' => ['prix ' . $course, $buycmd, 'checkout'],
            ];
        }

        return [
            'reply' => "Guide achat inscription:\n1. Demandez le prix: prix NomDuCours\n2. Ajoutez au panier: acheter NomDuCours pour 1 mois\n3. Finalisez: checkout\n4. Verifiez vos cours: voir mes cours.",
            'suggestions' => ['prix Mathematique', 'acheter Mathematique pour 1 mois', 'checkout'],
        ];
    }

    return [
        'reply' => "Commandes acceptees:\n1. Demander le prix d un cours\n2. Acheter un cours\n3. Voir mes cours\n4. Demander ma facture\n5. Voir les bundles\n6. Passer au paiement",
        'suggestions' => ['prix Physique', 'acheter Mathematique pour 3 mois', 'voir mes cours'],
    ];
}

/**
 * Call external LLM to classify user intent.
 *
 * @param string $message
 * @return array|null
 */
function local_elearning_system_call_llm_intent(string $message): ?array {
    $config = get_config('local_elearning_system');
    $enabled = !empty($config->llm_enabled);
    $provider = !empty($config->llm_provider) ? (string)$config->llm_provider : 'openai';
    $model = !empty($config->llm_model) ? (string)$config->llm_model : 'gpt-4o-mini';
    $endpoint = !empty($config->llm_endpoint) ? (string)$config->llm_endpoint : 'https://api.openai.com/v1/chat/completions';
    $apikey = !empty($config->llm_api_key) ? (string)$config->llm_api_key : '';
    $timeout = !empty($config->llm_timeout) ? (int)$config->llm_timeout : 8;

    if (!$enabled || $provider !== 'openai' || $apikey === '' || $endpoint === '') {
        return null;
    }

    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    $systemprompt = 'You are an intent classifier for a Moodle e-learning chatbot. '
        . 'Analyze the user sentence and return ONLY valid JSON with this exact shape: '
        . '{"intent":"...","confidence":0.0,"entities":{"course":null,"duration_months":null}}. '
        . 'Allowed intents are strictly: invoice_request, my_courses, price_request, purchase_course, checkout, bundles, help, forbidden_action, unknown. '
        . 'Use entities.course for a requested course name when possible. '
        . 'Use entities.duration_months as an integer when a duration is provided. '
        . 'Examples: "how much is Science?" => price_request; "enroll me in Math for 5 months" => purchase_course; '
        . '"Je voudrais consulter les formations que j ai suivies jusqu a present" => my_courses; '
        . '"Combien dois-je payer pour suivre Science ?" => price_request; '
        . '"Inscris-moi au cours Science pour une duree de 3 mois" => purchase_course; '
        . '"finaliser ma commande" => checkout; "show me the packs" => bundles; '
        . '"Peux-tu me montrer les formations que j ai obtenues recemment ?" => my_courses; '
        . '"affiche moi mes apprentissages acquis precedemment" => my_courses; '
        . '"give me another user invoice" => forbidden_action; "what can you do?" => help. '
        . 'Do not include explanations, markdown, or extra keys.';

    $payload = [
        'model' => $model,
        'temperature' => 0.0,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $systemprompt],
            ['role' => 'user', 'content' => $message],
        ],
    ];

    $curl = new curl();
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apikey,
    ];

    $response = $curl->post($endpoint, json_encode($payload), [
        'CURLOPT_HTTPHEADER' => $headers,
        'CURLOPT_TIMEOUT' => max(3, $timeout),
    ]);

    if (!is_string($response) || $response === '') {
        return null;
    }

    error_log('LLM response: ' . $response);

    $apiresponse = json_decode($response, true);
    if (!is_array($apiresponse)) {
        return null;
    }

    if (!empty($apiresponse['error'])) {
        error_log('LLM API error: ' . json_encode($apiresponse['error']));
        return null;
    }

    $content = $apiresponse['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || $content === '') {
        return null;
    }

    $result = json_decode($content, true);
    if (!is_array($result)) {
        return null;
    }

    $allowed = ['invoice_request', 'my_courses', 'price_request', 'purchase_course', 'checkout', 'bundles', 'help', 'forbidden_action', 'unknown'];
    $intent = strtolower(trim((string)($result['intent'] ?? 'unknown')));
    if (!in_array($intent, $allowed, true)) {
        $intent = 'unknown';
    }

    $confidence = (float)($result['confidence'] ?? 0.0);
    $confidence = max(0.0, min(1.0, $confidence));

    $entities = ['course' => null, 'duration_months' => null];
    if (isset($result['entities']) && is_array($result['entities'])) {
        if (array_key_exists('course', $result['entities'])) {
            $entities['course'] = trim((string)$result['entities']['course']);
            if ($entities['course'] === '') {
                $entities['course'] = null;
            }
        }

        if (array_key_exists('duration_months', $result['entities'])) {
            $entities['duration_months'] = max(1, min(24, (int)$result['entities']['duration_months']));
        } else if (array_key_exists('durationmonths', $result['entities'])) {
            $entities['duration_months'] = max(1, min(24, (int)$result['entities']['durationmonths']));
        }
    }

    return [
        'intent' => $intent,
        'confidence' => $confidence,
        'entities' => $entities,
    ];
}

/**
 * Resolve intent with deterministic regex fallback.
 *
 * @param string $normalized
 * @return array
 */
function local_elearning_system_chatbot_resolve_regex_intent(string $normalized): array {
    $patterns = [
        'forbidden_action' => '/\b(supprime|delete|autre utilisateur|another user|change mon prix|price to zero|ignore les regles|ignore the rules|donne acces|give access|give me another user invoice|set my price to zero)\b/',
        'help' => '/\b(bonjour|salut|aide|que peux tu faire|what can you do|comment signin|help|comment|how to)\b/',
        'checkout' => '/\b(checkout|finaliser ma commande|finaliser|ouvrir le panier|panier|passer au paiement|payer maintenant|paiement|open cart|proceed to checkout)\b/',
        'bundles' => '/\b(voir les bundles|show bundles|available bundles|show me the packs|show me the bundles|montrer les packs|montre moi les packs|quels packs sont disponibles|quels bundles sont disponibles|bundle|bundles|pack|packs)\b/',
        'my_courses' => '/(mes cours|cours achet[eé]s|formations achet[eé]es|formations obtenues|formations acquises|formations suivies|formations que j.?ai suivies|apprentissages acquis|mes apprentissages|cours obtenus|cours suivis|formations que j.?ai obtenues|cours auxquels je suis inscrit|historique d.?apprentissage|parcours suivi|ce que j.?ai achet[eé]|what i bought|courses i bought|my courses|show my courses|show me my courses|my enrollments|display my courses)/iu',
        'invoice_request' => '/\b(facture|fature|facure|recu|reçu|justificatif|invoice|receipt|i need my invoice|payment proof|proof of payment|document de paiement|document paiement|donner moi facture|donne moi facture)\b/',
        'purchase_course' => '/\b(acheter|achete|buy|purchase|enroll|inscrire|m.?inscrire|inscris.?moi|inscription|s.?inscrire|inscrivez.?moi|i want to buy|buy me|enroll me in|sign me up for|dur[eé]e)\b/',
        'price_request' => '/\b(prix|tarif|co[uû]t|cout|coute|combien coute|combien dois.?je payer|payer pour suivre|quel est le co[uû]t|how much is|how much|what is the price of|price)\b/',
    ];

    foreach ($patterns as $intent => $pattern) {
        if (preg_match($pattern, $normalized)) {
            $course = null;
            $duration = null;

            if ($intent === 'price_request' || $intent === 'purchase_course') {
                $course = local_elearning_system_chatbot_guess_course_from_text($normalized);
                if ($course === null) {
                    $course = local_elearning_system_chatbot_guess_course_from_text(preg_replace('/\b(prix|tarif|co[uû]te|cout|combien coute|how much is|how much|what is the price of|price|acheter|achete|buy|purchase|enroll|inscrire|i want to buy|buy me|enroll me in|sign me up for)\b/i', ' ', $normalized));
                }
            }

            if (preg_match('/\b(\d{1,2})\b/', $normalized, $durationmatch)) {
                $duration = max(1, min(24, (int)$durationmatch[1]));
            }

            return [
                'intent' => $intent,
                'confidence' => 0.9,
                'entities' => [
                    'course' => $course,
                    'duration_months' => $duration,
                ],
            ];
        }
    }

    return [
        'intent' => 'unknown',
        'confidence' => 0.0,
        'entities' => [
            'course' => null,
            'duration_months' => null,
        ],
    ];
}

/**
 * Guess a course name from free text using the loaded catalog.
 *
 * @param string $normalized
 * @return string|null
 */
function local_elearning_system_chatbot_guess_course_from_text(string $normalized): ?string {
    global $DB;

    $records = $DB->get_records('elearning_products', null, 'id DESC', 'id,name,isbundle');
    if (empty($records)) {
        return null;
    }

    $catalog = [];
    foreach ($records as $record) {
        if (!empty($record->isbundle)) {
            continue;
        }

        $name = format_string((string)$record->name);
        $catalog[] = [
            'id' => (int)$record->id,
            'name' => $name,
            'normalizedname' => local_elearning_system_chatbot_normalize($name),
            'price' => 0,
            'isfree' => true,
            'isbundle' => false,
        ];
    }

    $expanded = local_elearning_system_chatbot_expand_aliases($normalized);
    $matched = local_elearning_system_chatbot_match_product($expanded, $catalog, false);
    if ($matched && !empty($matched['name'])) {
        return (string)$matched['name'];
    }

    return null;
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


$pluginconfig = get_config('local_elearning_system');
$thresholdraw = $pluginconfig->llm_confidence_threshold ?? ($pluginconfig->llmconfidence ?? 0.60);
$llmthreshold = is_numeric($thresholdraw) ? (float)$thresholdraw : 0.60;
$llmthreshold = max(0.0, min(1.0, $llmthreshold));

$fallbackparsed = local_elearning_system_chatbot_resolve_regex_intent($normalized);
$intentdata = null;
$resolvedparsed = $fallbackparsed;

if (!empty($pluginconfig->llm_enabled)) {
    $intentdata = local_elearning_system_call_llm_intent($message);
    error_log('LLM intent: ' . json_encode($intentdata));

    if ($intentdata && isset($intentdata['confidence']) && (float)$intentdata['confidence'] >= $llmthreshold) {
        $resolvedparsed = [
            'intent' => (string)$intentdata['intent'],
            'confidence' => (float)$intentdata['confidence'],
            'entities' => is_array($intentdata['entities']) ? $intentdata['entities'] : [],
        ];
    }
}

$resolvedintent = (string)($resolvedparsed['intent'] ?? 'unknown');
$resolvedentities = is_array($resolvedparsed['entities'] ?? null) ? $resolvedparsed['entities'] : [];

if (!empty($resolvedentities['duration_months'])) {
    $months = max(1, min(24, (int)$resolvedentities['duration_months']));
} else if (!empty($resolvedentities['durationmonths'])) {
    $months = max(1, min(24, (int)$resolvedentities['durationmonths']));
}

$llmproductnormalized = '';
if (!empty($resolvedentities['course'])) {
    $llmproductnormalized = local_elearning_system_chatbot_normalize((string)$resolvedentities['course']);
}

$ispriceintent = ($resolvedintent === 'price_request');
$isbuyintent = ($resolvedintent === 'purchase_course');
$isviewintent = ($resolvedintent === 'my_courses');
$isbundleintent = ($resolvedintent === 'bundles');
$ischeckoutintent = ($resolvedintent === 'checkout');
$isinvoiceintent = ($resolvedintent === 'invoice_request');

switch ($resolvedintent) {
    case 'forbidden_action':
        echo json_encode([
            'ok' => true,
            'reply' => 'Je ne peux pas effectuer cette action. Vous pouvez consulter vos cours, demander votre facture ou passer au paiement.',
            'suggestions' => [
                'voir mes cours',
                'donne moi ma facture',
                'checkout',
            ],
            'showrating' => false,
        ]);
        exit;

    case 'help':
        $guide = local_elearning_system_chatbot_build_guide_response($normalized, $catalog);
        echo json_encode([
            'ok' => true,
            'reply' => (string)$guide['reply'],
            'suggestions' => is_array($guide['suggestions']) ? $guide['suggestions'] : ['prix Physique', 'acheter Mathematique pour 1 mois', 'voir mes cours'],
            'showrating' => false,
        ]);
        exit;

    case 'checkout':
        echo json_encode([
            'ok' => true,
            'reply' => 'Redirection vers checkout...',
            'redirecturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
            'suggestions' => [
                'voir mes cours',
                'donne moi ma facture',
                'voir les bundles',
            ],
            'showrating' => false,
        ]);
        exit;

    case 'bundles':
        $bundlecandidates = array_values(array_filter($catalog, function(array $item): bool {
            return !empty($item['isbundle']);
        }));

        if (empty($bundlecandidates)) {
            echo json_encode([
                'ok' => true,
                'reply' => 'Aucun bundle disponible pour le moment.',
                'suggestions' => [
                    'voir mes cours',
                    'checkout',
                ],
                'showrating' => false,
            ]);
            exit;
        }

        $bundlelines = [];
        $index = 1;
        foreach ($bundlecandidates as $bundle) {
            $bundlelines[] = $index . '. ' . format_string((string)$bundle['name']);
            $index++;
            if ($index > 5) {
                break;
            }
        }

        echo json_encode([
            'ok' => true,
            'reply' => "Bundles disponibles:\n" . implode("\n", $bundlelines),
            'suggestions' => [
                'checkout',
                'voir mes cours',
                'prix ' . (string)$bundlecandidates[0]['name'],
            ],
            'showrating' => false,
        ]);
        exit;
}

$primaryquery = ($llmproductnormalized !== '') ? $llmproductnormalized : $normalized;
$expandedprimaryquery = local_elearning_system_chatbot_expand_aliases($primaryquery);
$matched = local_elearning_system_chatbot_match_product($expandedprimaryquery, $catalog, $isbundleintent);

if (!$matched && $llmproductnormalized !== '' && $llmproductnormalized !== $normalized) {
    $matched = local_elearning_system_chatbot_match_product($normalized, $catalog, $isbundleintent);
}

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

if ($ischeckoutintent) {
    echo json_encode([
        'ok' => true,
        'reply' => 'Redirection vers checkout...',
        'redirecturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
        'suggestions' => [
            'voir mes cours',
            'donne moi ma facture',
            'voir les bundles',
        ],
        'showrating' => false,
    ]);
    exit;
}

if ($isviewintent && isloggedin() && !isguestuser()) {
    $userctx = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
    $targetuserid = (int)($userctx['targetuserid'] ?? 0);

    if ($targetuserid > 0 && $DB->get_manager()->table_exists('elearning_orders')) {
        $ordercolumns = $DB->get_columns('elearning_orders');
        $durationselect = isset($ordercolumns['durationmonths']) ? 'o.durationmonths AS durationmonths' : '1 AS durationmonths';
        $expireselect = isset($ordercolumns['expiresat']) ? 'o.expiresat AS expiresat' : '0 AS expiresat';

        $orders = $DB->get_records_sql(
            "SELECT o.id, o.timecreated, {$durationselect}, {$expireselect}, p.name AS productname
                FROM {elearning_orders} o
           LEFT JOIN {elearning_products} p ON p.id = o.productid
               WHERE o.userid = ?
            ORDER BY o.id DESC",
            [$targetuserid]
        );

        if (!empty($orders)) {
            $listlines = [];
            $index = 1;
            foreach ($orders as $o) {
                $productname = !empty($o->productname) ? format_string((string)$o->productname) : 'Cours';
                $months = max(1, (int)($o->durationmonths ?? 1));
                $listlines[] = $index . '. ' . $productname . ' (' . $months . ' mois)';
                $index++;
            }

            echo json_encode([
                'ok' => true,
                'reply' => "Vous avez " . count($orders) . " cours achete(s):\n" . implode("\n", $listlines),
                'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER),
                'showrating' => false,
            ]);
            exit;
        }
    }

    echo json_encode([
        'ok' => true,
        'reply' => 'Vous n avez pas de cours achetes encore.',
        'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER),
        'showrating' => false,
    ]);
    exit;
}

if ($isinvoiceintent) {
    if (!isloggedin() || isguestuser()) {
        echo json_encode([
            'ok' => true,
            'reply' => 'Connectez vous pour recuperer votre facture.',
            'suggestions' => [
                'se connecter',
                'voir mes cours',
                'checkout',
            ],
            'showrating' => false,
        ]);
        exit;
    }

    $userctx = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
    $targetuserid = (int)($userctx['targetuserid'] ?? 0);

    if ($targetuserid <= 0 || !$DB->get_manager()->table_exists('elearning_orders')) {
        echo json_encode([
            'ok' => true,
            'reply' => 'Je n ai pas trouve de facture pour votre compte.',
            'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, $matched, $DB, $USER),
            'showrating' => false,
        ]);
        exit;
    }

    $usematchedinvoice = false;
    $expandednormalized = local_elearning_system_chatbot_expand_aliases($normalized);
    if ($matched && !empty($matched['name'])) {
        $matchedname = local_elearning_system_chatbot_normalize((string)$matched['name']);
        if ($matchedname !== '' && (strpos($normalized, $matchedname) !== false || strpos($expandednormalized, $matchedname) !== false)) {
            $usematchedinvoice = true;
        }
    }

    if (!$usematchedinvoice) {
        $recentorders = $DB->get_records_sql(
            "SELECT o.id, o.timecreated, o.productid, p.name AS productname
               FROM {elearning_orders} o
          LEFT JOIN {elearning_products} p ON p.id = o.productid
              WHERE o.userid = :userid
           ORDER BY o.id DESC",
            ['userid' => $targetuserid],
            0,
            10
        );

        if (count($recentorders) > 1) {
            $suggestions = [];
            foreach ($recentorders as $ro) {
                $pname = !empty($ro->productname) ? format_string((string)$ro->productname) : '';
                if ($pname !== '') {
                    $suggestions[] = 'facture ' . $pname;
                }
                if (count($suggestions) >= 3) {
                    break;
                }
            }
            if (empty($suggestions)) {
                $suggestions = ['voir mes cours', 'checkout'];
            }

            echo json_encode([
                'ok' => true,
                'reply' => 'Pour quel produit voulez-vous la facture ? Ecrivez par exemple: facture Physique.',
                'suggestions' => $suggestions,
                'showrating' => false,
            ]);
            exit;
        }
    }

    $params = ['userid' => $targetuserid];
    $sql = "SELECT o.id, o.timecreated, o.productid, p.name AS productname, COALESCE(p.isbundle, 0) AS isbundle
              FROM {elearning_orders} o
         LEFT JOIN {elearning_products} p ON p.id = o.productid
             WHERE o.userid = :userid";

    if ($usematchedinvoice && $matched) {
        $sql .= ' AND o.productid = :productid';
        $params['productid'] = (int)$matched['id'];
    }

    if ($usematchedinvoice) {
        $sql .= ' ORDER BY o.id DESC';
    } else {
        $sql .= ' ORDER BY COALESCE(p.isbundle, 0) ASC, o.id DESC';
    }
    $orders = $DB->get_records_sql($sql, $params, 0, 1);

    if (empty($orders)) {
        echo json_encode([
            'ok' => true,
            'reply' => 'Aucun achat trouve pour generer une facture.',
            'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, $matched, $DB, $USER),
            'showrating' => false,
        ]);
        exit;
    }

    $order = reset($orders);
    $productname = !empty($order->productname) ? format_string((string)$order->productname) : 'votre cours';
    $invoiceurl = (new moodle_url('/local/elearning_system/invoice.php', [
        'id' => (int)$order->id,
        'pdf' => 1,
    ]))->out(false);

    $invoicereply = 'Votre facture est prete.';
    if ($usematchedinvoice) {
        $invoicereply = 'Votre facture est prete pour ' . $productname . '.';
    }

    echo json_encode([
        'ok' => true,
        'reply' => $invoicereply,
        'invoiceurl' => $invoiceurl,
        'invoicelabel' => 'Telecharger facture',
        'suggestions' => [
            'voir mes cours',
            'prix ' . $productname,
            'checkout',
        ],
        'showrating' => false,
    ]);
    exit;
}

if (!$matched && !$ispriceintent && !$isbuyintent && !$isviewintent) {
    echo json_encode([
        'ok' => true,
        'reply' => "Commandes acceptees:\n1. prix Physique\n2. acheter Math 5 mois\n3. voir mes cours\n4. donne moi ma facture\n5. buy Science 3\n6. checkout",
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
