<?php

require('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/products.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Products');
$PAGE->set_heading('Products');

global $DB, $CFG;

// PARAMS
$editid  = optional_param('edit', 0, PARAM_INT);
$editbundleid = optional_param('editbundle', 0, PARAM_INT);
$deleteid = optional_param('id', 0, PARAM_INT);
$addnew  = optional_param('addnew', 0, PARAM_INT);
$addbundle = optional_param('addbundle', 0, PARAM_INT);
$searchquery = trim((string)optional_param('search', '', PARAM_TEXT));
$selectedcategoryid = optional_param('categoryid', 0, PARAM_INT);
$selectedcourseid = optional_param('courseid', 0, PARAM_INT);
$selectedtypefilter = optional_param('typefilter', '', PARAM_ALPHA);
$selectedstatusfilter = optional_param('statusfilter', '', PARAM_ALPHA);

$listparams = [];
if ($searchquery !== '') {
    $listparams['search'] = $searchquery;
}
if ($selectedcategoryid !== 0) {
    $listparams['categoryid'] = $selectedcategoryid;
}
if ($selectedcourseid !== 0) {
    $listparams['courseid'] = $selectedcourseid;
}
if ($selectedtypefilter !== '') {
    $listparams['typefilter'] = $selectedtypefilter;
}
if ($selectedstatusfilter !== '') {
    $listparams['statusfilter'] = $selectedstatusfilter;
}

// DELETE
if ($deleteid && confirm_sesskey()) {
    $DB->delete_records('elearning_products', ['id'=>$deleteid]);
    redirect(new moodle_url('/local/elearning_system/admin/products.php', $listparams));
}

// INSERT / UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_sesskey();

    $bundleaction = optional_param('bundleaction', '', PARAM_ALPHA);
    if ($bundleaction === 'createbundle' || $bundleaction === 'updatebundle') {
        $bundleid = optional_param('bundleid', 0, PARAM_INT);
        $bundletitle = trim((string)optional_param('bundletitle', '', PARAM_TEXT));
        $bundledescription = optional_param('bundledescription', '', PARAM_RAW);
        $bundletype = strtolower(trim((string)optional_param('bundletype', 'free', PARAM_TEXT)));
        $bundlestatus = optional_param('bundlestatus', 'draft', PARAM_ALPHA);
        $bundleoriginalprice = optional_param('bundleoriginalprice', 0, PARAM_FLOAT);
        $bundlesaleprice = optional_param('bundlesaleprice', 0, PARAM_FLOAT);
        $bundleitems = optional_param_array('bundleitems', [], PARAM_INT);
        $bundleitemsjoined = trim((string)optional_param('bundleitemsjoined', '', PARAM_RAW_TRIMMED));

        if (empty($bundleitems) && $bundleitemsjoined !== '') {
            $bundleitems = array_map('intval', explode(',', $bundleitemsjoined));
        }

        $bundleitems = array_values(array_unique(array_filter(array_map('intval', $bundleitems))));
        if ($bundletitle === '') {
            \core\notification::add('Bundle title is required.', \core\output\notification::NOTIFY_ERROR);
            redirect(new moodle_url('/local/elearning_system/admin/products.php', $listparams));
        }

        if (empty($bundleitems)) {
            \core\notification::add('Please add at least one product to the bundle.', \core\output\notification::NOTIFY_ERROR);
            redirect(new moodle_url('/local/elearning_system/admin/products.php', $listparams));
        }

        $redirectbundleparams = $listparams + ['addbundle' => 1];
        if ($bundleaction === 'updatebundle' && $bundleid > 0) {
            $redirectbundleparams = $listparams + ['editbundle' => $bundleid];
        }

        $bundlerecord = new stdClass();
        $bundlerecord->name = $bundletitle;
        $bundlerecord->categoryid = 0;
        $bundlerecord->courseid = 0;
        $bundlerecord->description = $bundledescription;
        $bundlerecord->type = ($bundletype === 'paid') ? 'paid' : 'free';
        $bundlerecord->price = ($bundlerecord->type === 'paid') ? $bundleoriginalprice : 0;
        $bundlerecord->saleprice = ($bundlerecord->type === 'paid') ? $bundlesaleprice : 0;
        $bundlerecord->status = ($bundlestatus === 'publish') ? 'publish' : 'draft';
        $bundlerecord->isbundle = 1;
        $bundlerecord->bundleitems = implode(',', $bundleitems);

        if (!empty($_FILES['bundleimage']['name'])) {
            $uploadedfile = $_FILES['bundleimage'];
            if (!empty($uploadedfile['error']) && (int)$uploadedfile['error'] !== UPLOAD_ERR_OK) {
                \core\notification::add('Image upload failed. Please try again with a valid image file.', \core\output\notification::NOTIFY_ERROR);
                redirect(new moodle_url('/local/elearning_system/admin/products.php', $redirectbundleparams));
            }

            $tmpfilepath = $uploadedfile['tmp_name'] ?? '';
            $fileisimage = false;
            if (!empty($tmpfilepath) && is_uploaded_file($tmpfilepath)) {
                $imagesize = @getimagesize($tmpfilepath);
                $mimetype = '';
                if (function_exists('mime_content_type')) {
                    $mimetype = (string)@mime_content_type($tmpfilepath);
                }
                $fileisimage = (!empty($imagesize) || strpos($mimetype, 'image/') === 0);
            }

            if (!$fileisimage) {
                \core\notification::add('Only image files are allowed (no PDF or video).', \core\output\notification::NOTIFY_ERROR);
                redirect(new moodle_url('/local/elearning_system/admin/products.php', $redirectbundleparams));
            }

            $dir = $CFG->dirroot.'/local/elearning_system/uploads/';
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $filename = time().'_'.clean_param($uploadedfile['name'], PARAM_FILE);
            move_uploaded_file($uploadedfile['tmp_name'], $dir.$filename);
            $bundlerecord->image = '/local/elearning_system/uploads/'.$filename;
        }

        $bundlerecord->timemodified = time();
        if ($bundleaction === 'updatebundle' && $bundleid > 0) {
            $existingbundle = $DB->get_record('elearning_products', ['id' => $bundleid], '*', IGNORE_MISSING);
            if ($existingbundle && !empty($existingbundle->isbundle)) {
                $bundlerecord->id = $bundleid;
                if (empty($bundlerecord->image) && !empty($existingbundle->image)) {
                    $bundlerecord->image = $existingbundle->image;
                }
                $DB->update_record('elearning_products', $bundlerecord);
                \core\notification::add('Bundle updated successfully.', \core\output\notification::NOTIFY_SUCCESS);
            } else {
                \core\notification::add('Bundle not found.', \core\output\notification::NOTIFY_ERROR);
            }
        } else {
            $bundlerecord->timecreated = time();
            $DB->insert_record('elearning_products', $bundlerecord);
            \core\notification::add('Bundle created successfully.', \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect(new moodle_url('/local/elearning_system/admin/products.php', $listparams));
    }

    $productid = optional_param('productid',0,PARAM_INT);

    $record = new stdClass();
    $record->name = optional_param('title','',PARAM_TEXT);
    $record->categoryid = optional_param('categoryid',0,PARAM_INT);
    $record->courseid = optional_param('courseid',0,PARAM_INT);
    $record->description = optional_param('description','',PARAM_RAW);
    $record->price = optional_param('originalprice',0,PARAM_FLOAT);
    $record->saleprice = optional_param('saleprice',0,PARAM_FLOAT);
    $type = strtolower(trim((string)optional_param('type','free',PARAM_TEXT)));
    $record->type = ($type === 'paid') ? 'paid' : 'free';
    $record->status = optional_param('status','draft',PARAM_TEXT);
    $record->isbundle = 0;
    $record->bundleitems = '';

    if ($productid) {
        $existingproduct = $DB->get_record('elearning_products', ['id' => $productid], 'id, isbundle, bundleitems', IGNORE_MISSING);
        if ($existingproduct && !empty($existingproduct->isbundle)) {
            $record->isbundle = 1;
            $record->bundleitems = (string)$existingproduct->bundleitems;
        }
    }

    $redirectparams = $listparams + ($productid ? ['edit' => $productid] : ['addnew' => 1]);

    // IMAGE UPLOAD
    if (!empty($_FILES['productimage']['name'])) {
        $uploadedfile = $_FILES['productimage'];
        if (!empty($uploadedfile['error']) && (int)$uploadedfile['error'] !== UPLOAD_ERR_OK) {
            \core\notification::add('Image upload failed. Please try again with a valid image file.', \core\output\notification::NOTIFY_ERROR);
            redirect(new moodle_url('/local/elearning_system/admin/products.php', $redirectparams));
        }

        $tmpfilepath = $uploadedfile['tmp_name'] ?? '';
        $fileisimage = false;
        if (!empty($tmpfilepath) && is_uploaded_file($tmpfilepath)) {
            $imagesize = @getimagesize($tmpfilepath);
            $mimetype = '';
            if (function_exists('mime_content_type')) {
                $mimetype = (string)@mime_content_type($tmpfilepath);
            }
            $fileisimage = (!empty($imagesize) || strpos($mimetype, 'image/') === 0);
        }

        if (!$fileisimage) {
            \core\notification::add('Only image files are allowed (no PDF or video).', \core\output\notification::NOTIFY_ERROR);
            redirect(new moodle_url('/local/elearning_system/admin/products.php', $redirectparams));
        }

        $dir = $CFG->dirroot.'/local/elearning_system/uploads/';
        if (!file_exists($dir)) mkdir($dir,0755,true);

        $filename = time().'_'.clean_param($uploadedfile['name'], PARAM_FILE);
        move_uploaded_file($uploadedfile['tmp_name'], $dir.$filename);

        $record->image = '/local/elearning_system/uploads/'.$filename;
    }
    $record->timemodified = time();

    if ($productid) {
        $record->id = $productid;
        $DB->update_record('elearning_products',$record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('elearning_products',$record);
    }

    redirect(new moodle_url('/local/elearning_system/admin/products.php', $listparams));
}

// PRODUCTS LIST
$products = [];

// FORM DATA (all categories and courses)
$categories = [];
$categoryrecords = $DB->get_records('course_categories', null, 'name ASC', 'id, name');
foreach ($categoryrecords as $categoryrecord) {
    $categories[] = [
        'id' => $categoryrecord->id,
        'name' => format_string($categoryrecord->name),
    ];
}

$courses = [];
$courserecords = $DB->get_records_select('course', 'id <> :sitecourseid', ['sitecourseid' => SITEID], 'fullname ASC', 'id, fullname, category, summary');
foreach ($courserecords as $courserecord) {
    $courses[] = [
        'id' => $courserecord->id,
        'fullname' => format_string($courserecord->fullname),
        'category' => $courserecord->category,
        'summary' => !empty($courserecord->summary) ? strip_tags($courserecord->summary) : '',
    ];
}

$categoryfilters = [[
    'value' => 0,
    'label' => 'All categories',
    'selected' => $selectedcategoryid === 0,
]];
foreach ($categories as $category) {
    $categoryfilters[] = [
        'value' => (int)$category['id'],
        'label' => $category['name'],
        'selected' => $selectedcategoryid === (int)$category['id'],
    ];
}

$coursefilters = [[
    'value' => 0,
    'label' => 'All courses',
    'selected' => $selectedcourseid === 0,
]];
foreach ($courses as $course) {
    $coursefilters[] = [
        'value' => (int)$course['id'],
        'label' => $course['fullname'],
        'selected' => $selectedcourseid === (int)$course['id'],
    ];
}

$typefilters = [
    [
        'value' => '',
        'label' => 'All types',
        'selected' => $selectedtypefilter === '',
    ],
    [
        'value' => 'free',
        'label' => 'Free',
        'selected' => $selectedtypefilter === 'free',
    ],
    [
        'value' => 'paid',
        'label' => 'Paid',
        'selected' => $selectedtypefilter === 'paid',
    ],
];

$statusfilters = [
    [
        'value' => '',
        'label' => 'All statuses',
        'selected' => $selectedstatusfilter === '',
    ],
    [
        'value' => 'draft',
        'label' => 'Draft',
        'selected' => $selectedstatusfilter === 'draft',
    ],
    [
        'value' => 'publish',
        'label' => 'Publish',
        'selected' => $selectedstatusfilter === 'publish',
    ],
];

$records = $DB->get_records('elearning_products',null,'id DESC');

$bundleavailableproducts = [];
$selectedbundleidsforedit = [];
if ($editbundleid) {
    $editingbundle = $DB->get_record('elearning_products', ['id' => $editbundleid], '*', IGNORE_MISSING);
    if ($editingbundle && !empty($editingbundle->isbundle) && !empty($editingbundle->bundleitems)) {
        $selectedbundleidsforedit = array_map('intval', array_filter(explode(',', (string)$editingbundle->bundleitems)));
    }
}
foreach ($records as $record) {
    if (!empty($record->isbundle)) {
        continue;
    }
    if ($editbundleid && (int)$record->id === (int)$editbundleid) {
        continue;
    }
    $bundleavailableproducts[] = [
        'id' => (int)$record->id,
        'name' => format_string($record->name),
        'selected' => in_array((int)$record->id, $selectedbundleidsforedit, true),
    ];
}

foreach ($records as $r) {

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

    $categoryname = '-';
    if (!empty($r->categoryid)) {
        $cat = $DB->get_record('course_categories', ['id' => $r->categoryid]);
        if ($cat) {
            $categoryname = format_string($cat->name);
        }
    }

    $coursename = '-';
    if (!empty($r->courseid)) {
        $course = $DB->get_record('course', ['id' => $r->courseid]);
        if ($course) {
            $coursename = format_string($course->fullname);
        }
    }

    $normalizedtype = ($r->type === 'paid' || $r->type === 'subscription') ? 'paid' : 'free';
    $matchesfilters = true;

    if ($selectedcategoryid !== 0 && (int)$r->categoryid !== $selectedcategoryid) {
        $matchesfilters = false;
    }

    if ($matchesfilters && $selectedcourseid !== 0 && (int)$r->courseid !== $selectedcourseid) {
        $matchesfilters = false;
    }

    if ($matchesfilters && $selectedtypefilter !== '' && $normalizedtype !== $selectedtypefilter) {
        $matchesfilters = false;
    }

    if ($matchesfilters && $selectedstatusfilter !== '' && (string)$r->status !== $selectedstatusfilter) {
        $matchesfilters = false;
    }

    if ($matchesfilters && $searchquery !== '') {
        $haystack = core_text::strtolower(implode(' ', [
            (string)$r->name,
            $categoryname,
            $coursename,
            $normalizedtype,
            (string)$r->status,
        ]));
        $needle = core_text::strtolower($searchquery);

        if (strpos($haystack, $needle) === false) {
            $matchesfilters = false;
        }
    }

    if (!$matchesfilters) {
        continue;
    }

    $isbundleitem = !empty($r->isbundle);
    $products[] = [
    'name'=>$r->name,
    'image'=>$image,
    'categoryname'=>$categoryname,
    'coursename'=>$coursename,
    'isbundle'=>$isbundleitem,

    'type'=>ucfirst($normalizedtype),
    'isfree'=>$normalizedtype=='free',
    'ispaid'=>$normalizedtype=='paid',

    'status'=>ucfirst($r->status),
    'isdraft'=>$r->status=='draft',
    'ispublished'=>$r->status=='publish',

    'price'=>$r->price,
    'originalprice'=>$r->price,
    'saleprice'=>$r->saleprice,
    
    'editurl'=>(new moodle_url('/local/elearning_system/admin/products.php', $listparams + ($isbundleitem ? ['editbundle'=>$r->id] : ['edit'=>$r->id])))->out(false),
    'deleteurl'=>(new moodle_url('/local/elearning_system/admin/products.php', $listparams + ['id'=>$r->id, 'sesskey'=>sesskey()]))->out(false),
];
}

// EDIT
$editproduct = null;
if ($editid) {
    $editproduct = $DB->get_record('elearning_products',['id'=>$editid]);

    if ($editproduct && !empty($editproduct->image)) {
        $editproduct->imageurl = $CFG->wwwroot.$editproduct->image;
    }

    if ($editproduct) {
        $editproduct->type = ($editproduct->type === 'paid' || $editproduct->type === 'subscription') ? 'paid' : 'free';
        $editproduct->selectedcategoryid = $editproduct->categoryid;
        $editproduct->selectedcourseid = $editproduct->courseid;
    }
}

$editbundle = null;
if ($editbundleid) {
    $editbundle = $DB->get_record('elearning_products', ['id' => $editbundleid], '*', IGNORE_MISSING);
    if ($editbundle && !empty($editbundle->isbundle)) {
        $editbundle->selectedcategoryid = $editbundle->categoryid;
        $editbundle->selectedcourseid = $editbundle->courseid;
        $editbundle->isstatusdraft = ((string)$editbundle->status === 'draft');
        $editbundle->isstatuspublished = ((string)$editbundle->status === 'publish');
        $editbundle->istypefree = ((string)$editbundle->type !== 'paid');
        $editbundle->istypepaid = ((string)$editbundle->type === 'paid');
        if (!empty($editbundle->image)) {
            $editbundle->imageurl = (strpos($editbundle->image, 'http') === 0)
                ? $editbundle->image
                : $CFG->wwwroot.$editbundle->image;
        }
    } else {
        $editbundle = null;
    }
}

$descriptionvalue = ($editproduct !== null && isset($editproduct->description)) ? (string)$editproduct->description : '';
$descriptioneditor = html_writer::tag('textarea', s($descriptionvalue), [
    'name' => 'description',
    'id' => 'descriptionInput',
    'class' => 'form-control',
]);
$preferrededitor = editors_get_preferred_editor(FORMAT_HTML);
if ($preferrededitor) {
    $preferrededitor->use_editor('descriptionInput', [
        'context' => $context,
        'autosave' => false,
    ]);
}

$bundledescriptionvalue = ($editbundle !== null && isset($editbundle->description)) ? (string)$editbundle->description : '';
$bundledescriptioneditor = html_writer::tag('textarea', s($bundledescriptionvalue), [
    'name' => 'bundledescription',
    'id' => 'bundleDescriptionInput',
    'class' => 'form-control',
]);
if ($preferrededitor) {
    $preferrededitor->use_editor('bundleDescriptionInput', [
        'context' => $context,
        'autosave' => false,
    ]);
}

// TEMPLATE
$templatedata = [
    'products'=>$products,
    'hasproducts' => !empty($products),
    'hasfilters' => ($searchquery !== '' || $selectedcategoryid !== 0 || $selectedcourseid !== 0 || $selectedtypefilter !== '' || $selectedstatusfilter !== ''),
    'noresultsmessage' => ($searchquery !== '' || $selectedcategoryid !== 0 || $selectedcourseid !== 0 || $selectedtypefilter !== '' || $selectedstatusfilter !== '')
        ? 'No products match your filters.'
        : 'No products available.',
    'categories'=>$categories,
    'courses'=>$courses,
    'categoryfilters'=>$categoryfilters,
    'coursefilters'=>$coursefilters,
    'typefilters'=>$typefilters,
    'statusfilters'=>$statusfilters,
    'searchquery'=>$searchquery,
    'bundleavailableproducts' => $bundleavailableproducts,
    'editbundle' => $editbundle,
    'iseditingbundle' => ($editbundle !== null),
    'isbundleopen' => ($addbundle == 1 || $editbundle !== null),
    'bundledescriptioneditor' => $bundledescriptioneditor,
    'descriptioneditor' => $descriptioneditor,
    'editproduct'=>$editproduct,
    'isediting'=>($editproduct !== null),
    'isadding'=>($addnew == 1),
    'isaddingbundle'=>($addbundle == 1),
    'addnewurl'=>(new moodle_url('/local/elearning_system/admin/products.php', $listparams + ['addnew' => 1]))->out(false),
    'addbundleurl'=>(new moodle_url('/local/elearning_system/admin/products.php', $listparams + ['addbundle' => 1]))->out(false),
    'sesskey'=>sesskey(),

    'dashboardurl'=>(new moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl'=>(new moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl'=>(new moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'couponsurl' => (new moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new moodle_url('/local/elearning_system/admin/payement.php'))->out(false),

    'isdashboard'=>false,
    'isproducts'=>true,
    'isorders' => false,
    'iscoupons' => false,
    'ispayement' => false
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout',$templatedata);
echo $OUTPUT->footer();