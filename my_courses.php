<?php

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/my_courses.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Mes cours');
$PAGE->set_heading('Mes cours');

global $DB, $USER, $CFG;

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

if ($DB->get_manager()->table_exists('elearning_orders')) {
    local_elearning_system_cleanup_expired_orders_for_user($targetuserid, $DB);
    $ordercolumns = $DB->get_columns('elearning_orders');

    $expireselect = isset($ordercolumns['expiresat']) ? 'o.expiresat, o.durationmonths' : '0 AS expiresat, 1 AS durationmonths';
    $sql = "SELECT o.id, o.amount, o.timecreated,
                p.id AS productid, p.name AS productname, p.courseid, p.isbundle, p.bundleitems, p.image,
                {$expireselect},
                   c.fullname AS coursename
              FROM {elearning_orders} o
         LEFT JOIN {elearning_products} p ON p.id = o.productid
         LEFT JOIN {course} c ON c.id = p.courseid
             WHERE o.userid = :userid
          ORDER BY o.id DESC";

    $records = $DB->get_records_sql($sql, ['userid' => $targetuserid]);

    foreach ($records as $r) {
        $isactiveorder = local_elearning_system_is_order_active($r, $ordercolumns ?? []);
        $isbundle = !empty($r->isbundle);
        $courseid = !empty($r->courseid) ? (int)$r->courseid : 0;
        $hascourse = $courseid > 0 && !empty($r->coursename);
        $bundleproductsdisplay = '';
        
        [$productimage, $hasproductimage] = local_elearning_system_resolve_product_or_course_image(
            $r->image ?? '',
            $courseid
        );

        if ($isactiveorder && $hascourse && !isset($coursesbyid[$courseid])) {
            $coursesbyid[$courseid] = [
                'courseid' => $courseid,
                'coursename' => format_string($r->coursename),
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
                $bundleproducts = $DB->get_records_list('elearning_products', 'id', $bundleitemids, '', 'id,name,courseid,image');
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
            'coursename' => $hascourse ? format_string($r->coursename) : '-',
            'hascourse' => $hascourse,
            'courseurl' => $hascourse ? (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false) : '',
            'amount' => number_format((float)$r->amount, 2),
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
if ($DB->get_manager()->table_exists('elearning_products')) {
    $allproducts = $DB->get_records('elearning_products', null, 'id DESC');
    foreach ($allproducts as $product) {
        $productid = (int)$product->id;
        if ($productid <= 0) {
            continue;
        }

        $price = !empty($product->price) ? (float)$product->price : 0.0;
        $saleprice = !empty($product->saleprice) ? (float)$product->saleprice : 0.0;
        $displayprice = $saleprice > 0 ? $saleprice : $price;
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

        if (local_elearning_system_is_product_covered_by_active_purchase($targetuserid, $productid, $DB)) {
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
            'price' => number_format($displayprice, 2),
            'isfree' => $displayprice <= 0,
            'productimage' => $productimage,
            'hasproductimage' => $hasproductimage,
            'producturl' => (new moodle_url('/local/elearning_system/product.php', ['id' => $productid]))->out(false),
        ];
    }
}

echo $OUTPUT->header();
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
]);
echo $OUTPUT->footer();
