<?php

namespace fabianhaef\simpleform\services;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\fields\EmailFieldType;
use fabianhaef\simpleform\fields\PaymentFieldType;
use fabianhaef\simpleform\integrations\support\SubmissionValues;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Form payments (#116, minimal scope). Soft-depends on Craft Commerce: a form
 * with a Payment field creates a pending Commerce order on submit (a Donation
 * line item carrying the resolved amount), and notifications / integrations are
 * gated until the order completes. Without Commerce the Payment field is inert.
 *
 * The orchestration here (amount resolution, gating, status transitions) is
 * Commerce-agnostic and unit-tested; the order creation + completion path is
 * guarded behind {@see commerceAvailable()}.
 *
 * @phpstan-import-type SubmissionData from Submission
 */
class PaymentsService extends Component
{
    public const STATUS_PENDING = Submission::PAYMENT_PENDING;
    public const STATUS_PAID = Submission::PAYMENT_PAID;

    public function commerceAvailable(): bool
    {
        return class_exists(\craft\commerce\Plugin::class);
    }

    /**
     * The Payment field's config on a form, or null if it has none.
     *
     * @return array<string, mixed>|null
     */
    public function paymentFieldConfig(Form $form): ?array
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, (int) $form->siteId);
        foreach ($fields as $field) {
            if ($field['type'] === PaymentFieldType::getType()) {
                return $field['config'];
            }
        }

        return null;
    }

    /**
     * Whether a submission of this form should collect payment (has a Payment
     * field and Commerce is installed).
     */
    public function requiresPayment(Form $form): bool
    {
        return $this->commerceAvailable() && $this->paymentFieldConfig($form) !== null;
    }

    /**
     * Resolve the amount due from the Payment field config + submitted data:
     * a fixed amount, or the value of another field (by handle). Returns null
     * when no positive amount is configured.
     *
     * @param SubmissionData $data submission data keyed by field_<id>
     */
    public function resolveAmount(Form $form, array $data): ?float
    {
        $config = $this->paymentFieldConfig($form);
        if ($config === null) {
            return null;
        }

        if (($config['amountType'] ?? 'fixed') === 'field') {
            $handle = (string) ($config['amountField'] ?? '');
            $amount = $handle !== '' ? ($this->valuesByHandle($form, $data)[$handle] ?? null) : null;
        } else {
            $amount = $config['amount'] ?? null;
        }

        $amount = is_numeric($amount) ? (float) $amount : 0.0;
        return $amount > 0 ? round($amount, 2) : null;
    }

    public function isAwaitingPayment(Submission $submission): bool
    {
        return $submission->isAwaitingPayment();
    }

    /**
     * If the submission's form requires payment, record the amount, create a
     * pending Commerce order, and store it on the submission. Returns true when
     * the submission is now awaiting payment (so the caller gates email).
     *
     * @param SubmissionData $data
     */
    public function prepare(Form $form, Submission $submission, array $data): bool
    {
        if (!$this->requiresPayment($form)) {
            return false;
        }

        $amount = $this->resolveAmount($form, $data);
        if ($amount === null) {
            return false;
        }

        $email = $this->submitterEmail($form, $data);
        $orderId = $this->createOrder($amount, $email);
        if ($orderId === null) {
            // Commerce missing or order creation failed — don't strand the
            // submission; proceed without gating.
            Craft::warning('Payment required but no Commerce order could be created; proceeding ungated.', 'simple-form');
            return false;
        }

        $submission->paymentStatus = self::STATUS_PENDING;
        $submission->paymentAmount = (string) $amount;
        $submission->orderId = $orderId;
        Craft::$app->getElements()->saveElement($submission);

        return true;
    }

    /**
     * Mark a submission paid and release its gated notifications + integrations.
     */
    public function markPaid(Submission $submission): void
    {
        if ($submission->paymentStatus === self::STATUS_PAID) {
            return;
        }

        $submission->paymentStatus = self::STATUS_PAID;
        Craft::$app->getElements()->saveElement($submission);

        $form = $submission->getForm();
        if (!$form instanceof Form) {
            return;
        }

        // Integration dispatch and the notification email are withheld until
        // payment clears (see IntegrationsService/NotificationsService gating).
        Plugin::getInstance()->getIntegrations()->dispatchForSubmission($submission);
        Plugin::getInstance()->getEmailService()->queueForSubmission($form, $submission, $submission->data ?? []);
    }

    /**
     * Handle a completed Commerce order: mark its linked submission paid.
     */
    public function handleOrderCompleted(int $orderId): void
    {
        $submission = Submission::find()->siteId('*')->andWhere(['orderId' => $orderId])->one();
        if ($submission instanceof Submission && $this->isAwaitingPayment($submission)) {
            $this->markPaid($submission);
        }
    }

    /**
     * Create a pending Commerce order (session cart) with a Donation line item
     * carrying the amount. Returns the order id, or null if Commerce is absent
     * or the order can't be built.
     */
    private function createOrder(float $amount, ?string $email): ?int
    {
        if (!$this->commerceAvailable()) {
            return null;
        }

        try {
            $commerce = \craft\commerce\Plugin::getInstance();
            $donation = \craft\commerce\elements\Donation::find()->status(null)->one();
            if ($donation === null || $donation->id === null) {
                return null;
            }

            $cart = $commerce->getCarts()->getCart(true);
            $lineItem = $commerce->getLineItems()->createLineItem(
                $cart,
                (int) $donation->id,
                ['donationAmount' => $amount],
            );
            $cart->addLineItem($lineItem);
            if ($email !== null && $email !== '') {
                $cart->setEmail($email);
            }

            if (!Craft::$app->getElements()->saveElement($cart)) {
                return null;
            }

            return (int) $cart->id;
        } catch (\Throwable $e) {
            Craft::warning('Commerce order creation failed: ' . $e->getMessage(), 'simple-form');
            return null;
        }
    }

    /**
     * Best-effort submitter email: the first email-type field's value.
     *
     * @param SubmissionData $data
     */
    private function submitterEmail(Form $form, array $data): ?string
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, (int) $form->siteId);
        foreach ($fields as $field) {
            if ($field['type'] === EmailFieldType::getType()) {
                $value = SubmissionValues::value($data['field_' . $field['id']] ?? null);
                if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param SubmissionData $data
     * @return array<string, mixed>
     */
    private function valuesByHandle(Form $form, array $data): array
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, (int) $form->siteId);
        $values = [];
        foreach ($fields as $field) {
            $values[(string) $field['name']] = SubmissionValues::value($data['field_' . $field['id']] ?? null);
        }

        return $values;
    }
}
