<?php

require('../../../config.php');
require_once(__DIR__ . '/../classes/plugin_db.php');
require_once(__DIR__ . '/../lib.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/dashboard.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Dashboard');
$PAGE->set_heading('Dashboard');

global $DB;
function local_elearning_system_dashboard_plugin_db(): mysqli {
    return \local_elearning_system\plugin_db::get();
}

function local_elearning_system_dashboard_count_products(): int {
    $db = local_elearning_system_dashboard_plugin_db();

    $result = $db->query("SELECT COUNT(*) AS total FROM el_products");

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $row = $result->fetch_object();

    return $row ? (int)$row->total : 0;
}

function local_elearning_system_dashboard_count_orders(): int {
    $db = local_elearning_system_dashboard_plugin_db();

    $result = $db->query("SELECT COUNT(*) AS total FROM el_orders");

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $row = $result->fetch_object();

    return $row ? (int)$row->total : 0;
}

function local_elearning_system_dashboard_total_revenue(): float {
    $db = local_elearning_system_dashboard_plugin_db();

    $result = $db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM el_orders");

    if (!$result) {
        throw new moodle_exception('Plugin DB query error: ' . $db->error);
    }

    $row = $result->fetch_object();

    return $row ? (float)$row->total : 0.0;
}

function local_elearning_system_dashboard_revenue_since(int $starttimestamp): array {
    $db = local_elearning_system_dashboard_plugin_db();

    $stmt = $db->prepare("
        SELECT id, timecreated, amount
          FROM el_orders
         WHERE timecreated >= ?
         ORDER BY timecreated ASC
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param('i', $starttimestamp);
    $stmt->execute();

    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_object()) {
        $orders[] = $row;
    }

    $stmt->close();

    return $orders;
}

/**
 * Build sparkline SVG paths from monthly values.
 *
 * @param array $values Numeric series.
 * @param int $width SVG width.
 * @param int $height SVG height.
 * @return array
 */
function local_elearning_system_build_sparkline(array $values, int $width = 320, int $height = 96): array {
    $count = count($values);
    if ($count === 0) {
        return [
            'linepath' => '',
            'areapath' => '',
            'maxvalue' => 0,
        ];
    }

    $maxvalue = max($values);
    $minvalue = min($values);
    $range = max(1.0, (float)$maxvalue - (float)$minvalue);
    $left = 8;
    $right = $width - 8;
    $top = 10;
    $bottom = $height - 12;
    $plotwidth = max(1, $right - $left);
    $plotheight = max(1, $bottom - $top);
    $stepx = ($count > 1) ? ($plotwidth / ($count - 1)) : 0;

    $lineparts = [];
    $firstx = $left;
    $firsty = $bottom;
    $lastx = $left;
    $lasty = $bottom;

    foreach ($values as $index => $value) {
        $x = $left + ($stepx * $index);
        $normalized = ((float)$value - (float)$minvalue) / $range;
        $y = $bottom - ($normalized * $plotheight);
        if ($index === 0) {
            $lineparts[] = 'M' . round($x, 2) . ' ' . round($y, 2);
            $firstx = $x;
            $firsty = $y;
        } else {
            $lineparts[] = 'L' . round($x, 2) . ' ' . round($y, 2);
        }
        $lastx = $x;
        $lasty = $y;
    }

    $linepath = implode(' ', $lineparts);
    $areapath = $linepath
        . ' L' . round($lastx, 2) . ' ' . $bottom
        . ' L' . round($firstx, 2) . ' ' . $bottom
        . ' Z';

    return [
        'linepath' => $linepath,
        'areapath' => $areapath,
        'maxvalue' => $maxvalue,
    ];
}

/**
 * Generate ordered month buckets for the last N months.
 *
 * @param int $months
 * @return array
 */
function local_elearning_system_month_buckets(int $months = 12): array {
    $buckets = [];
    $currentmonthstart = (new DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);

    for ($i = $months - 1; $i >= 0; $i--) {
        $month = $currentmonthstart->modify('-' . $i . ' month');
        $key = $month->format('Y-m');
        $buckets[$key] = [
            'timestamp' => $month->getTimestamp(),
            'label' => $month->format('M'),
            'value' => 0,
        ];
    }

    return $buckets;
}

// STATS from independent plugin database.
$totalproducts = local_elearning_system_dashboard_count_products();
$totalorders = local_elearning_system_dashboard_count_orders();
$totalrevenue = local_elearning_system_dashboard_total_revenue();

// Users and revenue trend (last 12 months).
$usersbuckets = local_elearning_system_month_buckets(12);
$revenuebuckets = local_elearning_system_month_buckets(12);
$starttimestamp = reset($usersbuckets)['timestamp'];

$usersrs = $DB->get_recordset_select('user', 'timecreated >= :startts AND deleted = 0', ['startts' => $starttimestamp], '', 'id,timecreated');
foreach ($usersrs as $user) {
    if (empty($user->timecreated)) {
        continue;
    }
    $key = date('Y-m', (int)$user->timecreated);
    if (isset($usersbuckets[$key])) {
        $usersbuckets[$key]['value']++;
    }
}
$usersrs->close();

$pluginorders = local_elearning_system_dashboard_revenue_since((int)$starttimestamp);

foreach ($pluginorders as $order) {
    if (empty($order->timecreated)) {
        continue;
    }

    $key = date('Y-m', (int)$order->timecreated);

    if (isset($revenuebuckets[$key])) {
        $revenuebuckets[$key]['value'] += (float)$order->amount;
    }
}

$usersvalues = [];
$revenuevalues = [];
foreach ($usersbuckets as $bucket) {
    $usersvalues[] = (int)$bucket['value'];
}
foreach ($revenuebuckets as $bucket) {
    $revenuevalues[] = (float)$bucket['value'];
}

$usersspark = local_elearning_system_build_sparkline($usersvalues);
$revenuespark = local_elearning_system_build_sparkline($revenuevalues);
$currentmonthkey = date('Y-m');
$userscurrentmonth = isset($usersbuckets[$currentmonthkey]) ? (int)$usersbuckets[$currentmonthkey]['value'] : 0;
$revenuecurrentmonth = isset($revenuebuckets[$currentmonthkey]) ? (float)$revenuebuckets[$currentmonthkey]['value'] : 0.0;
$usersmax = max($usersvalues);
$revenuemax = max($revenuevalues);

// TEMPLATE
$templatedata = [
    'dashboardurl' => (new \moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl'  => (new \moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new \moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'parentsurl' => (new \moodle_url('/local/elearning_system/admin/parents.php'))->out(false),
    'couponsurl' => (new \moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new \moodle_url('/local/elearning_system/admin/payement.php'))->out(false),
    'emailtemplatesurl' => (new \moodle_url('/local/elearning_system/admin/emailtemplates.php'))->out(false),

    'isdashboard' => true,
    'isproducts' => false,
    'isorders' => false,
    'isparents' => false,
    'iscoupons' => false,
    'ispayement' => false,
    'isemailtemplates' => false,

    'totalproducts' => $totalproducts,
    'totalorders' => $totalorders,
    'totalrevenue' => number_format((float)$totalrevenue, 2),

    'userschartlinepath' => $usersspark['linepath'],
    'userschartareapath' => $usersspark['areapath'],
    'userscurrentmonth' => $userscurrentmonth,
    'usersmax' => $usersmax,

    'revenuechartlinepath' => $revenuespark['linepath'],
    'revenuechartareapath' => $revenuespark['areapath'],
    'revenuecurrentmonth' => number_format($revenuecurrentmonth, 2),
    'revenuemax' => number_format($revenuemax, 2),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();