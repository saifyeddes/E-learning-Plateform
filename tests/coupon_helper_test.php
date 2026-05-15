<?php

defined('MOODLE_INTERNAL') || die();

use local_elearning_system\coupon_helper;

final class coupon_helper_test extends advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_discount_percentage_calculation(): void {
        $price = 100;
        $discount = 20;

        $finalprice = coupon_helper::calculate_percentage_discount($price, $discount);

        $this->assertEquals(80, $finalprice);
    }

    public function test_discount_does_not_return_negative_price(): void {
        $price = 50;
        $discount = 100;

        $finalprice = coupon_helper::calculate_percentage_discount($price, $discount);

        $this->assertGreaterThanOrEqual(0, $finalprice);
    }
}