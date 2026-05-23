<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');

global $DB, $CFG, $USER, $PAGE, $OUTPUT, $SESSION;
function local_elearning_system_plugin_get_catalog_products(): array {
    $db = \local_elearning_system\plugin_db::get();

    $result = $db->query("SELECT * FROM el_products ORDER BY id DESC");

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $records = [];

    while ($row = $result->fetch_object()) {
        $records[(int)$row->id] = $row;
    }

    return $records;
}

function local_elearning_system_plugin_order_is_active(stdClass $order): bool {
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

function local_elearning_system_plugin_get_user_active_orders(int $userid): array {
    $db = \local_elearning_system\plugin_db::get();

    $stmt = $db->prepare("
        SELECT o.id, o.userid, o.productid, o.timecreated, o.durationmonths, o.expiresat,
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
        if (local_elearning_system_plugin_order_is_active($row)) {
            $orders[] = $row;
        }
    }

    $stmt->close();

    return $orders;
}

function local_elearning_system_plugin_get_product_purchase_status(int $userid, int $productid): string {
    if ($userid <= 0 || $productid <= 0) {
        return 'none';
    }

    $orders = local_elearning_system_plugin_get_user_active_orders($userid);

    foreach ($orders as $order) {
        if ((int)$order->productid === $productid) {
            return 'direct';
        }
    }

    foreach ($orders as $order) {
        if (empty($order->isbundle) || empty($order->bundleitems)) {
            continue;
        }

        $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$order->bundleitems)))));

        if (in_array($productid, $bundleitemids, true)) {
            return 'bundle';
        }
    }

    return 'none';
}

function local_elearning_system_plugin_bundle_all_items_purchased(int $userid, stdClass $bundle): bool {
    if ($userid <= 0 || empty($bundle->isbundle) || empty($bundle->bundleitems)) {
        return false;
    }

    $bundleitemids = array_values(array_unique(array_filter(array_map(
        'intval',
        explode(',', (string)$bundle->bundleitems)
    ))));

    if (empty($bundleitemids)) {
        return false;
    }

    foreach ($bundleitemids as $itemid) {
        $itemstatus = local_elearning_system_plugin_get_product_purchase_status($userid, (int)$itemid);

        if ($itemstatus === 'none') {
            return false;
        }
    }

    return true;
}
$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/elearning_system/index.php'));
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
local_elearning_system_apply_requested_language();

$frontendstrings = local_elearning_system_get_flat_language_strings();
$lang = local_elearning_system_get_active_language();
$isrtl = ($lang === 'ar');

$PAGE->set_title($frontendstrings['allcourses'] ?? 'All Courses');
$PAGE->set_heading($frontendstrings['allcourses'] ?? 'All Courses');
// local_elearning_system_force_auth_login_url('/local/elearning_system/index.php');



$isloggedin = isloggedin() && !isguestuser();
$beneficiaryuserid = (int)$USER->id;
if ($isloggedin) {
    $usercontext = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
    $beneficiaryuserid = (int)$usercontext['targetuserid'];
}

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}
local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

$purchasedproductids = [];
// =============================
// GET PRODUCTS
// Show all free products and only published paid products.
// =============================
$records = local_elearning_system_plugin_get_catalog_products();

$products = [];
$bundles = [];

foreach ($records as $r) {

    $originalprice = !empty($r->price) ? (float)$r->price : 0.0;
$saleprice = !empty($r->saleprice) ? (float)$r->saleprice : 0.0;

$displayprice = $saleprice > 0 ? $saleprice : $originalprice;
$hasdiscount = $originalprice > 0 && $saleprice > 0 && $originalprice > $saleprice;
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

    // Keep paid non-bundle products visible only when published; bundles stay visible.
    if (!$isbundle && $type === 'paid' && $status !== 'publish') {
        continue;
    }

    $image = '';
    if (!empty($r->image)) {
        if (preg_match('/^https?:\/\//', $r->image)) {
            $image = $r->image;
        } else if (strpos($r->image, '/') === 0) {
            $image = $CFG->wwwroot.$r->image;
        } else {
            $image = $CFG->wwwroot.'/local/elearning_system/uploads/'.$r->image;
        }
    }

    $item = [
        'id' => (int)$r->id,
        'name' => format_string($r->name),
        'description' => format_text($r->description, FORMAT_HTML),

        'image' => $image,
        'hasimage' => !empty($image),

        'price' => local_elearning_system_format_price($displayprice),
'displayprice' => local_elearning_system_format_price($displayprice),

'originalprice' => $hasdiscount ? local_elearning_system_format_price($originalprice) : '',
'saleprice' => $saleprice > 0 ? local_elearning_system_format_price($saleprice) : '',
'hasdiscount' => $hasdiscount,
        'type' => ucfirst($type),
        'isfree' => $type === 'free',
        'ispaid' => $type === 'paid',
        'isbundle' => $isbundle,
        'courseid' => !empty($r->courseid) ? (int)$r->courseid : 0,
       'producturl' => (new moodle_url('/local/elearning_system/product.php', [
    'id' => (int)$r->id,
    'lang' => $lang,
]))->out(false),

'addtocarturl' => (new moodle_url('/local/elearning_system/add_to_cart.php', [
    'id' => (int)$r->id,
    'lang' => $lang,
]))->out(false),
        'isincart' => array_key_exists((int)$r->id, $SESSION->local_elearning_system_cart),
        'ispurchased' => false,
    ];

  if ($isloggedin) {
    $purchasestatus = local_elearning_system_plugin_get_product_purchase_status(
        $beneficiaryuserid,
        (int)$r->id
    );

    $bundleallitemspurchased = false;

    if (!empty($r->isbundle)) {
        $bundleallitemspurchased = local_elearning_system_plugin_bundle_all_items_purchased(
            $beneficiaryuserid,
            $r
        );
    }

    $item['ispurchased'] = ($purchasestatus !== 'none') || $bundleallitemspurchased;
    $item['isdirectpurchase'] = ($purchasestatus === 'direct') || $bundleallitemspurchased;
    $item['isbundlepurchase'] = ($purchasestatus === 'bundle');

    if ($bundleallitemspurchased && $purchasestatus === 'none') {
        $item['purchaselabel'] = get_string('purchased', 'local_elearning_system');
    } else {
        $item['purchaselabel'] = ($purchasestatus === 'direct')
            ? get_string('purchased', 'local_elearning_system')
            : (($purchasestatus === 'bundle') ? get_string('includedinbundle', 'local_elearning_system') : '');
    }
}
    if (!empty($r->isbundle)) {
        $bundles[] = $item;
    } else {
        $products[] = $item;
    }
}

echo $OUTPUT->header();

$authurl = (new moodle_url('/local/elearning_system/auth.php', [
    'return' => '/local/elearning_system/index.php'
]))->out(false);

$admindashboardurl = (new moodle_url('/local/elearning_system/admin/dashboard.php', [
    'section' => 'dashboard'
]))->out(false);

echo $OUTPUT->render_from_template('local_elearning_system/home', [
'home_login_now' => ($lang === 'ar') ? 'تسجيل الدخول' : (($lang === 'en') ? 'Login' : 'Se connecter'),

'home_audience_student' => ($lang === 'ar') ? 'التلاميذ' : (($lang === 'en') ? 'Students' : 'Étudiants'),
'home_audience_student_desc' => ($lang === 'ar')
    ? 'ادخل إلى الدورات، تابع تعلمك وابدأ الدروس بطريقة سهلة ومنظمة.'
    : (($lang === 'en')
        ? 'Access your courses, follow your learning and start lessons easily.'
        : 'Accédez à vos cours, suivez votre apprentissage et démarrez vos leçons facilement.'),

'home_audience_parent' => ($lang === 'ar') ? 'الأولياء' : (($lang === 'en') ? 'Parents' : 'Parents'),
'home_audience_parent_desc' => ($lang === 'ar')
    ? 'تابع دورات طفلك، الطلبات، الفواتير وتقدمه من مساحة واحدة.'
    : (($lang === 'en')
        ? 'Track your child’s courses, orders, invoices and progress from one space.'
        : 'Suivez les cours, commandes, factures et la progression de votre enfant depuis un seul espace.'),

'home_feedback_title' => ($lang === 'ar') ? 'آراء المستخدمين' : (($lang === 'en') ? 'User feedback' : 'Avis des utilisateurs'),

'home_feedback_1_name' => ($lang === 'ar') ? 'ولي تلميذ' : (($lang === 'en') ? 'Parent user' : 'Parent d’élève'),
'home_feedback_1_text' => ($lang === 'ar')
    ? 'منصة واضحة وسهلة الاستعمال، ساعدتني على متابعة تعلم ابني والوصول إلى الفواتير بسرعة.'
    : (($lang === 'en')
        ? 'A clear and easy-to-use platform that helps me follow my child’s learning and access invoices quickly.'
        : 'Une plateforme claire et simple qui me permet de suivre l’apprentissage de mon enfant et d’accéder rapidement aux factures.'),

'home_feedback_2_name' => ($lang === 'ar') ? 'تلميذ' : (($lang === 'en') ? 'Student' : 'Étudiant'),
'home_feedback_2_text' => ($lang === 'ar')
    ? 'الدروس منظمة، والواجهة سهلة، ويمكنني الوصول إلى الدورات بسرعة.'
    : (($lang === 'en')
        ? 'The courses are organized, the interface is easy, and I can access lessons quickly.'
        : 'Les cours sont organisés, l’interface est simple et j’accède rapidement à mes leçons.'),

'home_feedback_3_name' => ($lang === 'ar') ? 'مؤسسة تعليمية' : (($lang === 'en') ? 'Educational institution' : 'Établissement'),
'home_feedback_3_text' => ($lang === 'ar')
    ? 'حل عملي لتنظيم المحتوى الرقمي وتحسين تجربة التعلم عن بعد.'
    : (($lang === 'en')
        ? 'A practical solution to organize digital content and improve online learning.'
        : 'Une solution pratique pour organiser les contenus numériques et améliorer l’apprentissage en ligne.'),
  'isrtl' => $isrtl,

'home_stats_title' => ($lang === 'ar') ? 'منصتنا في أرقام' : (($lang === 'en') ? 'Our platform in numbers' : 'Notre plateforme en chiffres'),
'home_stats_subtitle' => ($lang === 'ar') ? 'مؤشرات بسيطة تعكس تطور المنصة وجودة التجربة التعليمية.' : (($lang === 'en') ? 'Simple indicators showing platform growth and learning quality.' : 'Quelques indicateurs qui reflètent l’évolution de la plateforme et la qualité de l’expérience.'),

'home_stat_1_number' => '+4,500',
'home_stat_1_label' => ($lang === 'ar') ? 'حصة مباشرة شهريًا' : (($lang === 'en') ? 'live sessions monthly' : 'sessions en direct par mois'),

'home_stat_2_number' => '34',
'home_stat_2_label' => ($lang === 'ar') ? 'مقرًا وجهة تعليمية' : (($lang === 'en') ? 'learning locations' : 'espaces et partenaires éducatifs'),

'home_stat_3_number' => '+550k',
'home_stat_3_label' => ($lang === 'ar') ? 'متعلم مسجل' : (($lang === 'en') ? 'registered learners' : 'apprenants inscrits'),

'home_stat_4_number' => '+200k',
'home_stat_4_label' => ($lang === 'ar') ? 'مورد تعليمي' : (($lang === 'en') ? 'learning resources' : 'ressources pédagogiques'),

'home_audience_title' => ($lang === 'ar') ? 'لمن صُممت المنصة؟' : (($lang === 'en') ? 'Who is this platform for?' : 'À qui s’adresse la plateforme ?'),
'home_audience_subtitle' => ($lang === 'ar') ? 'تجربة تعليمية مناسبة للتلاميذ، الأولياء والمؤسسات التعليمية.' : (($lang === 'en') ? 'A learning experience for students, parents and educational institutions.' : 'Une expérience adaptée aux élèves, aux parents et aux établissements.'),

'home_audience_student' => ($lang === 'ar') ? 'التلاميذ' : (($lang === 'en') ? 'Students' : 'Élèves'),
'home_audience_student_desc' => ($lang === 'ar') ? 'دروس واضحة ومنظمة تساعد على التعلم بطريقة سهلة.' : (($lang === 'en') ? 'Clear and structured courses for a smooth learning experience.' : 'Des cours clairs et structurés pour apprendre facilement.'),

'home_audience_parent' => ($lang === 'ar') ? 'الأولياء' : (($lang === 'en') ? 'Parents' : 'Parents'),
'home_audience_parent_desc' => ($lang === 'ar') ? 'متابعة الدورات والطلبات والفواتير الخاصة بالأبناء.' : (($lang === 'en') ? 'Track children’s courses, orders and invoices.' : 'Suivre les cours, commandes et factures des enfants.'),

'home_audience_school' => ($lang === 'ar') ? 'المؤسسات التعليمية' : (($lang === 'en') ? 'Institutions' : 'Établissements'),
'home_audience_school_desc' => ($lang === 'ar') ? 'تنظيم المحتوى وتحسين تجربة التعلم الرقمي.' : (($lang === 'en') ? 'Organize content and improve digital learning.' : 'Organiser les contenus et améliorer l’apprentissage numérique.'),

'home_testimonial_title' => ($lang === 'ar') ? 'آراء المستخدمين' : (($lang === 'en') ? 'User feedback' : 'Avis des utilisateurs'),
'home_testimonial_name' => ($lang === 'ar') ? 'ولي تلميذ' : (($lang === 'en') ? 'Parent user' : 'Parent d’élève'),
'home_testimonial_text' => ($lang === 'ar') ? 'منصة سهلة وواضحة، تساعدني على متابعة تعلم ابني والوصول إلى الدورات والفواتير بسرعة.' : (($lang === 'en') ? 'A clear and easy-to-use platform that helps me follow my child’s learning and access courses and invoices quickly.' : 'Une plateforme claire et simple qui me permet de suivre l’apprentissage de mon enfant et d’accéder rapidement aux cours et factures.'),
    'home_allcourses' => $frontendstrings['allcourses'] ?? 'All Courses',
    'home_homeintro' => $frontendstrings['homeintro'] ?? '',
    'home_homeslide1kicker' => $frontendstrings['homeslide1kicker'] ?? '',
    'home_homeslide1title' => $frontendstrings['homeslide1title'] ?? '',
    'home_homeslide1desc' => $frontendstrings['homeslide1desc'] ?? '',
    'home_homeslide2kicker' => $frontendstrings['homeslide2kicker'] ?? '',
    'home_homeslide2title' => $frontendstrings['homeslide2title'] ?? '',
    'home_homeslide2desc' => $frontendstrings['homeslide2desc'] ?? '',
    'home_homeslide3kicker' => $frontendstrings['homeslide3kicker'] ?? '',
    'home_homeslide3title' => $frontendstrings['homeslide3title'] ?? '',
    'home_homeslide3desc' => $frontendstrings['homeslide3desc'] ?? '',
    'home_browsecourses' => $frontendstrings['browsecourses'] ?? '',
    'home_mycourses' => $frontendstrings['mycourses'] ?? '',
    'home_signin' => $frontendstrings['signin'] ?? '',
    'home_cart' => $frontendstrings['cart'] ?? '',
    'home_viewbundles' => $frontendstrings['viewbundles'] ?? '',
    'home_opencart' => $frontendstrings['opencart'] ?? '',
    'home_findacourse' => $frontendstrings['findacourse'] ?? '',
    'home_checkout' => $frontendstrings['checkout'] ?? '',
    'home_search' => $frontendstrings['search'] ?? '',
    'home_searchbycoursename' => $frontendstrings['searchbycoursename'] ?? '',
    'home_type' => $frontendstrings['type'] ?? '',
    'home_alltypes' => $frontendstrings['alltypes'] ?? '',
    'home_free' => $frontendstrings['free'] ?? '',
    'home_paid' => $frontendstrings['paid'] ?? '',
    'home_reset' => $frontendstrings['reset'] ?? '',
    'home_pricelabel' => $frontendstrings['price'] ?? '',
    'home_purchased' => $frontendstrings['purchased'] ?? '',
    'home_incart' => $frontendstrings['incart'] ?? '',
    'home_addtocart' => $frontendstrings['addtocart'] ?? '',
    'home_nocoursesavailable' => $frontendstrings['nocoursesavailable'] ?? '',
    'home_availablebundles' => $frontendstrings['availablebundles'] ?? '',
    'home_bundlesdesc' => $frontendstrings['bundlesdesc'] ?? '',
    'home_bundle' => $frontendstrings['bundle'] ?? '',
    'home_nobundlesavailable' => $frontendstrings['nobundlesavailable'] ?? '',
    'home_nocoursesmatch' => $frontendstrings['nocoursesmatch'] ?? '',
    'bundles' => $bundles,
    'hasbundles' => !empty($bundles),
    'products' => $products,
    'hasproducts' => !empty($products),
    'isloggedin' => $isloggedin,
    'cartcount' => local_elearning_system_cart_count($SESSION->local_elearning_system_cart),
   'carturl' => (new moodle_url('/local/elearning_system/cart.php', ['lang' => $lang]))->out(false),
'mycoursesurl' => (new moodle_url('/local/elearning_system/my_courses.php', ['lang' => $lang]))->out(false),
    'loginurl' => $authurl,
    'issiteadmin' => is_siteadmin(),
    'admindashboardurl' => $admindashboardurl,
    'accounturl' => (new moodle_url('/my/'))->out(false),
    'chatbotendpoint' => (new moodle_url('/local/elearning_system/chatbot.php'))->out(false),
    'sesskey' => sesskey(),
    'home_admindashboard' => get_string('admin_dashboard', 'local_elearning_system'),
'home_sitepresentation' => get_string('sitepresentation', 'local_elearning_system'),
'home_slide1alt' => get_string('slide1alt', 'local_elearning_system'),
'home_slide2alt' => get_string('slide2alt', 'local_elearning_system'),
'home_slide3alt' => get_string('slide3alt', 'local_elearning_system'),
'home_carouselnavigation' => get_string('carouselnavigation', 'local_elearning_system'),
'home_previousslide' => get_string('previousslide', 'local_elearning_system'),
'home_nextslide' => get_string('nextslide', 'local_elearning_system'),
'home_noimage' => get_string('noimage', 'local_elearning_system'),
'home_gotoslide' => get_string('gotoslide', 'local_elearning_system'),
]);

echo $OUTPUT->footer();