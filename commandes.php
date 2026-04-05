<?php

require('../../config.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/commandes.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Mes commandes');
$PAGE->set_heading('Mes commandes');

global $DB, $USER, $CFG;

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

if ($DB->get_manager()->table_exists('elearning_orders')) {
        $sql = "SELECT o.id, o.userid, o.amount, o.timecreated, o.productid,
                    p.id AS productid, p.name AS productname, p.courseid, p.isbundle, p.bundleitems, p.image, p.description, p.price, p.saleprice,
                   c.fullname AS coursename
              FROM {elearning_orders} o
         LEFT JOIN {elearning_products} p ON p.id = o.productid
         LEFT JOIN {course} c ON c.id = p.courseid
             WHERE o.userid = :userid
          ORDER BY o.id DESC";

    $records = $DB->get_records_sql($sql, ['userid' => (int)$USER->id]);

    foreach ($records as $r) {
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
                [$insql, $params] = $DB->get_in_or_equal($bundleitemids, SQL_PARAMS_NAMED);
                $bundleproducts = $DB->get_records_select('elearning_products', 'id ' . $insql, $params, '', 'id,name,courseid,image');
                
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
        $hascourse = $courseid > 0 && !empty($r->coursename);
        
        $orders[] = [
            'id' => (int)$r->id,
            'productname' => !empty($r->productname) ? format_string($r->productname) : '-',
            'coursename' => $hascourse ? format_string($r->coursename) : '-',
            'hascourse' => $hascourse,
            'courseurl' => $hascourse ? (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false) : '',
            'productimage' => $productimage,
            'hasproductimage' => $hasproductimage,
            'amount' => number_format($subtotal, 2),
            'productprice' => number_format($productdisplayprice, 2),
            'subtotal' => number_format($subtotal, 2),
            'tvapercent' => number_format($tvapercent, 1),
            'taxamount' => number_format($tax, 2),
            'total' => number_format($total, 2),
            'hastvapercent' => ($tvapercent > 0),
            'timecreated' => userdate((int)$r->timecreated),
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
    'homeurl' => (new moodle_url('/local/elearning_system/index.php'))->out(false),
    'mycoursesurl' => (new moodle_url('/local/elearning_system/my_courses.php'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/commandes', $templatedata);
echo $OUTPUT->footer();
