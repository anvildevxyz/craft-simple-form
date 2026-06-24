<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\helpers\Db;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\fields\EmailFieldType;
use fabianhaef\simpleform\fields\PaymentFieldType;
use fabianhaef\simpleform\integrations\support\SubmissionValues;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Form payments (#116). Soft-depends on Craft Commerce: a form with a Payment
 * field collects a payment on submit via the configured Commerce gateway (an
 * embedded, gateway-agnostic payment form rendered by the gateway itself) and a
 * Donation line item carrying the resolved amount. Payment is collected BEFORE
 * the submission is persisted — a decline saves nothing; an offsite/3-D-Secure
 * redirect persists a pending row and sends the visitor on to complete payment.
 * Notifications / integrations are withheld until the payment settles. Without
 * Commerce the Payment field is inert.
 *
 * The orchestration here (amount resolution, gating, status transitions) is
 * Commerce-agnostic and unit-tested; the order creation + charge path is guarded
 * behind {@see commerceAvailable()} and exercised by the live smoke suite.
 *
 * @phpstan-import-type SubmissionData from Submission
 * @phpstan-type PaymentResult array{status: string, orderId: int, amount: float, redirectUrl: string|null, error: string|null}
 */
class PaymentsService extends Component
{
    public const STATUS_PENDING = Submission::PAYMENT_PENDING;
    public const STATUS_PAID = Submission::PAYMENT_PAID;
    public const STATUS_CANCELED = Submission::PAYMENT_CANCELED;

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
        foreach ($this->fieldSet($form) as $field) {
            if ($field['type'] === PaymentFieldType::getType()) {
                return $field['config'];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldSet(Form $form): array
    {
        return Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, (int) $form->siteId);
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

        if (($config['amountType'] ?? PaymentFieldType::AMOUNT_TYPE_FIXED) === PaymentFieldType::AMOUNT_TYPE_FIELD) {
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
     * Collect payment for a submit, BEFORE the submission is persisted
     * (pay-to-submit, #116). Resolves the amount, builds a Commerce order with a
     * Donation line item, and — when the request carries gateway payment-form
     * data — charges it through the configured gateway.
     *
     * Returns null when the form collects no payment (caller proceeds normally).
     * Otherwise a result the caller writes onto the new submission:
     *  - error !== null  → decline/misconfig; caller must persist NOTHING.
     *  - status = paid   → charged; release notifications/integrations normally.
     *  - status = pending + redirectUrl → offsite/3DS or headless; persist the
     *    row pending and send the visitor to redirectUrl to finish.
     *
     * @param SubmissionData $data
     * @param array<string, mixed> $paymentParams gateway payment-form params from
     *   the request (the `paymentForm` body param); empty for headless/GraphQL.
     * @return PaymentResult|null
     */
    public function authorizeForSubmit(Form $form, array $data, array $paymentParams = []): ?array
    {
        if (!$this->requiresPayment($form)) {
            return null;
        }

        $amount = $this->resolveAmount($form, $data);
        if ($amount === null) {
            // A Payment field with no positive amount due — nothing to charge.
            return null;
        }

        if (($boundsError = $this->amountOutOfBoundsMessage($form, $amount)) !== null) {
            return $this->result('', 0, $amount, null, $boundsError);
        }

        if ($paymentParams === []) {
            return $this->result('', 0, $amount, null, Craft::t('simple-form', 'Payment information is required.'));
        }

        try {
            $gateway = $this->gateway();
            $donation = $this->donation();
            $order = $this->buildOrder($gateway, $donation, $amount, $this->submitterEmail($form, $data));
        } catch (\Throwable $e) {
            Craft::error('Payment setup failed (#116): ' . $e->getMessage(), 'simple-form');
            return $this->result('', 0, $amount, null, Craft::t('simple-form', 'Payments are not available right now. Please try again later.'));
        }

        $paymentForm = $gateway->getPaymentFormModel();
        $paymentForm->setAttributes($paymentParams, false);

        $redirect = null;
        $transaction = null;
        try {
            \craft\commerce\Plugin::getInstance()->getPayments()->processPayment($order, $paymentForm, $redirect, $transaction);
        } catch (\craft\commerce\errors\PaymentException $e) {
            Craft::warning('Payment declined (#116): ' . $e->getMessage(), 'simple-form');

            return $this->result('', 0, $amount, null, Craft::t('simple-form', 'Your payment could not be processed.'));
        }

        // Offsite / 3-D-Secure: persist pending and hand the visitor off.
        if (is_string($redirect) && $redirect !== '') {
            return $this->result(self::STATUS_PENDING, (int) $order->id, $amount, $redirect, null);
        }

        // Onsite: paid if the order settled, otherwise authorized-but-pending.
        $status = $order->getIsPaid() ? self::STATUS_PAID : self::STATUS_PENDING;
        return $this->result($status, (int) $order->id, $amount, null, null);
    }

    /**
     * Whether a resolved charge amount falls outside the Payment field's optional
     * min/max bounds. Returns a user-safe error message, or null when in range or
     * unbounded.
     */
    public function amountOutOfBoundsMessage(Form $form, float $amount): ?string
    {
        $config = $this->paymentFieldConfig($form);
        if ($config === null) {
            return null;
        }

        $min = isset($config['minAmount']) && is_numeric($config['minAmount']) ? (float) $config['minAmount'] : null;
        $max = isset($config['maxAmount']) && is_numeric($config['maxAmount']) ? (float) $config['maxAmount'] : null;

        if ($min !== null && $amount < $min) {
            return Craft::t('simple-form', 'The payment amount is below the minimum allowed.');
        }

        if ($max !== null && $amount > $max) {
            return Craft::t('simple-form', 'The payment amount exceeds the maximum allowed.');
        }

        return null;
    }

    /**
     * @return PaymentResult
     */
    private function result(string $status, int $orderId, float $amount, ?string $redirectUrl, ?string $error): array
    {
        return ['status' => $status, 'orderId' => $orderId, 'amount' => $amount, 'redirectUrl' => $redirectUrl, 'error' => $error];
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
     * Mark a pending submission's payment canceled (abandoned/expired). A no-op
     * once it has settled, so a late completion always wins over expiry.
     */
    public function markCanceled(Submission $submission): void
    {
        if ($submission->paymentStatus !== self::STATUS_PENDING) {
            return;
        }

        $submission->paymentStatus = self::STATUS_CANCELED;
        Craft::$app->getElements()->saveElement($submission);
    }

    /**
     * Cancel submissions whose payment has been pending longer than the
     * configured TTL (abandoned redirect/offsite checkouts). Returns the count
     * canceled. Disabled when the TTL is 0. Abandoned Commerce carts are reaped
     * by Commerce's own purge, so only the submission state is reconciled here.
     */
    public function expirePending(): int
    {
        $ttl = (int) Plugin::getInstance()->getSettings()->paymentPendingTtlMinutes;
        if ($ttl <= 0) {
            return 0;
        }

        $cutoff = (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$ttl} minutes");

        /** @var Submission[] $stale */
        $stale = Submission::find()
            ->siteId('*')
            ->status(null)
            ->andWhere(['paymentStatus' => self::STATUS_PENDING])
            ->andWhere(['<', 'elements.dateCreated', Db::prepareDateForDb($cutoff)])
            ->all();

        foreach ($stale as $submission) {
            $this->markCanceled($submission);
        }

        return count($stale);
    }

    /**
     * The embedded payment-form HTML rendered by the configured gateway (e.g.
     * card fields), for {@see PaymentFieldType::renderInput()}. Returns null when
     * Commerce or a usable gateway is absent, so the field degrades gracefully.
     */
    public function paymentFormHtml(): ?string
    {
        if (!$this->commerceAvailable()) {
            return null;
        }

        try {
            $html = $this->gateway()->getPaymentFormHtml([]);

            // Gateways render bare input names (number, expiry, cvv…); namespace
            // them under `paymentForm` so they post as a single array the submit
            // controller hands straight to the gateway's PaymentForm model.
            return $html === null ? null : Craft::$app->getView()->namespaceInputs($html, 'paymentForm');
        } catch (\Throwable $e) {
            Craft::warning('Could not render payment form (#116): ' . $e->getMessage(), 'simple-form');
            return null;
        }
    }

    /**
     * Build (and persist) a fresh Commerce order carrying a single Donation line
     * item for the amount, bound to the gateway and buyer email. Not completed —
     * completion happens when the charge settles.
     *
     * @throws \RuntimeException if the order can't be saved
     */
    private function buildOrder(\craft\commerce\base\Gateway $gateway, \craft\commerce\elements\Donation $donation, float $amount, ?string $email): \craft\commerce\elements\Order
    {
        $commerce = \craft\commerce\Plugin::getInstance();

        $order = new \craft\commerce\elements\Order();
        $order->gatewayId = (int) $gateway->id;
        $order->orderLanguage = Craft::$app->language;
        if ($email !== null && $email !== '') {
            $order->setEmail($email);
        }

        $lineItem = $commerce->getLineItems()->createLineItem($order, (int) $donation->id, ['donationAmount' => $amount]);
        $order->addLineItem($lineItem);

        if (!Craft::$app->getElements()->saveElement($order)) {
            throw new \RuntimeException('Could not save the payment order: ' . implode('; ', $order->getErrorSummary(true)));
        }

        return $order;
    }

    /**
     * The configured gateway (by handle), else the store's first customer-enabled
     * gateway.
     *
     * @throws \RuntimeException if no usable gateway exists
     */
    private function gateway(): \craft\commerce\base\Gateway
    {
        $gateways = \craft\commerce\Plugin::getInstance()->getGateways();
        $handle = (string) (Plugin::getInstance()->getSettings()->paymentGatewayHandle ?? '');

        $gateway = $handle !== '' ? $gateways->getGatewayByHandle($handle) : null;
        $gateway ??= $gateways->getAllCustomerEnabledGateways()->first();

        if (!$gateway instanceof \craft\commerce\base\Gateway) {
            throw new \RuntimeException('No Commerce payment gateway is configured.');
        }

        return $gateway;
    }

    /**
     * The Commerce Donation purchasable. Commerce does not seed one by default —
     * the store admin enables it under Commerce → Store Settings → Donation.
     *
     * @throws \RuntimeException if the donation purchasable is missing
     */
    private function donation(): \craft\commerce\elements\Donation
    {
        $donation = \craft\commerce\elements\Donation::find()->status(null)->one();
        if (!$donation instanceof \craft\commerce\elements\Donation || $donation->id === null) {
            throw new \RuntimeException('The Commerce Donation purchasable is not configured (Commerce → Store Settings → Donation).');
        }

        return $donation;
    }

    /**
     * Best-effort submitter email: the first email-type field's value.
     *
     * @param SubmissionData $data
     */
    private function submitterEmail(Form $form, array $data): ?string
    {
        foreach ($this->fieldSet($form) as $field) {
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
        $values = [];
        foreach ($this->fieldSet($form) as $field) {
            $values[(string) $field['name']] = SubmissionValues::value($data['field_' . $field['id']] ?? null);
        }

        return $values;
    }
}
