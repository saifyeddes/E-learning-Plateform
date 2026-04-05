<?php

require('../../config.php');
require_login();

$orderid = required_param('id', PARAM_INT);

global $DB, $USER, $CFG, $OUTPUT;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/order_detail.php', ['id' => $orderid]);
$PAGE->set_pagelayout('standard');

// Fetch order
$order = null;
if ($DB->get_manager()->table_exists('elearning_orders')) {
    $order = $DB->get_record('elearning_orders', ['id' => $orderid]);
    
    if (!$order) {
        throw new moodle_exception('ordernotfound', 'local_elearning_system', '', null, 'Order not found');
    }
    
    // Check if order belongs to current user
    if ((int)$order->userid !== (int)$USER->id && !has_capability('local/elearning_system:manage', $context)) {
        throw new moodle_exception('accessdenied', 'admin');
    }
}

// Build order details
$orderdetails = [
    'id' => (int)$order->id,
    'timecreated' => userdate((int)$order->timecreated),
    'amount' => number_format((float)$order->amount, 2),
    'backtomycoursesurl' => (new moodle_url('/local/elearning_system/my_courses.php'))->out(false),
    'invoiceurl' => (new moodle_url('/local/elearning_system/invoice.php', ['id' => (int)$order->id, 'pdf' => 1]))->out(false),
];

// Fetch product info
$product = null;
if (!empty($order->productid)) {
    $product = $DB->get_record('elearning_products', ['id' => (int)$order->productid]);
    
    if ($product) {
        // Resolve product image
        $productimage = '';
        $hasproductimage = false;
        if (!empty($product->image)) {
            $hasproductimage = true;
            if (filter_var($product->image, FILTER_VALIDATE_URL)) {
                $productimage = $product->image;
            } else if (strpos($product->image, '/') === 0) {
                $productimage = $CFG->wwwroot . $product->image;
            } else {
                $productimage = $CFG->wwwroot . '/local/elearning_system/uploads/' . $product->image;
            }
        }
        
        $isbundle = !empty($product->isbundle);
        $type = trim(strtolower($product->type ?? 'free'));
        $price = !empty($product->price) ? (float)$product->price : 0.0;
        $saleprice = !empty($product->saleprice) ? (float)$product->saleprice : 0.0;
        $displayprice = $saleprice > 0 ? $saleprice : $price;
        
        $orderdetails['product'] = [
            'id' => (int)$product->id,
            'name' => format_string($product->name),
            'description' => !empty($product->description) ? format_text($product->description, FORMAT_HTML) : '',
            'hasdescription' => !empty($product->description),
            'image' => $productimage,
            'hasimage' => $hasproductimage,
            'type' => $type,
            'price' => number_format($displayprice, 2),
            'isbundle' => $isbundle,
        ];
        
        // Fetch course details
        if (!empty($product->courseid)) {
            $course = $DB->get_record('course', ['id' => (int)$product->courseid]);
            if ($course) {
                $orderdetails['product']['course'] = [
                    'id' => (int)$course->id,
                    'name' => format_string($course->fullname),
                    'url' => (new moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false),
                ];
            }
        }
        
        // If bundle, fetch bundle items
        if ($isbundle && !empty($product->bundleitems)) {
            $bundleitemids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$product->bundleitems)))));
            if (!empty($bundleitemids)) {
                ['sql' => $insql, 'params' => $params] = $DB->get_in_or_equal($bundleitemids, SQL_PARAMS_NAMED);
                $bundleproducts = $DB->get_records_select('elearning_products', 'id ' . $insql, $params, '', 'id,name,courseid,image');
                
                $bundleitems = [];
                foreach ($bundleproducts as $bundleproduct) {
                    $bundleimage = '';
                    $hasbundleimage = false;
                    if (!empty($bundleproduct->image)) {
                        $hasbundleimage = true;
                        if (filter_var($bundleproduct->image, FILTER_VALIDATE_URL)) {
                            $bundleimage = $bundleproduct->image;
                        } else if (strpos($bundleproduct->image, '/') === 0) {
                            $bundleimage = $CFG->wwwroot . $bundleproduct->image;
                        } else {
                            $bundleimage = $CFG->wwwroot . '/local/elearning_system/uploads/' . $bundleproduct->image;
                        }
                    }
                    
                    $bundleitemsdata = [
                        'id' => (int)$bundleproduct->id,
                        'name' => format_string($bundleproduct->name),
                        'image' => $bundleimage,
                        'hasimage' => $hasbundleimage,
                    ];
                    
                    if (!empty($bundleproduct->courseid)) {
                        $bundlecourse = $DB->get_record('course', ['id' => (int)$bundleproduct->courseid], 'id,fullname');
                        if ($bundlecourse) {
                            $bundleitemsdata['course'] = [
                                'id' => (int)$bundlecourse->id,
                                'name' => format_string($bundlecourse->fullname),
                                'url' => (new moodle_url('/course/view.php', ['id' => (int)$bundlecourse->id]))->out(false),
                            ];
                        }
                    }
                    
                    $bundleitems[] = $bundleitemsdata;
                }
                
                if (!empty($bundleitems)) {
                    $orderdetails['product']['bundleitems'] = $bundleitems;
                    $orderdetails['product']['hasbundleitems'] = true;
                }
            }
        }
    }
}

// Get TVA config if available
$tvapercent = get_config('local_elearning_system', 'vat_percent');
if ($tvapercent === false) {
    $tvapercent = 0;
}
$tvapercent = (float)$tvapercent;

// Calculate tax
$subtotal = (float)$order->amount;
$tax = $subtotal * ($tvapercent / 100);

$orderdetails['subtotal'] = number_format($subtotal, 2);
$orderdetails['tvapercent'] = number_format($tvapercent, 1);
$orderdetails['taxamount'] = number_format($tax, 2);
$orderdetails['grandtotal'] = number_format($subtotal + $tax, 2);
$orderdetails['hasproduct'] = ($product !== null);
$orderdetails['hastvapercent'] = ($tvapercent > 0);

$PAGE->set_title('Détails de la commande #' . $orderid);
$PAGE->set_heading('Détails de la commande');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/order_detail', $orderdetails);
echo $OUTPUT->footer();
