<?php
// Coupon integration for payment - adds discount line item to Stripe

// Check for applied coupon
$applied_coupon = $SESSION->local_elearning_system_coupon ?? null;

if (!empty($applied_coupon)) {
    // Calculate discount from total
    $discount = 0.0;
    
    if ($applied_coupon->discounttype === 'percentage') {
        $discount = ($totalamount * $applied_coupon->discountvalue) / 100;
    } else if ($applied_coupon->discounttype === 'fixed') {
        $discount = min($applied_coupon->discountvalue, $totalamount);
    }
    
    if ($discount > 0) {
        // Add discount line item to Stripe (as a negative amount)
        $stripediscount = (int)round($discount * 100);
        $stripepostfields['line_items[' . $idx . '][price_data][currency]'] = $stripecurrency;
        $stripepostfields['line_items[' . $idx . '][price_data][product_data][name]'] = 'Discount (' . s($applied_coupon->code) . ')';
        $stripepostfields['line_items[' . $idx . '][price_data][unit_amount]'] = -$stripediscount;
        $stripepostfields['line_items[' . $idx . '][quantity]'] = 1;
        $idx++;
        
        $totalamount -= $discount;
        if ($totalamount < 0) {
            $totalamount = 0;
        }
    }
}
