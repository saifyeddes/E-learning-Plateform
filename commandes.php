<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/commandes.php');
local_elearning_system_apply_requested_language();

$lang = local_elearning_system_get_active_language();

$commandestexts = [
    'fr' => [
        'page_title' => 'Mes commandes',
        'orders_space' => 'Espace commandes',
        'subtitle' => 'Consultez vos achats, vérifiez le statut de vos accès et téléchargez vos factures PDF rapidement.',
        'my_courses' => 'Mes cours',
        'catalogue' => 'Catalogue',
        'parent_viewing' => 'Vous consultez les commandes de votre enfant :',
        'active' => 'Actif',
        'expired' => 'Expiré',
        'no_image' => 'Aucune image',
        'amount' => 'Montant',
        'details' => 'Détails',
        'invoice' => 'Facture',
        'empty_title' => 'Aucune commande trouvée',
        'empty_text' => 'Vous n’avez pas encore effectué d’achat sur la plateforme.',
        'order' => 'Commande',
        'download_pdf' => 'Télécharger PDF',
        'course' => 'Cours',
        'date' => 'Date',
        'duration' => 'Durée choisie',
        'months' => 'mois',
        'status' => 'Statut',
        'active_access' => 'Accès actif',
        'expired_access' => 'Accès expiré',
        'product_price' => 'Prix produit',
        'order_amount' => 'Montant commande',
        'description' => 'Description',
        'bundle_items' => 'Articles du pack',
        'access' => 'Accéder',
        'drawer_title' => 'Détails de la commande',
    ],
    'en' => [
        'page_title' => 'My orders',
        'orders_space' => 'Orders area',
        'subtitle' => 'View your purchases, check access status and quickly download your PDF invoices.',
        'my_courses' => 'My courses',
        'catalogue' => 'Catalogue',
        'parent_viewing' => 'You are viewing your child’s orders:',
        'active' => 'Active',
        'expired' => 'Expired',
        'no_image' => 'No image',
        'amount' => 'Amount',
        'details' => 'Details',
        'invoice' => 'Invoice',
        'empty_title' => 'No orders found',
        'empty_text' => 'You have not made any purchase on the platform yet.',
        'order' => 'Order',
        'download_pdf' => 'Download PDF',
        'course' => 'Course',
        'date' => 'Date',
        'duration' => 'Selected duration',
        'months' => 'month(s)',
        'status' => 'Status',
        'active_access' => 'Active access',
        'expired_access' => 'Expired access',
        'product_price' => 'Product price',
        'order_amount' => 'Order amount',
        'description' => 'Description',
        'bundle_items' => 'Bundle items',
        'access' => 'Access',
        'drawer_title' => 'Order details',
    ],
    'ar' => [
        'page_title' => 'طلباتي',
        'orders_space' => 'مساحة الطلبات',
        'subtitle' => 'اطّلع على مشترياتك، تحقق من حالة الوصول، وقم بتحميل فواتيرك بصيغة PDF بسرعة.',
        'my_courses' => 'دوراتي',
        'catalogue' => 'الفهرس',
        'parent_viewing' => 'أنت تطّلع على طلبات طفلك:',
        'active' => 'نشط',
        'expired' => 'منتهي',
        'no_image' => 'لا توجد صورة',
        'amount' => 'المبلغ',
        'details' => 'التفاصيل',
        'invoice' => 'الفاتورة',
        'empty_title' => 'لا توجد طلبات',
        'empty_text' => 'لم تقم بأي عملية شراء على المنصة حتى الآن.',
        'order' => 'الطلب',
        'download_pdf' => 'تحميل PDF',
        'course' => 'الدورة',
        'date' => 'التاريخ',
        'duration' => 'المدة المختارة',
        'months' => 'شهر',
        'status' => 'الحالة',
        'active_access' => 'وصول نشط',
        'expired_access' => 'وصول منتهي',
        'product_price' => 'سعر المنتج',
        'order_amount' => 'مبلغ الطلب',
        'description' => 'الوصف',
        'bundle_items' => 'عناصر الباقة',
        'access' => 'الدخول',
        'drawer_title' => 'تفاصيل الطلب',
    ],
];

$ct = $commandestexts[$lang] ?? $commandestexts['fr'];

$PAGE->set_title($ct['page_title']);
$PAGE->set_heading($ct['page_title']);
$PAGE->set_pagelayout('standard');


global $DB, $USER, $CFG;
function local_elearning_system_commandes_plugin_db(): mysqli {
    return \local_elearning_system\plugin_db::get();
}

function local_elearning_system_commandes_get_orders(int $userid): array {
    $db = local_elearning_system_commandes_plugin_db();

    $stmt = $db->prepare("
        SELECT o.id, o.userid, o.amount, o.timecreated, o.productid,
               o.expiresat, o.durationmonths,
               p.id AS productid,
               p.name AS productname,
               p.courseid,
               p.isbundle,
               p.bundleitems,
               p.image,
               p.description,
               p.price,
               p.saleprice
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
        $orders[(int)$row->id] = $row;
    }

    $stmt->close();

    return $orders;
}

function local_elearning_system_commandes_get_products_by_ids(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        return [];
    }

    $db = local_elearning_system_commandes_plugin_db();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $db->prepare("SELECT id, name, courseid, image FROM el_products WHERE id IN ($placeholders)");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param($types, ...$ids);
    $stmt->execute();

    $result = $stmt->get_result();

    $products = [];
    while ($row = $result->fetch_object()) {
        $products[(int)$row->id] = $row;
    }

    $stmt->close();

    return $products;
}

$usercontext = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
$targetuserid = (int)$usercontext['targetuserid'];
$isparentaccount = !empty($usercontext['isparentaccount']);
$targetfullname = trim((string)($usercontext['targetfullname'] ?? ''));

/**
 * Resolve image URL for a product, falling back to course overview image.
 *
 * @param string|null $productimagepath
 * @param int $courseid
 * @return array [string $url, bool $hasimage]
 */
function local_elearning_system_resolve_order_image($productimagepath, $courseid) {
    global $CFG;

    $url = '';
    $hasimage = false;

    if (!empty($productimagepath)) {
        $hasimage = true;
        if (filter_var($productimagepath, FILTER_VALIDATE_URL)) {
            $url = $productimagepath;
        } else if (strpos($productimagepath, '/') === 0) {
            $url = $CFG->wwwroot . $productimagepath;
        } else {
            $url = $CFG->wwwroot . '/local/elearning_system/uploads/' . $productimagepath;
        }
        return [$url, $hasimage];
    }

    if (!empty($courseid)) {
        $contextcourse = context_course::instance((int)$courseid, IGNORE_MISSING);
        if ($contextcourse) {
            $fs = get_file_storage();
            $overviewfiles = $fs->get_area_files(
                $contextcourse->id,
                'course',
                'overviewfiles',
                0,
                'itemid, filepath, filename',
                false
            );
            foreach ($overviewfiles as $file) {
                if ($file->is_valid_image()) {
                    $url = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename()
                    )->out(false);
                    $hasimage = true;
                    break;
                }
            }
        }
    }

    return [$url, $hasimage];
}

$orders = [];
$pageheading = $ct['page_title'];

if ($isparentaccount && $targetfullname !== '') {
    if ($lang === 'ar') {
        $pageheading = 'طلبات ' . $targetfullname;
    } else if ($lang === 'en') {
        $pageheading = $targetfullname . '’s orders';
    } else {
        $pageheading = 'Commandes de ' . $targetfullname;
    }
}

$records = local_elearning_system_commandes_get_orders($targetuserid);
$ordercolumns = [
    'expiresat' => true,
    'durationmonths' => true,
];

if (!empty($records)) {
    foreach ($records as $r) {
        $isactiveorder = local_elearning_system_is_order_active($r, $ordercolumns ?? []);
        $courseid = !empty($r->courseid) ? (int)$r->courseid : 0;
        [$productimage, $hasproductimage] = local_elearning_system_resolve_order_image(
            $r->image ?? '',
            $courseid
        );

        $isbundle = !empty($r->isbundle);
        
        // Get TVA for calculation
        $tvapercent = get_config('local_elearning_system', 'vat_percent');
        if ($tvapercent === false) {
            $tvapercent = 0;
        }
        $tvapercent = (float)$tvapercent;
        
        $subtotal = (float)$r->amount;
        $tax = $subtotal * ($tvapercent / 100);
        $total = $subtotal + $tax;

        $productbaseprice = !empty($r->price) ? (float)$r->price : 0.0;
        $productsaleprice = !empty($r->saleprice) ? (float)$r->saleprice : 0.0;
        $productdisplayprice = $productsaleprice > 0 ? $productsaleprice : $productbaseprice;
        
        // Fetch bundle items if bundle
        $bundleitems = [];
        if ($isbundle && !empty($r->bundleitems)) {
            $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$r->bundleitems)))));
            if (!empty($bundleitemids)) {
                $bundleproducts = local_elearning_system_commandes_get_products_by_ids($bundleitemids);
                
                foreach ($bundleproducts as $bundleproduct) {
                    [$bundleimage, $hasbundleimage] = local_elearning_system_resolve_order_image(
                        $bundleproduct->image ?? '',
                        (int)($bundleproduct->courseid ?? 0)
                    );

                    if (!$hasbundleimage && $hasproductimage) {
                        $bundleimage = $productimage;
                        $hasbundleimage = true;
                    }
                    
                    $itemdata = [
                        'id' => (int)$bundleproduct->id,
                        'name' => format_string($bundleproduct->name),
                        'image' => $bundleimage,
                        'hasimage' => $hasbundleimage,
                    ];
                    
                    if (!empty($bundleproduct->courseid)) {
                        $bundlecourse = $DB->get_record('course', ['id' => (int)$bundleproduct->courseid], 'id,fullname');
                        if ($bundlecourse) {
                            $itemdata['course'] = [
                                'id' => (int)$bundlecourse->id,
                                'name' => format_string($bundlecourse->fullname),
                                'url' => (new moodle_url('/course/view.php', ['id' => (int)$bundlecourse->id]))->out(false),
                            ];
                        }
                    }
                    
                    $bundleitems[] = $itemdata;
                }
            }
        }
        
        // Get course info
        $coursename = '';

if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', IGNORE_MISSING);
    if ($course) {
        $coursename = $course->fullname;
    }
}

$hascourse = $courseid > 0 && $coursename !== '';
        
        $orders[] = [
            'id' => (int)$r->id,
            'productname' => !empty($r->productname) ? format_string($r->productname) : '-',
            'coursename' => $hascourse ? format_string($coursename) : '-',
            'hascourse' => $hascourse,
            'courseurl' => $hascourse ? (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false) : '',
            'productimage' => $productimage,
            'hasproductimage' => $hasproductimage,
            'amount' => local_elearning_system_format_price($subtotal),
'productprice' => local_elearning_system_format_price($productdisplayprice),
'subtotal' => local_elearning_system_format_price($subtotal),
'taxamount' => local_elearning_system_format_price($tax),
'total' => local_elearning_system_format_price($total),
            'hastvapercent' => ($tvapercent > 0),
            'timecreated' => userdate((int)$r->timecreated),
            'durationmonths' => max(1, (int)($r->durationmonths ?? 1)),
            'isactiveorder' => $isactiveorder,
            'isexpiredorder' => !$isactiveorder,
            'description' => !empty($r->description) ? format_text($r->description, FORMAT_HTML) : '',
            'hasdescription' => !empty($r->description),
            'isbundle' => $isbundle,
            'bundleitems' => $bundleitems,
            'hasbundleitems' => !empty($bundleitems),
            'pdfurl' => (new moodle_url('/local/elearning_system/invoice.php', ['id' => (int)$r->id, 'pdf' => 1]))->out(false),
            'isactiveorder' => $isactiveorder,
            'statuslabel' => $isactiveorder ? $ct['active'] : $ct['expired'],
            'activeaccesslabel' => $ct['active_access'],
            'expiredaccesslabel' => $ct['expired_access'],
            'durationlabel' => max(1, (int)($r->durationmonths ?? 1)) . ' ' . $ct['months'],       
            ];
    }
}

$templatedata = [
    'orders' => $orders,
    'hasorders' => !empty($orders),

    'isparentaccount' => $isparentaccount,
    'targetfullname' => $targetfullname,
    'pageheading' => $pageheading,

    'isrtl' => ($lang === 'ar'),

    'homeurl' => (new moodle_url('/local/elearning_system/index.php', [
        'lang' => $lang,
    ]))->out(false),

    'mycoursesurl' => (new moodle_url('/local/elearning_system/my_courses.php', [
        'lang' => $lang,
    ]))->out(false),

    't_orders_space' => $ct['orders_space'],
    't_subtitle' => $ct['subtitle'],
    't_my_courses' => $ct['my_courses'],
    't_catalogue' => $ct['catalogue'],
    't_parent_viewing' => $ct['parent_viewing'],
    't_active' => $ct['active'],
    't_expired' => $ct['expired'],
    't_no_image' => $ct['no_image'],
    't_amount' => $ct['amount'],
    't_details' => $ct['details'],
    't_invoice' => $ct['invoice'],
    't_empty_title' => $ct['empty_title'],
    't_empty_text' => $ct['empty_text'],
    't_order' => $ct['order'],
    't_download_pdf' => $ct['download_pdf'],
    't_course' => $ct['course'],
    't_date' => $ct['date'],
    't_duration' => $ct['duration'],
    't_months' => $ct['months'],
    't_status' => $ct['status'],
    't_active_access' => $ct['active_access'],
    't_expired_access' => $ct['expired_access'],
    't_product_price' => $ct['product_price'],
    't_order_amount' => $ct['order_amount'],
    't_description' => $ct['description'],
    't_bundle_items' => $ct['bundle_items'],
    't_access' => $ct['access'],
    't_drawer_title' => $ct['drawer_title'],
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/commandes', $templatedata);
echo $OUTPUT->footer();
