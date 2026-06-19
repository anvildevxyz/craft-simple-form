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

    private function logCount(int $submissionId): int
    {
        return (int) (new Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['submissionId' => $submissionId])
            ->count();
    }
}
