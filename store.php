<?php

require('../../config.php');

// NO LOGIN REQUIRED - Public store page
$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url('/local/elearning_system/store.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Store');
$PAGE->set_heading('Store');

global $DB, $CFG, $USER;

// =============================
// GET ITEMS
// Show all free items and only published paid items.
// Split into Available Courses and Available Products.
// =============================
$records = $DB->get_records('elearning_products', null, 'id DESC');

$products = [];
$courses = [];

$courseids = [];
foreach ($records as $record) {
    if (!empty($record->courseid)) {
        $courseids[] = (int)$record->courseid;
    }
}
$courseids = array_values(array_unique(array_filter($courseids)));

$coursesmap = [];
if (!empty($courseids)) {
    [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
    $courserecords = $DB->get_records_select('course', 'id ' . $insql, $params, '', 'id, fullname');
    foreach ($courserecords as $courserecord) {
        $coursesmap[(int)$courserecord->id] = format_string($courserecord->fullname);
    }
}

foreach ($records as $r) {

    $price = !empty($r->price) ? (float)$r->price : 0.0;
    $saleprice = !empty($r->saleprice) ? (float)$r->saleprice : 0.0;
    $displayprice = $saleprice > 0 ? $saleprice : $price;

    $type = trim(strtolower($r->type ?? 'free'));
    $status = trim(strtolower($r->status ?? 'draft'));

    // Normalize type
    if (!in_array($type, ['free', 'paid'])) {
        $type = ($displayprice > 0) ? 'paid' : 'free';
    }

    $isbundle = !empty($r->isbundle);

    // Apply visibility rules: show free always; paid non-bundle only if published.
    if (!$isbundle && $type === 'paid' && $status !== 'publish') {
        continue;
    }

    // Resolve image URL
    $image = '';
    if (!empty($r->image)) {
        if (filter_var($r->image, FILTER_VALIDATE_URL)) {
            $image = $r->image;
        } else {
            $image = $CFG->wwwroot . '/local/elearning_system/' . $r->image;
        }
    }

    $item = [
        'id' => (int)$r->id,
        'name' => $r->name,
        'image' => $image,
        'hasimage' => !empty($r->image),
        'coursefullname' => !empty($coursesmap[(int)$r->courseid]) ? $coursesmap[(int)$r->courseid] : '',
        'hascoursefullname' => !empty($coursesmap[(int)$r->courseid]),
        'type' => $type,
        'isfree' => ($type === 'free'),
        'ispaid' => ($type === 'paid'),
        'price' => number_format($displayprice, 2),
        'saleprice' => !empty($r->saleprice) ? number_format($r->saleprice, 2) : '',
        'producturl' => (new moodle_url('/local/elearning_system/product.php', ['id' => (int)$r->id]))->out(false),
    ];

    if (!empty($r->courseid)) {
        $courses[] = $item;
    } else {
        $products[] = $item;
    }
}

// Check if user is logged in
$isloggedin = isloggedin() && !isguestuser();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/store', [
    'courses' => $courses,
    'hascourses' => !empty($courses),
    'products' => $products,
    'hasproducts' => !empty($products),
    'isloggedin' => $isloggedin,
    'loginurl' => (new moodle_url('/login/index.php'))->out(false),
]);
echo $OUTPUT->footer();
