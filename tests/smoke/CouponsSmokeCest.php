<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\controllers\SubmitController;
use anvildev\simpleform\models\CouponModel;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Response;
use SmokeTester;

/**
 * Payment coupons smoke tests (#246): the discount math + redemption rules in
 * CouponsService, and the public coupon-preview endpoint. The Commerce charge
 * path is exercised by the live smoke suite (Commerce isn't in the test app), so
 * these cover the Commerce-agnostic discount logic + the preview HTTP path.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class CouponsSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testFixedCouponDiscountsTheAmount(SmokeTester $I): void
    {
        $this->makeCoupon('FIXED10', CouponModel::TYPE_FIXED, 10.0);

        $eval = $this->coupons()->evaluate('FIXED10', 50.0);
        $I->assertNull($eval['error']);
        $I->assertSame(10.0, $eval['discount']);
        $I->assertSame(40.0, $eval['total']);
    }

    public function testPercentageCouponDiscountsProportionally(SmokeTester $I): void
    {
        $this->makeCoupon('PCT20', CouponModel::TYPE_PERCENTAGE, 20.0);

        $eval = $this->coupons()->evaluate('PCT20', 50.0);
        $I->assertNull($eval['error']);
        $I->assertSame(10.0, $eval['discount']);
        $I->assertSame(40.0, $eval['total']);
    }

    public function testCodeLookupIsCaseInsensitive(SmokeTester $I): void
    {
        $this->makeCoupon('SAVEME', CouponModel::TYPE_FIXED, 5.0);

        $I->assertNotNull($this->coupons()->getByCode('saveme'));
        $I->assertNotNull($this->coupons()->getByCode('SaVeMe'));
    }

    public function testExpiredCouponIsRejected(SmokeTester $I): void
    {
        $coupon = $this->makeCoupon('OLD', CouponModel::TYPE_FIXED, 5.0);
        $coupon->expiryDate = new \DateTime('-1 day', new \DateTimeZone('UTC'));
        $I->assertTrue($this->coupons()->save($coupon));

        $eval = $this->coupons()->evaluate('OLD', 50.0);
        $I->assertNotNull($eval['error']);
        $I->assertNull($eval['coupon']);
    }

    public function testUsageCapBlocksASecondRedemption(SmokeTester $I): void
    {
        $coupon = $this->makeCoupon('ONCE', CouponModel::TYPE_FIXED, 5.0, 1);

        $I->assertTrue($this->coupons()->tryConsume((int) $coupon->id));
        $I->assertFalse($this->coupons()->tryConsume((int) $coupon->id));
        // A released reservation frees the slot again.
        $this->coupons()->releaseUsage((int) $coupon->id);
        $I->assertTrue($this->coupons()->tryConsume((int) $coupon->id));
    }

    public function testPreviewEndpointReturnsDiscountForAFixedAmountForm(SmokeTester $I): void
    {
        $this->makeCoupon('PREVIEW', CouponModel::TYPE_FIXED, 15.0);
        $form = $this->createForm('Pay', 'couponPay' . uniqid());
        $this->createField((int) $form->id, 'payment', 'fee', 'Fee', false, [
            'amountType' => 'fixed',
            'amount' => 60,
            'enableCoupons' => true,
        ]);

        $data = $this->callCouponValidate($form->handle, 'PREVIEW');
        $I->assertTrue($data['success'] ?? false);
        $I->assertSame(15.0, $data['discount']);
        $I->assertSame(45.0, $data['total']);
    }

    public function testPreviewEndpointRejectsAnUnknownCode(SmokeTester $I): void
    {
        $form = $this->createForm('Pay', 'couponPay2' . uniqid());
        $this->createField((int) $form->id, 'payment', 'fee', 'Fee', false, [
            'amountType' => 'fixed',
            'amount' => 60,
            'enableCoupons' => true,
        ]);

        $data = $this->callCouponValidate($form->handle, 'NOPE');
        $I->assertFalse($data['success'] ?? true);
        $I->assertNotEmpty($data['error'] ?? '');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function coupons(): \anvildev\simpleform\services\CouponsService
    {
        return Plugin::getInstance()->getCoupons();
    }

    private function makeCoupon(string $code, string $type, float $amount, ?int $maxUsages = null): CouponModel
    {
        $coupon = new CouponModel();
        $coupon->code = $code;
        $coupon->type = $type;
        $coupon->amount = $amount;
        $coupon->maxUsages = $maxUsages;
        $this->coupons()->save($coupon);

        return $coupon;
    }

    /**
     * Call the public coupon-preview endpoint the front-end Apply button uses.
     *
     * @return array<string, mixed>
     */
    private function callCouponValidate(string $handle, string $couponCode): array
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => $handle, 'couponCode' => $couponCode]);
        $request->getHeaders()->set('Accept', 'application/json');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new SubmitController('submit', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $data = $controller->actionCouponValidate()->data;

        return is_array($data) ? $data : [];
    }
}
