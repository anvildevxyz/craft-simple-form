<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\models\CouponModel;
use PHPUnit\Framework\TestCase;

/**
 * #246 — the discount math and redeemability checks on CouponModel are pure
 * (no Craft boot), so the rules a charge is reduced by are verified in isolation:
 * fixed vs percentage, the never-below-zero clamp, expiry, and usage limits.
 */
class CouponModelTest extends TestCase
{
    public function testFixedDiscountTakesAFlatAmountOff(): void
    {
        $coupon = new CouponModel();
        $coupon->type = CouponModel::TYPE_FIXED;
        $coupon->amount = 10.0;

        $this->assertSame(10.0, $coupon->discountFor(50.0));
    }

    public function testPercentageDiscountIsProportional(): void
    {
        $coupon = new CouponModel();
        $coupon->type = CouponModel::TYPE_PERCENTAGE;
        $coupon->amount = 25.0;

        $this->assertSame(25.0, $coupon->discountFor(100.0));
        $this->assertSame(5.0, $coupon->discountFor(20.0));
    }

    public function testDiscountNeverExceedsTheAmount(): void
    {
        $coupon = new CouponModel();
        $coupon->type = CouponModel::TYPE_FIXED;
        $coupon->amount = 80.0;

        // A $80-off coupon on a $30 charge discounts only $30 (total can't go negative).
        $this->assertSame(30.0, $coupon->discountFor(30.0));
    }

    public function testDiscountOnZeroOrNegativeAmountIsZero(): void
    {
        $coupon = new CouponModel();
        $coupon->type = CouponModel::TYPE_PERCENTAGE;
        $coupon->amount = 50.0;

        $this->assertSame(0.0, $coupon->discountFor(0.0));
        $this->assertSame(0.0, $coupon->discountFor(-5.0));
    }

    public function testDiscountIsRoundedToCents(): void
    {
        $coupon = new CouponModel();
        $coupon->type = CouponModel::TYPE_PERCENTAGE;
        $coupon->amount = 33.0;

        // 33% of 10.00 = 3.30
        $this->assertSame(3.3, $coupon->discountFor(10.0));
    }

    public function testExpiryInThePastIsExpired(): void
    {
        $coupon = new CouponModel();
        $coupon->expiryDate = new \DateTime('-1 day', new \DateTimeZone('UTC'));
        $this->assertTrue($coupon->isExpired());

        $coupon->expiryDate = new \DateTime('+1 day', new \DateTimeZone('UTC'));
        $this->assertFalse($coupon->isExpired());

        $coupon->expiryDate = null;
        $this->assertFalse($coupon->isExpired());
    }

    public function testUsageLimitIsEnforced(): void
    {
        $coupon = new CouponModel();
        $coupon->maxUsages = 2;

        $coupon->usageCount = 1;
        $this->assertFalse($coupon->isUsedUp());

        $coupon->usageCount = 2;
        $this->assertTrue($coupon->isUsedUp());

        // null = unlimited.
        $coupon->maxUsages = null;
        $coupon->usageCount = 999;
        $this->assertFalse($coupon->isUsedUp());
    }

    public function testRedeemableRequiresEnabledUnexpiredAndUnderLimit(): void
    {
        $coupon = new CouponModel();
        $coupon->enabled = true;
        $coupon->maxUsages = 5;
        $coupon->usageCount = 1;
        $this->assertTrue($coupon->isRedeemable());

        $coupon->enabled = false;
        $this->assertFalse($coupon->isRedeemable());

        $coupon->enabled = true;
        $coupon->usageCount = 5;
        $this->assertFalse($coupon->isRedeemable());
    }

    public function testPercentageOverHundredFailsValidation(): void
    {
        $coupon = new CouponModel();
        $coupon->code = 'SAVE';
        $coupon->type = CouponModel::TYPE_PERCENTAGE;
        $coupon->amount = 150.0;

        $this->assertFalse($coupon->validate());
        $this->assertArrayHasKey('amount', $coupon->getErrors());
    }

    public function testCodeIsRequired(): void
    {
        $coupon = new CouponModel();
        $coupon->code = '';

        $this->assertFalse($coupon->validate());
        $this->assertArrayHasKey('code', $coupon->getErrors());
    }
}
