<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');
require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/my_courses.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Mes cours');
$PAGE->set_heading('Mes cours');

global $DB, $USER, $CFG;
function local_elearning_system_my_courses_plugin_db(): mysqli {
    return \local_elearning_system\plugin_db::get();
}

function local_elearning_system_my_courses_get_orders(int $userid): array {
    $db = local_elearning_system_my_courses_plugin_db();

    $stmt = $db->prepare("
        SELECT o.id, o.userid, o.productid, o.amount, o.timecreated,
               o.expiresat, o.durationmonths,
               p.id AS productid, p.name AS productname, p.courseid, p.isbundle, p.bundleitems, p.image,
               p.price, p.saleprice, p.status, p.type
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

    $records = [];
    while ($row = $result->fetch_object()) {
        $records[(int)$row->id] = $row;
    }

    $stmt->close();

    return $records;
}

function local_elearning_system_my_courses_get_products_by_ids(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        return [];
    }

    $db = local_elearning_system_my_courses_plugin_db();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $db->prepare("SELECT * FROM el_products WHERE id IN ($placeholders)");
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

function local_elearning_system_my_courses_get_all_products(): array {
    $db = local_elearning_system_my_courses_plugin_db();

    $result = $db->query("SELECT * FROM el_products ORDER BY id DESC");

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $products = [];
    while ($row = $result->fetch_object()) {
        $products[(int)$row->id] = $row;
    }

    return $products;
}

function local_elearning_system_my_courses_product_has_active_purchase(int $userid, int $productid): bool {
    $db = local_elearning_system_my_courses_plugin_db();

    $stmt = $db->prepare("
        SELECT id, timecreated, durationmonths, expiresat
          FROM el_orders
         WHERE userid = ? AND productid = ?
      ORDER BY id DESC
         LIMIT 1
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param('ii', $userid, $productid);
    $stmt->execute();

    $result = $stmt->get_result();
    $order = $result ? $result->fetch_object() : null;

    $stmt->close();

    if (!$order) {
        return false;
    }

    $durationmonths = max(1, (int)($order->durationmonths ?? 1));
    $expiresat = !empty($order->expiresat)
        ? (int)$order->expiresat
        : strtotime('+' . $durationmonths . ' months', (int)$order->timecreated);

    return $expiresat === false || $expiresat <= 0 || $expiresat > time();
}

$usercontext = local_elearning_system_get_effective_user_context((int)$USER->id, $DB);
$targetuserid = (int)$usercontext['targetuserid'];
$isparentaccount = !empty($usercontext['isparentaccount']);
$targetfullname = trim((string)($usercontext['targetfullname'] ?? ''));

/**
 * Resolve image URL for a product, falling back to the course overview image.
 *
 * @param string|null $productimagepath Product image path stored in DB.
 * @param int $courseid Related course id.
 * @return array [string $url, bool $hasimage]
 */
function local_elearning_system_resolve_product_or_course_image($productimagepath, $courseid) {
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
$coursesbyid = [];

$records = local_elearning_system_my_courses_get_orders($targetuserid);
$ordercolumns = [
    'expiresat' => true,
    'durationmonths' => true,
];

if (!empty($records)) {
    foreach ($records as $r) {
        $isactiveorder = local_elearning_system_is_order_active($r, $ordercolumns ?? []);
        $isbundle = !empty($r->isbundle);
        $courseid = !empty($r->courseid) ? (int)$r->courseid : 0;
        $coursename = '';
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', IGNORE_MISSING);
    if ($course) {
        $coursename = $course->fullname;
    }
}

$hascourse = $courseid > 0 && $coursename !== '';
        $bundleproductsdisplay = '';
        
        [$productimage, $hasproductimage] = local_elearning_system_resolve_product_or_course_image(
            $r->image ?? '',
            $courseid
        );

        if ($isactiveorder && $hascourse && !isset($coursesbyid[$courseid])) {
            $coursesbyid[$courseid] = [
                'courseid' => $courseid,
                'coursename' => format_string($coursename),
                'productname' => !empty($r->productname) ? format_string($r->productname) : '-',
                'showproductname' => true,
                'productimage' => $productimage,
                'hasproductimage' => $hasproductimage,
                'courseurl' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'purchaseat' => userdate((int)$r->timecreated),
            ];
        }

        if ($isactiveorder && $isbundle && !empty($r->bundleitems)) {
            $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$r->bundleitems)))));
            if (!empty($bundleitemids)) {
                $bundleproducts = local_elearning_system_my_courses_get_products_by_ids($bundleitemids);
                $bundlenames = [];
                foreach ($bundleproducts as $bundleproduct) {
                    $bundlenames[] = format_string($bundleproduct->name);

                    if (empty($bundleproduct->courseid)) {
                        continue;
                    }

                    $bundlecourseid = (int)$bundleproduct->courseid;
                    if ($bundlecourseid <= 0 || isset($coursesbyid[$bundlecourseid])) {
                        continue;
                    }

                    $bundlecourse = $DB->get_record('course', ['id' => $bundlecourseid], 'id,fullname', IGNORE_MISSING);
                    if (!$bundlecourse) {
                        continue;
                    }

                    [$bundleproductimage, $hasbundleproductimage] = local_elearning_system_resolve_product_or_course_image(
                        $bundleproduct->image ?? '',
                        $bundlecourseid
                    );

                    if (!$hasbundleproductimage && $hasproductimage) {
                        // Fallback to bundle image so each course card has a visual.
                        $bundleproductimage = $productimage;
                        $hasbundleproductimage = true;
                    }

                    $coursesbyid[$bundlecourseid] = [
                        'courseid' => $bundlecourseid,
                        'coursename' => format_string($bundlecourse->fullname),
                        'productname' => format_string($bundleproduct->name),
                        'showproductname' => true,
                        'productimage' => $bundleproductimage,
                        'hasproductimage' => $hasbundleproductimage,
                        'courseurl' => (new moodle_url('/course/view.php', ['id' => $bundlecourseid]))->out(false),
                        'purchaseat' => userdate((int)$r->timecreated),
                    ];
                }

                if (!empty($bundlenames)) {
                    $bundleproductsdisplay = implode(', ', $bundlenames);
                }
            }

        }

        $orders[] = [
            'id' => (int)$r->id,
            'productname' => !empty($r->productname) ? format_string($r->productname) : '-',
            'bundleproducts' => $bundleproductsdisplay,
            'hasbundleproducts' => ($bundleproductsdisplay !== ''),
            'coursename' => $hascourse ? format_string($coursename) : '-',
            'hascourse' => $hascourse,
            'courseurl' => $hascourse ? (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false) : '',
            'amount' => local_elearning_system_format_price((float)$r->amount),
            'timecreated' => userdate((int)$r->timecreated),
            'durationmonths' => max(1, (int)($r->durationmonths ?? 1)),
            'isexpired' => !$isactiveorder,
            'detailsurl' => (new moodle_url('/local/elearning_system/order_detail.php', ['id' => (int)$r->id]))->out(false),
            'invoiceurl' => (new moodle_url('/local/elearning_system/invoice.php', ['id' => (int)$r->id]))->out(false),
        ];
    }
}

$courses = array_values($coursesbyid);

$availablecourses = [];
$eligibleproductscount = 0;
$allproducts = local_elearning_system_my_courses_get_all_products();

if (!empty($allproducts)) {
    foreach ($allproducts as $product) {
        $productid = (int)$product->id;
        if ($productid <= 0) {
            continue;
        }

        $originalprice = !empty($product->price) ? (float)$product->price : 0.0;
$saleprice = !empty($product->saleprice) ? (float)$product->saleprice : 0.0;

$displayprice = $saleprice > 0 ? $saleprice : $originalprice;
$hasdiscount = $originalprice > 0 && $saleprice > 0 && $originalprice > $saleprice;
        $status = strtolower(trim((string)($product->status ?? '')));
        $rawtype = strtolower(trim((string)($product->type ?? '')));
        if ($displayprice <= 0) {
            $type = 'free';
        } else if (in_array($rawtype, ['paid', 'subscription', 'subscroiption', 'subcription', 'subscribe', 'premium'])) {
            $type = 'paid';
        } else {
            $type = 'free';
        }

        if (empty($product->isbundle) && $type === 'paid' && $status !== 'publish') {
            continue;
        }

        $eligibleproductscount++;

        if (local_elearning_system_my_courses_product_has_active_purchase($targetuserid, $productid)) {
    continue;
}

        $courseid = !empty($product->courseid) ? (int)$product->courseid : 0;
        $coursename = '';
        if ($courseid > 0) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', IGNORE_MISSING);
            if ($course) {
                $coursename = format_string($course->fullname);
            }
        }

        [$productimage, $hasproductimage] = local_elearning_system_resolve_product_or_course_image(
            $product->image ?? '',
            $courseid
        );

        $availablecourses[] = [
    'id' => $productid,
    'productname' => format_string((string)$product->name),
    'coursename' => $coursename,
    'hascoursename' => $coursename !== '',
    'price' => local_elearning_system_format_price($displayprice),
    'displayprice' => local_elearning_system_format_price($displayprice),
    'originalprice' => $hasdiscount ? local_elearning_system_format_price($originalprice) : '',
    'saleprice' => $saleprice > 0 ? local_elearning_system_format_price($saleprice) : '',
    'hasdiscount' => $hasdiscount,
    'isfree' => $displayprice <= 0,
    'productimage' => $productimage,
    'hasproductimage' => $hasproductimage,
    'producturl' => (new moodle_url('/local/elearning_system/product.php', ['id' => $productid]))->out(false),
];
    }
}

echo $OUTPUT->header();
$expiredcourses = [];

if (isloggedin() && !isguestuser()) {
    $records = local_elearning_system_my_courses_get_orders((int)$USER->id);
    $seenproducts = [];

    foreach ($records as $record) {
        $productid = (int)$record->productid;

        if (isset($seenproducts[$productid])) {
            continue;
        }

        $seenproducts[$productid] = true;

        $durationmonths = max(1, (int)($record->durationmonths ?? 1));
        $expiresat = !empty($record->expiresat)
            ? (int)$record->expiresat
            : strtotime('+' . $durationmonths . ' months', (int)$record->timecreated);

        if ($expiresat !== false && $expiresat > 0 && $expiresat <= time()) {
            $expiredcourses[] = [
                'orderid' => (int)$record->id,
                'productid' => $productid,
                'coursename' => format_string($record->productname),
                'durationmonths' => $durationmonths,
                'durationlabel' => $durationmonths . ' mois',
                'expirationdate' => userdate($expiresat),
                'reactivateurl' => (new moodle_url('/local/elearning_system/reactivate.php', [
                    'productid' => $productid,
                    'orderid' => (int)$record->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }
    }
}
echo $OUTPUT->render_from_template('local_elearning_system/my_courses', [
    'courses' => $courses,
    'hascourses' => !empty($courses),
    'availablecourses' => $availablecourses,
    'hasavailablecourses' => !empty($availablecourses),
    'haseligibleproducts' => $eligibleproductscount > 0,
    'alleligibleproductspurchased' => $eligibleproductscount > 0 && empty($availablecourses),
    'orders' => $orders,
    'hasorders' => !empty($orders),
    'isparentaccount' => $isparentaccount,
    'targetfullname' => $targetfullname,
    'homeurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
    'carturl' => (new moodle_url('/local/elearning_system/cart.php'))->out(false),
    'commandesurl' => (new moodle_url('/local/elearning_system/commandes.php'))->out(false),
    'expiredcourses' => $expiredcourses,
'hasexpiredcourses' => !empty($expiredcourses),
]);
echo $OUTPUT->footer();
