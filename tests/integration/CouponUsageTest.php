<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\models\CouponModel;
use anvildev\simpleform\Plugin;

/**
 * Coupon usage accounting (#246): the atomic reserve/release that enforces a
 * coupon's usage cap even under concurrent redemptions. Exercises the real DB
 * path (the cap is enforced in a single conditional UPDATE, not in PHP).
 */
class CouponUsageTest extends SimpleFormTestCase
{
    private function makeCoupon(?int $maxUsages): CouponModel
    {
        $coupons = Plugin::getInstance()->getCoupons();
        $coupon = new CouponModel();
        $coupon->code = 'CAP' . substr(md5((string) $maxUsages . serialize($maxUsages)), 0, 8);
        $coupon->type = CouponModel::TYPE_FIXED;
        $coupon->amount = 5.0;
        $coupon->maxUsages = $maxUsages;
        $this->assertTrue($coupons->save($coupon), 'coupon should save');

        return $coupon;
    }

    public function testCapBlocksASecondConcurrentRedemption(): void
    {
        $this->requireCraft();
        $coupons = Plugin::getInstance()->getCoupons();

        $coupon = $this->makeCoupon(1);
        $id = (int) $coupon->id;

        // First claim succeeds; the second is refused at the DB level.
        $this->assertTrue($coupons->tryConsume($id));
        $this->assertFalse($coupons->tryConsume($id));

        $this->assertSame(1, (int) $coupons->getById($id)->usageCount);
    }

    public function testReleaseReturnsASlotToThePool(): void
    {
        $this->requireCraft();
        $coupons = Plugin::getInstance()->getCoupons();

        $coupon = $this->makeCoupon(1);
        $id = (int) $coupon->id;

        $this->assertTrue($coupons->tryConsume($id));
        $this->assertFalse($coupons->tryConsume($id));

        // A declined/expired charge releases the reservation, freeing the cap.
        $coupons->releaseUsage($id);
        $this->assertSame(0, (int) $coupons->getById($id)->usageCount);
        $this->assertTrue($coupons->tryConsume($id));
    }

    public function testReleaseNeverGoesNegative(): void
    {
        $this->requireCraft();
        $coupons = Plugin::getInstance()->getCoupons();

        $coupon = $this->makeCoupon(5);
        $id = (int) $coupon->id;

        $coupons->releaseUsage($id);
        $this->assertSame(0, (int) $coupons->getById($id)->usageCount);
    }

    public function testUnlimitedCouponAlwaysConsumes(): void
    {
        $this->requireCraft();
        $coupons = Plugin::getInstance()->getCoupons();

        $coupon = $this->makeCoupon(null);
        $id = (int) $coupon->id;

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($coupons->tryConsume($id));
        }
        $this->assertSame(5, (int) $coupons->getById($id)->usageCount);
    }
}
