<?php

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

global $DB, $USER, $SESSION;

require_sesskey();

$productid = required_param('productid', PARAM_INT);
$orderid = optional_param('orderid', 0, PARAM_INT);

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}

local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

$orderconditions = [
    'userid' => (int)$USER->id,
    'productid' => $productid,
];

if ($orderid > 0) {
    $orderconditions['id'] = $orderid;
}

$order = $DB->get_record('elearning_orders', $orderconditions, '*', IGNORE_MULTIPLE);

if (!$order) {
    redirect(new moodle_url('/local/elearning_system/my_courses.php'));
}

$product = $DB->get_record('elearning_products', ['id' => $productid], '*', MUST_EXIST);

$ordercolumns = $DB->get_columns('elearning_orders');

$durationmonths = 1;
if (isset($ordercolumns['durationmonths']) && !empty($order->durationmonths)) {
    $durationmonths = max(1, (int)$order->durationmonths);
}

$now = time();
$expiresat = 0;

if (isset($ordercolumns['expiresat']) && !empty($order->expiresat)) {
    $expiresat = (int)$order->expiresat;
} else {
    $expiresat = strtotime('+' . $durationmonths . ' months', (int)$order->timecreated);
    if ($expiresat === false) {
        $expiresat = 0;
    }
}

if ($expiresat > $now) {
    redirect(new moodle_url('/local/elearning_system/my_courses.php'));
}

/*
 * Réactivation :
 * on remet le même produit dans le panier avec la même durée du dernier achat.
 * Selon votre système de panier, on stocke productid + durationmonths.
 */
$SESSION->local_elearning_system_cart[$productid] = [
    'productid' => $productid,
    'durationmonths' => $durationmonths,
    'quantity' => 1,
    'reactivation' => 1,
    'previousorderid' => (int)$order->id,
];

redirect(new moodle_url('/local/elearning_system/checkout.php', [
    'reactivate' => 1,
    'productid' => $productid,
]));