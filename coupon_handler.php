<?php
// Coupon integration for checkout - to be included in checkout.php after cart is loaded

$couponerror = '';
$couponsuccess = '';
$appliedcoupon = null;
$discountamount = 0.0;
$newtotal = $total;
$discountdisplay = '';

// Check if coupon is already in session
if (!empty($SESSION->local_elearning_system_coupon)) {
    $appliedcoupon = $SESSION->local_elearning_system_coupon;
    
    // Calculate discount
    if ($appliedcoupon->discounttype === 'percentage') {
        $discountamount = ($total * $appliedcoupon->discountvalue) / 100;
    } else if ($appliedcoupon->discounttype === 'fixed') {
        $discountamount = min($appliedcoupon->discountvalue, $total);
    }
    
    $newtotal = $total - $discountamount;
    if ($newtotal < 0) {
        $newtotal = 0;
    }
    
    if ($appliedcoupon->discounttype === 'percentage') {
        $discountdisplay = $appliedcoupon->discountvalue . '% (' . number_format($discountamount, 2) . ')';
    } else {
        $discountdisplay = '$' . number_format($discountamount, 2);
    }
}

// Handle apply coupon request
if (optional_param('applycoupon', 0, PARAM_BOOL) && confirm_sesskey()) {
    $couponcode = strtoupper(trim(optional_param('couponcode', '', PARAM_TEXT)));
    
    if (!empty($couponcode)) {
        $coupon = $DB->get_record('elearning_coupons', ['code' => $couponcode]);
        
        if (!$coupon) {
            $couponerror = 'Coupon code not found';
        } else if ($coupon->status !== 'active') {
            $couponerror = 'This coupon is inactive';
        } else if (!empty($coupon->expirydate) && $coupon->expirydate < time()) {
            $couponerror = 'This coupon has expired';
        } else if (!empty($coupon->maxuse) && $coupon->currentuse >= $coupon->maxuse) {
            $couponerror = 'This coupon has reached its maximum usage limit';
        } else if (!empty($coupon->minpurchase) && $total < $coupon->minpurchase) {
            $couponerror = 'Minimum purchase of $' . number_format((float)$coupon->minpurchase, 2) . ' required for this coupon';
        } else {
            // Store coupon in session
            $SESSION->local_elearning_system_coupon = (object)[
                'id' => (int)$coupon->id,
                'code' => $coupon->code,
                'discounttype' => $coupon->discounttype,
                'discountvalue' => (float)$coupon->discountvalue,
                'minpurchase' => !empty($coupon->minpurchase) ? (float)$coupon->minpurchase : null,
            ];
            
            $appliedcoupon = $SESSION->local_elearning_system_coupon;
            
            // Recalculate discount
            if ($appliedcoupon->discounttype === 'percentage') {
                $discountamount = ($total * $appliedcoupon->discountvalue) / 100;
            } else if ($appliedcoupon->discounttype === 'fixed') {
                $discountamount = min($appliedcoupon->discountvalue, $total);
            }
            
            $newtotal = $total - $discountamount;
            if ($newtotal < 0) {
                $newtotal = 0;
            }
            
            if ($appliedcoupon->discounttype === 'percentage') {
                $discountdisplay = $appliedcoupon->discountvalue . '% (' . number_format($discountamount, 2) . ')';
            } else {
                $discountdisplay = '$' . number_format($discountamount, 2);
            }
            
            $couponsuccess = 'Coupon applied successfully!';
        }
    } else {
        $couponerror = 'Please enter a coupon code';
    }
}

// Handle remove coupon request
if (optional_param('removecoupon', 0, PARAM_BOOL) && confirm_sesskey()) {
    unset($SESSION->local_elearning_system_coupon);
    $appliedcoupon = null;
    $discountamount = 0.0;
    $newtotal = $total;
    $couponsuccess = 'Coupon removed';
}
