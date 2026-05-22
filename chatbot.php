<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');

$context = context_system::instance();
$PAGE->set_context($context);

header('Content-Type: application/json; charset=utf-8');

$message = trim(optional_param('message', '', PARAM_RAW_TRIMMED));
$chatlang = local_elearning_system_chatbot_conversation_lang($message);
if (!confirm_sesskey()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'reply' => local_elearning_system_chatbot_t('invalid_session', [], $chatlang),
    ]);
    exit;
}

if ($message === '') {
    echo json_encode([
        'ok' => false,
        'reply' => local_elearning_system_chatbot_t('empty_message', [], $chatlang),
    ]);
    exit;
}

global $DB, $USER, $SESSION;
function local_elearning_system_chatbot_site_lang(): string {
    $lang = current_language();

    if (strpos($lang, 'ar') === 0) {
        return 'ar';
    }

    if (strpos($lang, 'en') === 0) {
        return 'en';
    }

    return 'fr';
}

function local_elearning_system_chatbot_detect_message_lang(string $message): string {
    $message = trim($message);

    if ($message === '') {
        return '';
    }

    // Arabic characters.
    if (preg_match('/\p{Arabic}/u', $message)) {
        return 'ar';
    }

    $m = function_exists('mb_strtolower')
        ? mb_strtolower($message, 'UTF-8')
        : strtolower($message);

    // French keywords.
    if (preg_match('/\b(bonjour|salut|merci|cours|prix|acheter|achat|facture|panier|paiement|payer|mes cours|formation|formations)\b/u', $m)) {
        return 'fr';
    }

    // English keywords.
    if (preg_match('/\b(hello|hi|thanks|course|courses|price|buy|purchase|invoice|cart|checkout|payment|my courses|bundle|bundles)\b/u', $m)) {
        return 'en';
    }

    return '';
}

function local_elearning_system_chatbot_suggestions(?string $lang = null): array {
    if ($lang === null) {
        $lang = local_elearning_system_chatbot_conversation_lang();
    }

    $suggestions = [
        'fr' => [
            'Quels cours sont disponibles ?',
            'Comment acheter un cours ?',
            'Comment récupérer ma facture ?',
        ],
        'en' => [
            'What courses are available?',
            'How can I buy a course?',
            'How can I download my invoice?',
        ],
        'ar' => [
            'ما هي الدورات المتاحة؟',
            'كيف أشتري دورة؟',
            'كيف أحصل على فاتورتي؟',
        ],
    ];

    return $suggestions[$lang] ?? $suggestions['fr'];
}

function local_elearning_system_chatbot_conversation_lang(string $message = ''): string {
    global $SESSION;

    $detected = local_elearning_system_chatbot_detect_message_lang($message);

    if ($detected !== '') {
        $SESSION->local_elearning_system_chatbot_lang = $detected;
        return $detected;
    }

    if (!empty($SESSION->local_elearning_system_chatbot_lang)) {
        return (string)$SESSION->local_elearning_system_chatbot_lang;
    }

    return local_elearning_system_chatbot_site_lang();
}

function local_elearning_system_chatbot_t(string $key, array $params = [], ?string $lang = null): string {
    if ($lang === null) {
        $lang = local_elearning_system_chatbot_conversation_lang();
    }

    $strings = [
        'fr' => [
            'invalid_session' => 'Session invalide. Rechargez la page puis réessayez.',
            'empty_message' => 'Écrivez votre question : prix, cours, bundle, achat, checkout ou facture.',
            'help' => "Bonjour 👋 Je suis votre assistant IA DOUROUSS E-Learning.\n\nJe peux vous aider à :\n1. Découvrir les cours disponibles\n2. Connaître les prix des formations\n3. Acheter un cours\n4. Accéder au paiement\n5. Retrouver vos cours achetés\n6. Récupérer votre facture",
            'no_courses' => "Aucun cours n'est disponible pour le moment.",
            'login_required_courses' => "Connectez-vous pour voir vos cours.",
            'login_required_invoice' => "Connectez-vous pour récupérer votre facture.",
            'course_not_found' => "Je n’ai pas trouvé ce cours. Essayez avec le nom exact visible sur la page.",
            'forbidden' => "Je ne peux pas effectuer cette action. Vous pouvez consulter vos cours, demander votre facture ou passer au paiement.",
            'fallback' => "Je peux vous aider à consulter les cours, connaître les prix, acheter une formation, accéder au paiement ou récupérer une facture.",
            'checkout_redirect' => "Redirection vers checkout...",
            'already_purchased' => "Le cours {{course}} est déjà acheté.",
            'added_to_cart' => "Parfait. {{course}} a été ajouté pour {{months}} mois. Redirection vers checkout...",
            'free_price' => "{{course}} est gratuit.",
            'paid_price' => "{{course}} coûte {{price}} par mois. Pour {{months}} mois : {{total}}.",
            'available_courses' => "Voici les cours disponibles sur la plateforme :",
            'bundles_available' => "Des bundles sont aussi disponibles. Vous pouvez écrire : voir les bundles.",
            'no_bundles' => "Aucun bundle disponible pour le moment.",
            'bundles_title' => "Bundles disponibles :",
            'invoice_ready' => "Votre facture est prête pour : {{product}}.",
            'unpurchased_login_required' => "Connectez-vous pour voir les cours que vous n’avez pas encore achetés.",
'student_not_found' => "Je n’ai pas pu identifier votre compte étudiant.",
'all_courses_purchased' => "Vous avez déjà acheté tous les cours disponibles actuellement.",
'no_purchased_courses' => "Vous n’avez pas encore de cours achetés.",
'no_invoice_found' => "Je n’ai pas trouvé de facture pour votre compte.",
'no_purchase_for_invoice' => "Aucun achat trouvé pour générer une facture.",
            'download_invoice' => "Cliquez sur le bouton ci-dessous pour télécharger la facture PDF.",
        ],

        'en' => [
            'unpurchased_login_required' => "Please log in to view the courses you have not purchased yet.",
'student_not_found' => "I could not identify your student account.",
'all_courses_purchased' => "You have already purchased all available courses.",
'no_purchased_courses' => "You have not purchased any courses yet.",
'no_invoice_found' => "I could not find an invoice for your account.",
'no_purchase_for_invoice' => "No purchase was found to generate an invoice.",
            'invalid_session' => 'Invalid session. Please reload the page and try again.',
            'empty_message' => 'Write your question: price, courses, bundle, purchase, checkout or invoice.',
            'help' => "Hello 👋 I am your DOUROUSS E-Learning AI assistant.\n\nI can help you to:\n1. Discover available courses\n2. Check course prices\n3. Buy a course\n4. Go to payment\n5. Find your purchased courses\n6. Download your invoice",
            'no_courses' => "No courses are available at the moment.",
            'login_required_courses' => "Please log in to view your courses.",
            'login_required_invoice' => "Please log in to retrieve your invoice.",
            'course_not_found' => "I could not find this course. Please try the exact name shown on the page.",
            'forbidden' => "I cannot perform this action. You can view your courses, request your invoice, or go to checkout.",
            'fallback' => "I can help you view courses, check prices, buy a course, access payment, or retrieve an invoice.",
            'checkout_redirect' => "Redirecting to checkout...",
            'already_purchased' => "The course {{course}} has already been purchased.",
            'added_to_cart' => "Perfect. {{course}} has been added for {{months}} month(s). Redirecting to checkout...",
            'free_price' => "{{course}} is free.",
            'paid_price' => "{{course}} costs {{price}} per month. For {{months}} month(s): {{total}}.",
            'available_courses' => "Here are the available courses on the platform:",
            'bundles_available' => "Bundles are also available. You can write: show bundles.",
            'no_bundles' => "No bundles are available at the moment.",
            'bundles_title' => "Available bundles:",
            'invoice_ready' => "Your invoice is ready for: {{product}}.",
            'download_invoice' => "Click the button below to download the PDF invoice.",
        ],

        'ar' => [
            'unpurchased_login_required' => "يرجى تسجيل الدخول لعرض الدورات التي لم تشترها بعد.",
'student_not_found' => "لم أتمكن من تحديد حساب الطالب الخاص بك.",
'all_courses_purchased' => "لقد اشتريت جميع الدورات المتاحة حاليًا.",
'no_purchased_courses' => "لم تقم بشراء أي دورة بعد.",
'no_invoice_found' => "لم أجد فاتورة مرتبطة بحسابك.",
'no_purchase_for_invoice' => "لم يتم العثور على عملية شراء لإنشاء الفاتورة.",
            'invalid_session' => 'انتهت صلاحية الجلسة. يرجى تحديث الصفحة ثم المحاولة مرة أخرى.',
            'empty_message' => 'اكتب سؤالك: السعر، الدورات، الحزم، الشراء، الدفع أو الفاتورة.',
            'help' => "مرحبًا 👋 أنا مساعد DOUROUSS E-Learning الذكي.\n\nيمكنني مساعدتك في:\n1. معرفة الدورات المتاحة\n2. معرفة أسعار الدورات\n3. شراء دورة\n4. الانتقال إلى الدفع\n5. عرض الدورات التي اشتريتها\n6. تحميل الفاتورة",
            'no_courses' => "لا توجد دورات متاحة حاليًا.",
            'login_required_courses' => "يرجى تسجيل الدخول لعرض دوراتك.",
            'login_required_invoice' => "يرجى تسجيل الدخول للحصول على الفاتورة.",
            'course_not_found' => "لم أتمكن من العثور على هذه الدورة. يرجى كتابة الاسم كما يظهر في الصفحة.",
            'forbidden' => "لا يمكنني تنفيذ هذا الإجراء. يمكنك عرض دوراتك أو طلب الفاتورة أو الانتقال إلى الدفع.",
            'fallback' => "يمكنني مساعدتك في عرض الدورات، معرفة الأسعار، شراء دورة، الانتقال إلى الدفع أو الحصول على الفاتورة.",
            'checkout_redirect' => "جاري التوجيه إلى صفحة الدفع...",
            'already_purchased' => "لقد تم شراء دورة {{course}} بالفعل.",
            'added_to_cart' => "تمت إضافة {{course}} لمدة {{months}} شهر إلى السلة. جاري التوجيه إلى الدفع...",
            'free_price' => "{{course}} مجانية.",
            'paid_price' => "سعر {{course}} هو {{price}} لكل شهر. لمدة {{months}} شهر: {{total}}.",
            'available_courses' => "هذه هي الدورات المتاحة على المنصة:",
            'bundles_available' => "توجد أيضًا حزم متاحة. يمكنك كتابة: عرض الحزم.",
            'no_bundles' => "لا توجد حزم متاحة حاليًا.",
            'bundles_title' => "الحزم المتاحة:",
            'invoice_ready' => "فاتورتك جاهزة لـ: {{product}}.",
            'download_invoice' => "اضغط على الزر أدناه لتحميل الفاتورة بصيغة PDF.",
        ],
    ];

    $text = $strings[$lang][$key] ?? $strings['fr'][$key] ?? $key;

    foreach ($params as $name => $value) {
        $text = str_replace('{{' . $name . '}}', (string)$value, $text);
    }

    return $text;
}

/**
 * Normalize text for loose matching.
 *
 * @param string $text
 * @return string
 */
function local_elearning_system_call_llm_answer(
    string $message,
    array $catalog,
    bool $isloggedin,
    ?stdClass $user = null
): ?string {
    $config = get_config('local_elearning_system');

    if (empty($config->llm_enabled) || empty($config->llm_api_key)) {
        return null;
    }

    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    $model = !empty($config->llm_model) ? (string)$config->llm_model : 'gpt-4o-mini';
    $endpoint = !empty($config->llm_endpoint)
        ? (string)$config->llm_endpoint
        : 'https://api.openai.com/v1/chat/completions';

    $catalogtext = '';
    foreach ($catalog as $item) {
        $catalogtext .= '- ' . $item['name'];

        if (!empty($item['isbundle'])) {
            $catalogtext .= ' | Type: Bundle';
        } else {
            $catalogtext .= ' | Type: Course';
        }

        if (!empty($item['isfree'])) {
            $catalogtext .= ' | Price: Free';
        } else {
            $catalogtext .= ' | Price per month: ' . number_format((float)$item['price'], 2);
        }

        $catalogtext .= "\n";
    }

    $userstatus = $isloggedin ? 'authenticated student' : 'visitor without login';

$sitelang = local_elearning_system_chatbot_site_lang();

$languagename = [
    'fr' => 'French',
    'en' => 'English',
    'ar' => 'Arabic',
][$sitelang] ?? 'French';
 $systemprompt = "
You are an intelligent assistant integrated into a Moodle e-learning platform.

Rules:
- Always answer in {$languagename}.
- Keep using {$languagename} during the conversation unless the user clearly switches to another language.
- Never answer in French if the current conversation language is Arabic or English.
- Keep the answer short, clear and helpful.
- Do not invent courses that do not exist.

Available catalog:
{$catalogtext}
";

    $payload = [
        'model' => $model,
        'temperature' => 0.3,
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemprompt,
            ],
            [
                'role' => 'user',
                'content' => $message,
            ],
        ],
    ];

    $curl = new curl();

    $response = $curl->post($endpoint, json_encode($payload), [
        'CURLOPT_HTTPHEADER' => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config->llm_api_key,
        ],
        'CURLOPT_TIMEOUT' => 10,
    ]);

    if (!is_string($response) || $response === '') {
        return null;
    }

    $apiresponse = json_decode($response, true);

    if (!is_array($apiresponse) || !empty($apiresponse['error'])) {
        error_log('LLM answer error: ' . $response);
        return null;
    }

    $content = $apiresponse['choices'][0]['message']['content'] ?? '';

    if (!is_string($content) || trim($content) === '') {
        return null;
    }

    return trim($content);
}

function local_elearning_system_chatbot_normalize(string $text): string {
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    $text = core_text::strtolower($text);

    // Normalisation arabe simple.
    $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
    $text = str_replace(['ى'], 'ي', $text);
    $text = str_replace(['ة'], 'ه', $text);
    $text = str_replace(['ؤ'], 'و', $text);
    $text = str_replace(['ئ'], 'ي', $text);

    // Supprimer les voyelles/diacritiques arabes.
    $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);

    // Garder lettres latines + lettres arabes + chiffres.
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', (string)$text);

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

    if (preg_match('/(\d{1,2})\s*(mois|month|months|شهر|اشهر|أشهر)/iu', $normalized, $m)) {
        $months = (int)$m[1];
    } else if (preg_match('/(acheter|achete|buy|purchase|enroll|inscrire|payer|شراء|اشتري|اريد شراء|أريد شراء).*?(\d{1,2})/iu', $normalized, $m)) {
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
function local_elearning_system_chatbot_command(string $type, string $course = '', int $months = 1, ?string $lang = null): string {
    if ($lang === null) {
        $lang = local_elearning_system_chatbot_conversation_lang();
    }

    if ($type === 'price') {
        if ($lang === 'ar') {
            return 'سعر ' . $course;
        }
        if ($lang === 'en') {
            return 'price ' . $course;
        }
        return 'prix ' . $course;
    }

    if ($type === 'buy') {
        if ($lang === 'ar') {
            return 'شراء ' . $course . ' لمدة ' . $months . ' شهر';
        }
        if ($lang === 'en') {
            return 'buy ' . $course . ' for ' . $months . ' month';
        }
        return 'acheter ' . $course . ' pour ' . $months . ' mois';
    }

    if ($type === 'checkout') {
        if ($lang === 'ar') {
            return 'الدفع';
        }
        return 'checkout';
    }

    if ($type === 'bundles') {
        if ($lang === 'ar') {
            return 'عرض الحزم';
        }
        if ($lang === 'en') {
            return 'show bundles';
        }
        return 'voir les bundles';
    }

    if ($type === 'mycourses') {
        if ($lang === 'ar') {
            return 'دوراتي';
        }
        if ($lang === 'en') {
            return 'my courses';
        }
        return 'voir mes cours';
    }

    if ($type === 'invoice') {
        if ($lang === 'ar') {
            return 'تحميل الفاتورة';
        }
        if ($lang === 'en') {
            return 'download my invoice';
        }
        return 'donne moi ma facture';
    }

    return '';
}

function local_elearning_system_chatbot_recommended_commands(array $catalog, ?array $matched, moodle_database $DB, ?stdClass $user, ?string $lang = null): array {
    if ($lang === null) {
        $lang = local_elearning_system_chatbot_conversation_lang();
    }

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

        if ($userid > 0 && local_elearning_system_chatbot_get_purchase_status($userid, (int)$item['id']) !== 'none') {
            continue;
        }

        if ($chosenfallback === null) {
            $chosenfallback = $item;
        }

        if (
            strpos((string)$item['normalizedname'], 'math') !== false ||
            strpos((string)$item['normalizedname'], 'mathem') !== false
        ) {
            $chosenmath = $item;
            break;
        }
    }

    $chosen = $chosenmath ?: $chosenfallback;

    if ($chosen) {
        $suggestions[] = local_elearning_system_chatbot_command('buy', $chosen['name'], 1, $lang);
        $suggestions[] = local_elearning_system_chatbot_command('price', $chosen['name'], 1, $lang);
    }

    if ($matched) {
        $suggestions[] = local_elearning_system_chatbot_command('buy', $matched['name'], 2, $lang);
        $suggestions[] = local_elearning_system_chatbot_command('checkout', '', 1, $lang);
    } else {
        $suggestions[] = local_elearning_system_chatbot_command('bundles', '', 1, $lang);
        $suggestions[] = local_elearning_system_chatbot_command('checkout', '', 1, $lang);
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
function local_elearning_system_chatbot_build_guide_response(string $normalized, array $catalog, ?string $lang = null): array {
    if ($lang === null) {
        $lang = local_elearning_system_chatbot_conversation_lang();
    }

    $firstcourse = '';
    foreach ($catalog as $item) {
        if (empty($item['isbundle'])) {
            $firstcourse = (string)$item['name'];
            break;
        }
    }

    if ($firstcourse === '') {
        $firstcourse = 'Arabic';
    }

    if (preg_match('/(facture|invoice|receipt|فاتورة|الفاتورة)/iu', $normalized)) {
        if ($lang === 'ar') {
            return [
                'reply' => "دليل الفاتورة:\n1. اكتب: تحميل الفاتورة\n2. إذا لزم الأمر، اذكر اسم المنتج\n3. اضغط على زر تحميل الفاتورة.",
                'suggestions' => [
                    local_elearning_system_chatbot_command('invoice', '', 1, $lang),
                    local_elearning_system_chatbot_command('mycourses', '', 1, $lang),
                    local_elearning_system_chatbot_command('checkout', '', 1, $lang),
                ],
            ];
        }

        if ($lang === 'en') {
            return [
                'reply' => "Invoice guide:\n1. Write: download my invoice\n2. If needed, specify the product name\n3. Click the download invoice button.",
                'suggestions' => [
                    local_elearning_system_chatbot_command('invoice', '', 1, $lang),
                    local_elearning_system_chatbot_command('mycourses', '', 1, $lang),
                    local_elearning_system_chatbot_command('checkout', '', 1, $lang),
                ],
            ];
        }

        return [
            'reply' => "Guide facture :\n1. Écrivez : donne moi ma facture\n2. Si besoin, précisez le produit\n3. Cliquez sur Télécharger facture.",
            'suggestions' => [
                local_elearning_system_chatbot_command('invoice', '', 1, $lang),
                local_elearning_system_chatbot_command('mycourses', '', 1, $lang),
                local_elearning_system_chatbot_command('checkout', '', 1, $lang),
            ],
        ];
    }

    if (preg_match('/(bundle|bundles|pack|packs|حزم|الحزم|الباقات)/iu', $normalized)) {
        if ($lang === 'ar') {
            return [
                'reply' => "دليل الحزم:\n1. اكتب: عرض الحزم\n2. اختر الحزمة المناسبة\n3. اكتب: الدفع لإتمام الشراء.",
                'suggestions' => [
                    local_elearning_system_chatbot_command('bundles', '', 1, $lang),
                    local_elearning_system_chatbot_command('checkout', '', 1, $lang),
                    local_elearning_system_chatbot_command('mycourses', '', 1, $lang),
                ],
            ];
        }

        if ($lang === 'en') {
            return [
                'reply' => "Bundles guide:\n1. Write: show bundles\n2. Choose a bundle\n3. Write: checkout to complete payment.",
                'suggestions' => [
                    local_elearning_system_chatbot_command('bundles', '', 1, $lang),
                    local_elearning_system_chatbot_command('checkout', '', 1, $lang),
                    local_elearning_system_chatbot_command('mycourses', '', 1, $lang),
                ],
            ];
        }

        return [
            'reply' => "Guide bundles :\n1. Écrivez : voir les bundles\n2. Choisissez un bundle\n3. Écrivez : checkout pour payer.",
            'suggestions' => [
                local_elearning_system_chatbot_command('bundles', '', 1, $lang),
                local_elearning_system_chatbot_command('checkout', '', 1, $lang),
                local_elearning_system_chatbot_command('mycourses', '', 1, $lang),
            ],
        ];
    }

    $buycmd = local_elearning_system_chatbot_command('buy', $firstcourse, 1, $lang);
    $pricecmd = local_elearning_system_chatbot_command('price', $firstcourse, 1, $lang);
    $checkoutcmd = local_elearning_system_chatbot_command('checkout', '', 1, $lang);
    $mycoursescmd = local_elearning_system_chatbot_command('mycourses', '', 1, $lang);

    if ($lang === 'ar') {
        return [
            'reply' => "دليل الشراء:\n1. تحقق من السعر: {$pricecmd}\n2. أضف الدورة إلى السلة: {$buycmd}\n3. أكمل الدفع: {$checkoutcmd}\n4. تحقق من دوراتك: {$mycoursescmd}",
            'suggestions' => [$pricecmd, $buycmd, $checkoutcmd],
        ];
    }

    if ($lang === 'en') {
        return [
            'reply' => "Purchase guide:\n1. Check the price: {$pricecmd}\n2. Add the course to cart: {$buycmd}\n3. Complete payment: {$checkoutcmd}\n4. Check your courses: {$mycoursescmd}",
            'suggestions' => [$pricecmd, $buycmd, $checkoutcmd],
        ];
    }

    return [
        'reply' => "Guide achat inscription :\n1. Vérifiez le prix : {$pricecmd}\n2. Ajoutez au panier : {$buycmd}\n3. Finalisez : {$checkoutcmd}\n4. Vérifiez vos cours : {$mycoursescmd}",
        'suggestions' => [$pricecmd, $buycmd, $checkoutcmd],
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
        . 'Allowed intents are strictly: invoice_request, my_courses, unpurchased_courses, course_list, price_request, purchase_course, checkout, bundles, help, forbidden_action, unknown.'        
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

    $allowed = ['invoice_request', 'my_courses', 'unpurchased_courses', 'course_list', 'price_request', 'purchase_course', 'checkout', 'bundles', 'help', 'forbidden_action', 'unknown'];    $intent = strtolower(trim((string)($result['intent'] ?? 'unknown')));
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
    'forbidden_action' => '/(supprime|delete|autre utilisateur|another user|change mon prix|price to zero|ignore les regles|ignore the rules|donne acces|give access|give me another user invoice|set my price to zero)/iu',

    'course_list' => '/(quels cours|cours disponibles|formations disponibles|liste des cours|catalogue|available courses|courses available|show courses|what courses|what are the courses|الدورات|الدورات المتاحه|ما هي الدورات|ماهي الدورات|قائمه الدورات|اعرض الدورات|الدروس المتاحه)/iu',

    'purchase_course' => '/(acheter|achete|buy|purchase|enroll|inscrire|inscription|je veux acheter|comment acheter|how to buy|how can i buy|شراء|اشتري|اريد شراء|اود شراء|كيف اشتري|سجلني|التسجيل في)/iu',

    'price_request' => '/(prix|tarif|co[uû]t|cout|coute|combien coute|how much|what is the price of|price|السعر|ثمن|كم السعر|كم ثمن|بكم|ما سعر|ما هو السعر)/iu',

    'checkout' => '/(checkout|finaliser ma commande|panier|paiement|payer maintenant|open cart|proceed to checkout|الدفع|السله|ادفع|اتمام الشراء|اكمال الدفع|اذهب للدفع)/iu',

    'bundles' => '/(voir les bundles|show bundles|available bundles|bundle|bundles|pack|packs|حزم|الحزم|عرض الحزم|الباقات|باقه|الباقه)/iu',

    'my_courses' => '/(mes cours|cours achet[eé]s|formations achet[eé]es|my courses|show my courses|دوراتي|كورساتي|دوراتي المشتراه|الدورات التي اشتريتها|اعرض دوراتي)/iu',

    'invoice_request' => '/(facture|recu|reçu|invoice|receipt|donner moi facture|donne moi facture|فاتوره|الفاتوره|اريد الفاتوره|تحميل الفاتوره|كيف احصل على فاتورتي)/iu',

    'unpurchased_courses' => '/(cours pas encore achet[eé]s|cours non achet[eé]s|courses not purchased|courses i have not bought|available courses not bought|الدورات غير المشتراه|الدورات التي لم اشتريها|كورسات لم اشتريها|دورات لم اشتريها)/iu',

    'help' => '/(bonjour|salut|aide|que peux tu faire|what can you do|help|مرحبا|اهلا|مساعده|ساعدني|ماذا تستطيع)/iu',
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

function local_elearning_system_chatbot_plugin_order_is_active(stdClass $order): bool {
    $durationmonths = !empty($order->durationmonths)
        ? max(1, (int)$order->durationmonths)
        : 1;

    if (!empty($order->expiresat)) {
        return (int)$order->expiresat > time();
    }

    if (!empty($order->timecreated)) {
        $expiresat = strtotime('+' . $durationmonths . ' months', (int)$order->timecreated);
        return $expiresat === false || $expiresat > time();
    }

    return true;
}

function local_elearning_system_chatbot_get_purchase_status(int $userid, int $productid): string {
    if ($userid <= 0 || $productid <= 0) {
        return 'none';
    }

    $db = \local_elearning_system\plugin_db::get();

    $stmt = $db->prepare("
        SELECT o.id, o.userid, o.productid, o.durationmonths, o.expiresat, o.timecreated,
               p.isbundle, p.bundleitems
          FROM el_orders o
     LEFT JOIN el_products p ON p.id = o.productid
         WHERE o.userid = ?
      ORDER BY o.id DESC
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param('i', $userid);
    $stmt->execute();

    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_object()) {
        if (local_elearning_system_chatbot_plugin_order_is_active($row)) {
            $orders[] = $row;
        }
    }

    $stmt->close();

    foreach ($orders as $order) {
        if ((int)$order->productid === $productid) {
            return 'direct';
        }
    }

    foreach ($orders as $order) {
        if (empty($order->isbundle) || empty($order->bundleitems)) {
            continue;
        }

        $bundleitemids = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)$order->bundleitems)
        ))));

        if (in_array($productid, $bundleitemids, true)) {
            return 'bundle';
        }
    }

    return 'none';
}
function local_elearning_system_chatbot_guess_course_from_text(string $normalized): ?string {
    $catalog = local_elearning_system_chatbot_get_plugin_catalog();

    $expanded = local_elearning_system_chatbot_expand_aliases($normalized);
    $matched = local_elearning_system_chatbot_match_product($expanded, $catalog, false);

    if ($matched && !empty($matched['name'])) {
        return (string)$matched['name'];
    }

    return null;
}
/**
 * Build a smart catalog response.
 *
 * @param array $catalog
 * @return array
 */
function local_elearning_system_chatbot_build_catalog_response(array $catalog, ?string $lang = null): array {
    if ($lang === null) {
        $lang = local_elearning_system_chatbot_conversation_lang();
    }
    $courses = [];
    $bundles = [];

    foreach ($catalog as $item) {
        if (!empty($item['isbundle'])) {
            $bundles[] = $item;
        } else {
            $courses[] = $item;
        }
    }

    if (empty($courses) && empty($bundles)) {
        return [
            'reply' => local_elearning_system_chatbot_t('no_courses'),
            'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
        ];
    }

    $lines = [];

    if (!empty($courses)) {
        $lines[] = local_elearning_system_chatbot_t('available_courses', [], $lang);

        $index = 1;
        foreach ($courses as $course) {
            $priceinfo = '';
            if (!empty($course['isfree'])) {
                $priceinfo = ($lang === 'ar') ? 'مجاني' : (($lang === 'en') ? 'free' : 'gratuit');
            } else {
                $priceinfo = number_format((float)$course['price'], 2) . (($lang === 'ar') ? ' / شهر' : (($lang === 'en') ? ' / month' : ' / mois'));
            }

            $lines[] = $index . '. ' . $course['name'] . ' — ' . $priceinfo;
            $index++;

            if ($index > 8) {
                break;
            }
        }
    }

    if (!empty($bundles)) {
        $lines[] = "";
        $lines[] = local_elearning_system_chatbot_t('bundles_available', [], $lang);
    }

    $suggestions = [];

    if (!empty($courses)) {
        $firstcourse = $courses[0]['name'];
        $suggestions[] = local_elearning_system_chatbot_command('price', $firstcourse, 1, $lang);

        $suggestions[] = local_elearning_system_chatbot_command('buy', $firstcourse, 1, $lang);

    }

    $suggestions[] = local_elearning_system_chatbot_command('bundles', '', 1, $lang);

    return [
        'reply' => implode("\n", $lines),
        'suggestions' => array_slice(array_values(array_unique($suggestions)), 0, 3),
    ];
}
function local_elearning_system_chatbot_build_unpurchased_courses_response(array $catalog, moodle_database $DB, stdClass $USER): array {
    if (!isloggedin() || isguestuser()) {
        return [
            'reply' => local_elearning_system_chatbot_t('login_required_courses'),
            'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
        ];
    }

    $userctx = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
    $targetuserid = (int)($userctx['targetuserid'] ?? 0);

    if ($targetuserid <= 0) {
        return [
            'reply' => "Je n’ai pas pu identifier votre compte étudiant.",
            'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
        ];
    }

    $available = [];

    foreach ($catalog as $item) {
        if (!empty($item['isbundle'])) {
            continue;
        }

        $productid = (int)$item['id'];

        if (!local_elearning_system_is_product_covered_by_active_purchase($targetuserid, $productid, $DB)) {
            $available[] = $item;
        }
    }

    if (empty($available)) {
        return [
            'reply' => "Vous avez déjà acheté tous les cours disponibles actuellement.",
            'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
        ];
    }

    $lines = [];
    $lines[] = "Voici les cours que vous n’avez pas encore achetés :";

    $index = 1;
    foreach ($available as $course) {
        if (!empty($course['isfree'])) {
            $priceinfo = ($lang === 'ar') ? 'مجاني' : (($lang === 'en') ? 'free' : 'gratuit');
        } else {
            $priceinfo = number_format((float)$course['price'], 2) . (($lang === 'ar') ? ' / شهر' : (($lang === 'en') ? ' / month' : ' / mois'));
        }

        $lines[] = $index . '. ' . $course['name'] . ' — ' . $priceinfo;
        $index++;
    }

    $firstcourse = $available[0]['name'];

    return [
        'reply' => implode("\n", $lines),
        'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
    ];
}
function local_elearning_system_chatbot_get_plugin_catalog(): array {
    $db = \local_elearning_system\plugin_db::get();

    $sql = "
        SELECT id, name, price, saleprice, status, type, isbundle
          FROM el_products
      ORDER BY id DESC
    ";

    $result = $db->query($sql);

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $catalog = [];

    while ($r = $result->fetch_object()) {
        $price = !empty($r->price) ? (float)$r->price : 0.0;
        $saleprice = !empty($r->saleprice) ? (float)$r->saleprice : 0.0;
        $displayprice = $saleprice > 0 ? $saleprice : $price;

        $status = strtolower(trim((string)($r->status ?? '')));
        $rawtype = strtolower(trim((string)($r->type ?? '')));

        if ($displayprice <= 0) {
            $type = 'free';
        } else if (in_array($rawtype, ['paid', 'subscription', 'subscroiption', 'subcription', 'subscribe', 'premium'], true)) {
            $type = 'paid';
        } else {
            $type = 'free';
        }

        $isbundle = !empty($r->isbundle);

        if (!$isbundle && $type === 'paid' && $status !== 'publish') {
            continue;
        }

        $name = format_string((string)$r->name);

        $catalog[] = [
            'id' => (int)$r->id,
            'name' => $name,
            'normalizedname' => local_elearning_system_chatbot_normalize($name),
            'price' => $displayprice,
            'isfree' => ($type === 'free'),
            'isbundle' => $isbundle,
        ];
    }

    return $catalog;
}

$catalog = local_elearning_system_chatbot_get_plugin_catalog();
$normalized = local_elearning_system_chatbot_normalize($message);
$months = local_elearning_system_chatbot_extract_months($normalized);


$pluginconfig = get_config('local_elearning_system');
$thresholdraw = $pluginconfig->llm_confidence_threshold ?? ($pluginconfig->llmconfidence ?? 0.60);
$llmthreshold = is_numeric($thresholdraw) ? (float)$thresholdraw : 0.60;
$llmthreshold = max(0.0, min(1.0, $llmthreshold));

$fallbackparsed = local_elearning_system_chatbot_resolve_regex_intent($normalized);
$intentdata = null;
$resolvedparsed = $fallbackparsed;

/*
 * Priority:
 * 1. Regex intent first.
 * 2. LLM only if regex did not understand the message.
 */
if (
    !empty($pluginconfig->llm_enabled)
    && (string)($fallbackparsed['intent'] ?? 'unknown') === 'unknown'
) {
    $intentdata = local_elearning_system_call_llm_intent($message);
    error_log('LLM intent: ' . json_encode($intentdata));

    $llmintent = $intentdata ? (string)($intentdata['intent'] ?? 'unknown') : 'unknown';
    $llmconfidence = $intentdata && isset($intentdata['confidence']) ? (float)$intentdata['confidence'] : 0.0;

    if (
        $intentdata
        && $llmintent !== 'unknown'
        && $llmconfidence >= $llmthreshold
    ) {
        $resolvedparsed = [
            'intent' => $llmintent,
            'confidence' => $llmconfidence,
            'entities' => is_array($intentdata['entities'] ?? null) ? $intentdata['entities'] : [],
        ];
    } else {
        $resolvedparsed = $fallbackparsed;
    }
} else {
    $resolvedparsed = $fallbackparsed;
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
$iscourselistintent = ($resolvedintent === 'course_list');

switch ($resolvedintent) {
    case 'unpurchased_courses':
        $response = local_elearning_system_chatbot_build_unpurchased_courses_response($catalog, $DB, $USER);

        echo json_encode([
            'ok' => true,
            'reply' => (string)$response['reply'],
            'suggestions' => is_array($response['suggestions']) ? $response['suggestions'] : local_elearning_system_chatbot_suggestions($chatlang),
            'showrating' => true,
        ]);
        exit;
    case 'course_list':
    $catalogresponse = local_elearning_system_chatbot_build_catalog_response($catalog, $chatlang);

    echo json_encode([
        'ok' => true,
        'reply' => (string)$catalogresponse['reply'],
        'suggestions' => is_array($catalogresponse['suggestions'])
            ? $catalogresponse['suggestions']
            : local_elearning_system_chatbot_suggestions($chatlang),
        'showrating' => true,
    ]);
    exit;
    case 'forbidden_action':
        echo json_encode([
            'ok' => true,
'reply' => local_elearning_system_chatbot_t('forbidden'),   
'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
            'showrating' => false,
        ]);
        exit;

    case 'help':
        $guide = local_elearning_system_chatbot_build_guide_response($normalized, $catalog, $chatlang);
        echo json_encode([
            'ok' => true,
            'reply' => (string)$guide['reply'],
            'suggestions' => is_array($guide['suggestions']) ? $guide['suggestions'] : local_elearning_system_chatbot_suggestions($chatlang),
            'showrating' => false,
        ]);
        exit;
    
    
    case 'checkout':
        echo json_encode([
            'ok' => true,
            'reply' => local_elearning_system_chatbot_t('checkout_redirect', [], $chatlang),
            'redirecturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
            'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
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
                'reply' => local_elearning_system_chatbot_t('no_bundles'),
                'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
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
            'reply' => local_elearning_system_chatbot_t('bundles_title') . "\n" . implode("\n", $bundlelines),
            'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
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

if (
    !$matched
    && !$isbundleintent
    && ($ispriceintent || $isbuyintent)
    && (bool)preg_match('/\b(cours|course)\b/', $normalized)
) {
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
        'reply' => local_elearning_system_chatbot_t('checkout_redirect'),
        'redirecturl' => (new moodle_url('/local/elearning_system/checkout.php'))->out(false),
        'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
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
                'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER, $chatlang),
                'showrating' => false,
            ]);
            exit;
        }
    }

    echo json_encode([
        'ok' => true,
        'reply' => 'Vous n avez pas de cours achetes encore.',
        'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER, $chatlang),
        'showrating' => false,
    ]);
    exit;
}

if ($isinvoiceintent) {
    if (!isloggedin() || isguestuser()) {
        echo json_encode([
            'ok' => true,
            'reply' => local_elearning_system_chatbot_t('login_required_invoice', [], $chatlang),
            'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
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
            'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, $matched, $DB, $USER, $chatlang),
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
        "SELECT o.id, o.timecreated, o.productid, p.name AS productname, COALESCE(p.isbundle, 0) AS isbundle
           FROM {elearning_orders} o
      LEFT JOIN {elearning_products} p ON p.id = o.productid
          WHERE o.userid = :userid
       ORDER BY o.id DESC",
        ['userid' => $targetuserid],
        0,
        10
    );

    if (count($recentorders) > 0) {
        $suggestions = [];
        $lines = [];
        $index = 1;

        foreach ($recentorders as $ro) {
            $pname = !empty($ro->productname) ? format_string((string)$ro->productname) : 'Produit';
            $type = !empty($ro->isbundle) ? 'Bundle' : 'Cours';
            $date = !empty($ro->timecreated) ? userdate((int)$ro->timecreated, '%d/%m/%Y') : '';

            $line = $index . '. ' . $pname . ' — ' . $type;
            if ($date !== '') {
                $line .= ' — acheté le ' . $date;
            }
            $lines[] = $line;

            if (count($suggestions) < 3) {
                $suggestions[] = 'facture ' . $pname;
            }

            $index++;
        }

        echo json_encode([
            'ok' => true,
            'reply' => "Pour quel achat voulez-vous la facture ?\n\n" . implode("\n", $lines) . "\n\nÉcrivez par exemple : facture " . (!empty($suggestions[0]) ? str_replace('facture ', '', $suggestions[0]) : 'NomDuProduit') . ".",
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
            'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, $matched, $DB, $USER, $chatlang),
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

    $orderdate = !empty($order->timecreated)
    ? userdate((int)$order->timecreated, '%d/%m/%Y')
    : '';

$invoicereply = "Votre facture est prête pour : " . $productname . ".";

if ($orderdate !== '') {
    $invoicereply .= "\nDate d'achat : " . $orderdate . ".";
}

$invoicereply .= "\nCliquez sur le bouton ci-dessous pour télécharger la facture PDF.";

    echo json_encode([
        'ok' => true,
        'reply' => $invoicereply,
        'invoiceurl' => $invoiceurl,
        'invoicelabel' => 'Télécharger la facture PDF',
        'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
        'showrating' => false,
    ]);
    exit;
}

if (!$matched && !$ispriceintent && !$isbuyintent && !$isviewintent) {
    $aifallback = local_elearning_system_call_llm_answer(
        $message,
        $catalog,
        isloggedin() && !isguestuser(),
        $USER
    );

    if ($aifallback !== null) {
        echo json_encode([
            'ok' => true,
            'reply' => $aifallback,
            'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER, $chatlang),
            'showrating' => true,
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'reply' => local_elearning_system_chatbot_t('fallback', [], $chatlang),
        'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
        'showrating' => true,
    ]);
    exit;
}
if (!$matched && $isbuyintent && preg_match('/(comment|how|كيف)/iu', $message)) {
    $guide = local_elearning_system_chatbot_build_guide_response($normalized, $catalog, $chatlang);

    echo json_encode([
        'ok' => true,
        'reply' => (string)$guide['reply'],
        'suggestions' => is_array($guide['suggestions'])
            ? $guide['suggestions']
            : local_elearning_system_chatbot_suggestions($chatlang),
        'showrating' => false,
    ]);
    exit;
}
if (!$matched) {
    echo json_encode([
        'ok' => true,
'reply' => local_elearning_system_chatbot_t('course_not_found', [], $chatlang),
        'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER, $chatlang),
        'showrating' => true,
    ]);
    exit;
}

if ($ispriceintent && !$isbuyintent) {
    if (!empty($matched['isfree'])) {
        $reply = local_elearning_system_chatbot_t('free_price', [
    'course' => $matched['name'],
]);
    } else {
        $total = $matched['price'] * $months;
        $reply = local_elearning_system_chatbot_t('paid_price', [
    'course' => $matched['name'],
    'price' => number_format($matched['price'], 2),
    'months' => $months,
    'total' => number_format($total, 2),
]);
    }

    echo json_encode([
        'ok' => true,
        'reply' => $reply,
        'suggestions' => local_elearning_system_chatbot_recommended_commands($catalog, $matched, $DB, $USER, $chatlang),
        'showrating' => true,
    ]);
    exit;
}

if ($isbuyintent) {
    if (!isloggedin() || isguestuser()) {
        echo json_encode([
            'ok' => true,
            'reply' => local_elearning_system_chatbot_t('login_required_courses', [], $chatlang),
            'suggestions' => local_elearning_system_chatbot_suggestions($chatlang),
            'showrating' => true,
        ]);
        exit;
    }

    $effectiveuserctx = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
    $targetuserid = (int)($effectiveuserctx['targetuserid'] ?? $USER->id);

    $purchasestatus = local_elearning_system_chatbot_get_purchase_status(
        $targetuserid,
        (int)$matched['id']
    );

    if ($purchasestatus !== 'none') {
        echo json_encode([
            'ok' => true,
            'reply' => local_elearning_system_chatbot_t('already_purchased', [
                'course' => $matched['name'],
            ], $chatlang),
            'suggestions' => local_elearning_system_chatbot_recommended_commands(
                $catalog,
                $matched,
                $DB,
                $USER,
                $chatlang
            ),
            'showrating' => true,
        ]);
        exit;
    }

    if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
        $SESSION->local_elearning_system_cart = [];
    }

    local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

    $SESSION->local_elearning_system_cart[(int)$matched['id']] = [
        'productid' => (int)$matched['id'],
        'qty' => 1,
        'durationmonths' => max(1, min(24, (int)$months)),
    ];

    echo json_encode([
        'ok' => true,
        'reply' => local_elearning_system_chatbot_t('added_to_cart', [
            'course' => $matched['name'],
            'months' => $months,
        ], $chatlang),
        'redirecturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
        'suggestions' => local_elearning_system_chatbot_recommended_commands(
            $catalog,
            $matched,
            $DB,
            $USER,
            $chatlang
        ),
        'showrating' => true,
    ]);
    exit;
}

$catalogresponse = local_elearning_system_chatbot_build_catalog_response($catalog, $chatlang);

echo json_encode([
    'ok' => true,
    'reply' => local_elearning_system_chatbot_t('fallback', [], $chatlang),
    'suggestions' => is_array($catalogresponse['suggestions'])
        ? $catalogresponse['suggestions']
        : local_elearning_system_chatbot_recommended_commands($catalog, null, $DB, $USER, $chatlang),
    'showrating' => true,
]);
exit;