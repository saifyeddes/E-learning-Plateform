<?php

require('../../config.php');

$productid = required_param('id', PARAM_INT);
redirect(new moodle_url('/local/elearning_system/product.php', ['id' => $productid]));
exit;

// NO LOGIN REQUIRED - Public product detail page
$context = context_system::instance();
$PAGE->set_context($context);

$productid = required_param('id', PARAM_INT);

$PAGE->set_url('/local/elearning_system/store_product.php', ['id' => $productid]);
$PAGE->set_pagelayout('standard');

global $DB, $CFG, $USER;

// =============================
// GET SINGLE PRODUCT
// =============================
$product = $DB->get_record('elearning_products', ['id' => $productid]);

if (!$product) {
    throw new moodle_exception('Product not found', 'local_elearning_system');
}

$price = !empty($product->price) ? (float)$product->price : 0.0;
$saleprice = !empty($product->saleprice) ? (float)$product->saleprice : 0.0;
$displayprice = $saleprice > 0 ? $saleprice : $price;

$type = trim(strtolower($product->type ?? 'free'));
$status = trim(strtolower($product->status ?? 'draft'));

// Normalize type
if (!in_array($type, ['free', 'paid'])) {
    $type = ($displayprice > 0) ? 'paid' : 'free';
}

// Apply visibility rules: paid products must be published
if ($type === 'paid' && $status !== 'publish') {
    throw new moodle_exception('This product is not available', 'local_elearning_system');
}

// Resolve image URL
$image = '';
if (!empty($product->image)) {
    if (filter_var($product->image, FILTER_VALIDATE_URL)) {
        $image = $product->image;
    } else {
        $image = $CFG->wwwroot . '/local/elearning_system/' . $product->image;
    }
}

// Get category name
$categoryname = '';
if (!empty($product->categoryid)) {
    $category = $DB->get_record('course_categories', ['id' => $product->categoryid]);
    if ($category) {
        $categoryname = $category->name;
    }
}

// Get course info
$coursename = '';
$courseurl = '';
$hascourse = false;
if (!empty($product->courseid)) {
    $course = $DB->get_record('course', ['id' => $product->courseid]);
    if ($course) {
        $hascourse = true;
        $coursename = $course->fullname;
        $courseurl = (new moodle_url('/course/view.php', ['id' => $product->courseid]))->out(false);
    }
}

$isloggedin = isloggedin() && !isguestuser();
$authurl = (new moodle_url('/local/elearning_system/auth.php', ['return' => (new moodle_url('/local/elearning_system/product.php', ['id' => $productid]))->out(false)]))->out(false);

$PAGE->set_title($product->name);
$PAGE->set_heading($product->name);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/store_product_details', [
    'name' => $product->name,
    'description' => $product->description ?? '',
    'image' => $image,
    'hasimage' => !empty($product->image),
    'type' => $type,
    'isfree' => ($type === 'free'),
    'ispaid' => ($type === 'paid'),
    'price' => number_format($displayprice, 2),
    'saleprice' => !empty($product->saleprice) ? number_format($product->saleprice, 2) : '',
    'categoryname' => $categoryname,
    'coursename' => $coursename,
    'hascourse' => $hascourse,
    'courseurl' => $courseurl,
    'isloggedin' => $isloggedin,
    'loginurl' => $authurl,
    'backurl' => (new moodle_url('/local/elearning_system/store.php'))->out(false),
]);
echo $OUTPUT->footer();
