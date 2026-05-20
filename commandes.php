<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/commandes.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Mes commandes');
$PAGE->set_heading('Mes commandes');

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
$pageheading = 'Mes commandes';

if ($isparentaccount && $targetfullname !== '') {
    $pageheading = 'Commandes de ' . $targetfullname;
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
        ];
    }
}

$templatedata = [
    'orders' => $orders,
    'hasorders' => !empty($orders),
    'isparentaccount' => $isparentaccount,
    'targetfullname' => $targetfullname,
    'pageheading' => $pageheading,
    'homeurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
    'mycoursesurl' => (new moodle_url('/local/elearning_system/my_courses.php'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/commandes', $templatedata);
echo $OUTPUT->footer();
