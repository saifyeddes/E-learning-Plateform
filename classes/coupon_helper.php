<?php

namespace local_elearning_system;

// Helper functions for coupon handling

/**
 * Apply a coupon code and store it in session
 * Returns array ['success' => bool, 'message' => string, 'coupon' => object|null]
 */
function apply_coupon($couponcode, $total = 0) {
    global $DB;
    
    $result = [
        'success' => false,
        'message' => '',
        'coupon' => null,
    ];
    
    $couponcode = strtoupper(trim($couponcode));
    
    if (empty($couponcode)) {
        $result['message'] = 'Please enter a coupon code';
        return $result;
    }
    
    $coupon = $DB->get_record('elearning_coupons', ['code' => $couponcode]);
    
    if (!$coupon) {
        $result['message'] = 'Coupon code not found';
        return $result;
    }
    
    if ($coupon->status !== 'active') {
        $result['message'] = 'This coupon is inactive';
        return $result;
    }
    
    if (!empty($coupon->expirydate) && $coupon->expirydate < time()) {
        $result['message'] = 'This coupon has expired';
        return $result;
    }
    
    if (!empty($coupon->maxuse) && $coupon->currentuse >= $coupon->maxuse) {
        $result['message'] = 'This coupon has reached its maximum usage limit';
        return $result;
    }
    
    // Check minimum purchase
    if (!empty($coupon->minpurchase) && $total > 0 && $total < $coupon->minpurchase) {
        $result['message'] = 'Minimum purchase of $' . number_format((float)$coupon->minpurchase, 2) . ' required for this coupon';
        return $result;
    }
    
    $result['success'] = true;
    $result['message'] = 'Coupon applied successfully!';
    $result['coupon'] = (object)[
        'id' => (int)$coupon->id,
        'code' => $coupon->code,
        'discounttype' => $coupon->discounttype,
        'discountvalue' => (float)$coupon->discountvalue,
        'minpurchase' => !empty($coupon->minpurchase) ? (float)$coupon->minpurchase : null,
    ];
    
    return $result;
}

/**
 * Calculate discount from an applied coupon
 * Returns array ['discount' => amount, 'finalprice' => amount]
 */
function calculate_discount($total, $coupon) {
    $discount = 0.0;
    
    if (!$coupon) {
        return [
            'discount' => 0.0,
            'finalprice' => (float)$total,
        ];
    }
    
    if ($coupon->discounttype === 'percentage') {
        $discount = ((float)$total * (float)$coupon->discountvalue) / 100;
    } else if ($coupon->discounttype === 'fixed') {
        $discount = min((float)$coupon->discountvalue, (float)$total);
    }
    
    $finalprice = (float)$total - $discount;
    if ($finalprice < 0) {
        $finalprice = 0;
    }
    
    return [
        'discount' => (float)$discount,
        'finalprice' => (float)$finalprice,
    ];
}

/**
 * Mark a coupon as used (increment usage count)
 */
function mark_coupon_used($couponid) {
    global $DB;
    
    $coupon = $DB->get_record('elearning_coupons', ['id' => $couponid]);
    if ($coupon) {
        $coupon->currentuse = ((int)$coupon->currentuse) + 1;
        if (!empty($coupon->maxuse) && (int)$coupon->currentuse >= (int)$coupon->maxuse) {
            $coupon->status = 'inactive';
        }
        $DB->update_record('elearning_coupons', $coupon);
        return true;
    }
    
    return false;
}
