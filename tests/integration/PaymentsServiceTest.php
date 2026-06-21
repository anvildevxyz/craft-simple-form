<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\PaymentsService;

/**
 * Payments orchestration (#116) — the Commerce-agnostic surface: amount
 * resolution, status transitions, and the notification/integration gating
 * while a submission awaits payment. (The Commerce order-creation path is
 * guarded by class_exists and exercised manually; Commerce isn't loaded here.)
 */
class PaymentsServiceTest extends SimpleFormTestCase
{
    private function payments(): PaymentsService
    {
        return Plugin::getInstance()->getPayments();
    }

    private function submission(int $formId, ?string $paymentStatus = null, ?int $orderId = null): Submission
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = [];
        $sub->readStatus = SubmissionStatus::NEW;
        $sub->paymentStatus = $paymentStatus;
        $sub->orderId = $orderId;
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        return $sub;
    }

    public function testResolvesFixedAndFieldAmounts(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_amounts');
        $amountId = $this->createField((int) $form->id, 'number', 'amount', 'Amount');
        $this->createField((int) $form->id, 'payment', 'payment', 'Payment', false, ['amountType' => 'fixed', 'amount' => 25]);

        // Fixed amount from config.
        $this->assertSame(25.0, $this->payments()->resolveAmount($form, []));

        // Field-driven amount: switch the payment field config to read 'amount'.
        $form2 = $this->createForm('Pay2', 'pay_field');
        $aid = $this->createField((int) $form2->id, 'number', 'amount', 'Amount');
        $this->createField((int) $form2->id, 'payment', 'payment', 'Payment', false, ['amountType' => 'field', 'amountField' => 'amount']);
        $data = ['field_' . $aid => ['label' => 'Amount', 'type' => 'number', 'value' => '40']];
        $this->assertSame(40.0, $this->payments()->resolveAmount($form2, $data));

        unset($amountId);
    }

    public function testFieldAmountRejectsOutOfBoundsValues(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_bounds');
        $aid = $this->createField((int) $form->id, 'number', 'amount', 'Amount');
        $this->createField((int) $form->id, 'payment', 'payment', 'Payment', false, [
            'amountType' => 'field',
            'amountField' => 'amount',
            'minAmount' => 10,
            'maxAmount' => 100,
        ]);

        $this->assertSame('The payment amount is below the minimum allowed.', $this->payments()->amountOutOfBoundsMessage($form, 5.0));
        $this->assertSame('The payment amount exceeds the maximum allowed.', $this->payments()->amountOutOfBoundsMessage($form, 150.0));
        $this->assertNull($this->payments()->amountOutOfBoundsMessage($form, 50.0));

        unset($aid);
    }

    public function testZeroAmountResolvesToNull(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_zero');
        $this->createField((int) $form->id, 'payment', 'payment', 'Payment', false, ['amountType' => 'fixed', 'amount' => 0]);
        $this->assertNull($this->payments()->resolveAmount($form, []));
    }

    public function testPaymentFieldConfigDetected(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_cfg');
        $this->createField((int) $form->id, 'payment', 'payment', 'Payment', false, ['amountType' => 'fixed', 'amount' => 9.5]);
        $config = $this->payments()->paymentFieldConfig($form);
        $this->assertIsArray($config);
        $this->assertSame('fixed', $config['amountType']);

        $noPay = $this->createForm('Plain', 'pay_none');
        $this->assertNull($this->payments()->paymentFieldConfig($noPay));
    }

    public function testStatusTransitions(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_status');
        $pending = $this->submission((int) $form->id, PaymentsService::STATUS_PENDING);

        $this->assertTrue($this->payments()->isAwaitingPayment($pending));
        $this->payments()->markPaid($pending);
        $this->assertSame(PaymentsService::STATUS_PAID, $pending->paymentStatus);
        $this->assertFalse($this->payments()->isAwaitingPayment($pending));
    }

    public function testHandleOrderCompletedMarksLinkedSubmission(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_order');
        $sub = $this->submission((int) $form->id, PaymentsService::STATUS_PENDING, 778899);

        $this->payments()->handleOrderCompleted(778899);

        $reloaded = Submission::find()->id((int) $sub->id)->one();
        $this->assertInstanceOf(Submission::class, $reloaded);
        $this->assertSame(PaymentsService::STATUS_PAID, $reloaded->paymentStatus);
    }

    public function testIntegrationDispatchIsGatedWhileAwaitingPayment(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_gate');
        $formId = (int) $form->id;

        // An enabled integration attached to the form, dispatched synchronously
        // so a dispatch attempt leaves a log row we can count.
        Plugin::getInstance()->getSettings()->dispatchIntegrationsSynchronously = true;
        $integration = new IntegrationModel();
        $integration->type = 'webhook';
        $integration->name = 'Hook';
        $integration->settings = ['url' => 'https://example.test/hook'];
        $integrations = Plugin::getInstance()->getIntegrations();
        $this->assertTrue($integrations->saveIntegration($integration));
        $integrations->toggleFormIntegration($formId, (int) $integration->id);

        $awaiting = $this->submission($formId, PaymentsService::STATUS_PENDING);
        $integrations->dispatchForSubmission($awaiting);
        $this->assertSame(0, $this->logCount((int) $awaiting->id), 'dispatch must be withheld while awaiting payment');

        // Once paid, dispatch proceeds (the webhook attempt is logged).
        $awaiting->paymentStatus = PaymentsService::STATUS_PAID;
        Craft::$app->getElements()->saveElement($awaiting);
        $integrations->dispatchForSubmission($awaiting);
        $this->assertGreaterThanOrEqual(1, $this->logCount((int) $awaiting->id), 'dispatch runs once paid');
    }

    public function testMarkCanceledOnlyAffectsPending(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_cancel');
        $formId = (int) $form->id;

        $pending = $this->submission($formId, PaymentsService::STATUS_PENDING);
        $this->payments()->markCanceled($pending);
        $this->assertSame(PaymentsService::STATUS_CANCELED, $pending->paymentStatus);

        // A settled payment is never downgraded by a later cancel.
        $paid = $this->submission($formId, PaymentsService::STATUS_PAID);
        $this->payments()->markCanceled($paid);
        $this->assertSame(PaymentsService::STATUS_PAID, $paid->paymentStatus);
    }

    public function testExpirePendingCancelsStaleAndRespectsTtl(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Pay', 'pay_expire');
        $formId = (int) $form->id;

        $stale = $this->submission($formId, PaymentsService::STATUS_PENDING);
        // Backdate the row two hours so it's well past any positive TTL.
        $this->backdate((int) $stale->id, '-2 hours');
        $fresh = $this->submission($formId, PaymentsService::STATUS_PENDING);

        // TTL = 0 disables expiry entirely.
        Plugin::getInstance()->getSettings()->paymentPendingTtlMinutes = 0;
        $this->assertSame(0, $this->payments()->expirePending());

        // TTL = 60 cancels the stale pending row but leaves the fresh one.
        Plugin::getInstance()->getSettings()->paymentPendingTtlMinutes = 60;
        $this->assertSame(1, $this->payments()->expirePending());

        $reloadedStale = Submission::find()->id((int) $stale->id)->status(null)->one();
        $reloadedFresh = Submission::find()->id((int) $fresh->id)->status(null)->one();
        $this->assertInstanceOf(Submission::class, $reloadedStale);
        $this->assertInstanceOf(Submission::class, $reloadedFresh);
        $this->assertSame(PaymentsService::STATUS_CANCELED, $reloadedStale->paymentStatus);
        $this->assertSame(PaymentsService::STATUS_PENDING, $reloadedFresh->paymentStatus);
    }

    private function backdate(int $submissionId, string $modifier): void
    {
        $when = (new \DateTime('now', new \DateTimeZone('UTC')))->modify($modifier);
        Craft::$app->getDb()->createCommand()
            ->update('{{%elements}}', ['dateCreated' => $when->format('Y-m-d H:i:s')], ['id' => $submissionId])
            ->execute();
    }

    private function logCount(int $submissionId): int
    {
        return (int) (new Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['submissionId' => $submissionId])
            ->count();
    }
}
