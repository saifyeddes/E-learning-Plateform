<?php

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/plugin_db.php');

require_login();

global $USER, $SESSION;

require_sesskey();

function local_elearning_system_reactivate_db(): mysqli {
    return \local_elearning_system\plugin_db::get();
}

function local_elearning_system_reactivate_get_order(int $userid, int $productid, int $orderid = 0): ?stdClass {
    $db = local_elearning_system_reactivate_db();

    if ($orderid > 0) {
        $stmt = $db->prepare("
            SELECT *
              FROM el_orders
             WHERE id = ?
               AND userid = ?
               AND productid = ?
             LIMIT 1
        ");

        if (!$stmt) {
            throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
        }

        $stmt->bind_param('iii', $orderid, $userid, $productid);
    } else {
        $stmt = $db->prepare("
            SELECT *
              FROM el_orders
             WHERE userid = ?
               AND productid = ?
          ORDER BY id DESC
             LIMIT 1
        ");

        if (!$stmt) {
            throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
        }

        $stmt->bind_param('ii', $userid, $productid);
    }

    $stmt->execute();

    $result = $stmt->get_result();
    $order = $result ? $result->fetch_object() : null;

    $stmt->close();

    return $order ?: null;
}

function local_elearning_system_reactivate_get_product(int $productid): ?stdClass {
    $db = local_elearning_system_reactivate_db();

    $stmt = $db->prepare("
        SELECT *
          FROM el_products
         WHERE id = ?
         LIMIT 1
    ");

    if (!$stmt) {
        throw new moodle_exception('Plugin DB prepare error: ' . $db->error);
    }

    $stmt->bind_param('i', $productid);
    $stmt->execute();

    $result = $stmt->get_result();
    $product = $result ? $result->fetch_object() : null;

    $stmt->close();

    return $product ?: null;
}

$productid = required_param('productid', PARAM_INT);
$orderid = optional_param('orderid', 0, PARAM_INT);

if (!isset($SESSION->local_elearning_system_cart) || !is_array($SESSION->local_elearning_system_cart)) {
    $SESSION->local_elearning_system_cart = [];
}

local_elearning_system_normalise_cart_structure($SESSION->local_elearning_system_cart);

$order = local_elearning_system_reactivate_get_order((int)$USER->id, $productid, $orderid);

if (!$order) {
    redirect(new moodle_url('/local/elearning_system/my_courses.php'));
}

$product = local_elearning_system_reactivate_get_product($productid);

if (!$product) {
    redirect(new moodle_url('/local/elearning_system/my_courses.php'));
}

$durationmonths = !empty($order->durationmonths)
    ? max(1, (int)$order->durationmonths)
    : 1;

$now = time();
$expiresat = 0;

if (!empty($order->expiresat)) {
    $expiresat = (int)$order->expiresat;
} else {
    $calculated = strtotime('+' . $durationmonths . ' months', (int)$order->timecreated);
    $expiresat = $calculated !== false ? (int)$calculated : 0;
}

if ($expiresat > $now) {
    redirect(new moodle_url('/local/elearning_system/my_courses.php'));
}

/*
 * Réactivation :
 * on remet le même produit dans le panier avec la même durée du dernier achat.
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