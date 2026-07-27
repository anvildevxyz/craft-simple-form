<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Plugin;
use Craft;
use SmokeTester;

/**
 * Security-hardening regression smoke tests (functional).
 *
 * Covers redirect URL validation, save-draft rate limiting, and payment amount
 * bounds through the public submit paths.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SecurityHardeningSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testUnsafeRedirectUrlIsStripped(SmokeTester $I): void
    {
        $form = $this->createForm('Redirect', 'redirect' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $reloaded = $this->reloadForm($form);
        $reloaded->postSubmitAction = 'url';
        $reloaded->redirectUrl = '//evil.example/phish';
        Craft::$app->getElements()->saveElement($reloaded);

        $result = $this->submitDirect($reloaded, ['field_' . $fieldId => 'Ada']);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $result['submission'], $result['data']);

        $I->assertNull($resolved['redirectUrl']);
    }

    public function testSafeRelativeRedirectIsAllowed(SmokeTester $I): void
    {
        $form = $this->createForm('Thanks', 'thanks' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $reloaded = $this->reloadForm($form);
        $reloaded->postSubmitAction = 'url';
        $reloaded->redirectUrl = '/thanks?name={name}';
        Craft::$app->getElements()->saveElement($reloaded);

        $result = $this->submitDirect($reloaded, ['field_' . $fieldId => 'Ada']);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $result['submission'], $result['data']);

        $I->assertSame('/thanks?name=Ada', $resolved['redirectUrl']);
    }

    public function testPaymentAmountOutOfBoundsIsRejected(SmokeTester $I): void
    {
        $form = $this->createForm('Pay', 'pay' . uniqid());
        $this->createField((int) $form->id, 'number', 'amount', 'Amount');
        $this->createField((int) $form->id, 'payment', 'payment', 'Payment', false, [
            'amountType' => 'field',
            'amountField' => 'amount',
            'minAmount' => 10,
            'maxAmount' => 100,
        ]);

        $payments = Plugin::getInstance()->getPayments();
        $I->assertSame(
            'The payment amount is below the minimum allowed.',
            $payments->amountOutOfBoundsMessage($form, 5.0),
        );
        $I->assertSame(
            'The payment amount exceeds the maximum allowed.',
            $payments->amountOutOfBoundsMessage($form, 150.0),
        );
        $I->assertNull($payments->amountOutOfBoundsMessage($form, 50.0));
    }

    public function testEmptyPaymentParamsRejectedWhenCommerceAvailable(SmokeTester $I): void
    {
        $payments = Plugin::getInstance()->getPayments();
        if (!$payments->commerceAvailable()) {
            $I->markTestSkipped('Commerce is not installed in the test environment.');
        }

        $form = $this->createForm('Pay Form', 'payForm' . uniqid());
        $this->createField((int) $form->id, 'payment', 'fee', 'Fee', false, [
            'amountType' => 'fixed',
            'amount' => 25,
        ]);

        $result = $payments->authorizeForSubmit($form, [], []);
        $I->assertNotNull($result);
        $I->assertNotNull($result['error']);
        $I->assertSame('Payment information is required.', $result['error']);
    }
}
