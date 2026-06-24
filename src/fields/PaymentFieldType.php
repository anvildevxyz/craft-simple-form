<?php

namespace fabianhaef\simpleform\fields;

use Craft;
use fabianhaef\simpleform\Plugin;

/**
 * Payment field (#116). Marks a form as requiring payment and defines the
 * amount — either a fixed amount or read from another field's value. When Craft
 * Commerce is installed it renders the configured gateway's embedded payment
 * form (e.g. card fields) so the visitor pays on submit; the charge is processed
 * server-side and notifications/integrations are gated until it settles. Without
 * Commerce (or a usable gateway) the field degrades to an informational note.
 *
 * Config keys:
 *  - amountType: 'fixed' | 'field' (default 'fixed')
 *  - amount:     fixed amount (when amountType = fixed)
 *  - amountField: handle of a numeric field holding the amount (when 'field')
 *  - minAmount / maxAmount: optional server-side bounds on the resolved charge
 *  - currency:   ISO currency code (informational; the Commerce store currency
 *                is authoritative)
 */
class PaymentFieldType extends FieldType
{
    /** Charge a fixed amount configured on the field (default). */
    public const AMOUNT_TYPE_FIXED = 'fixed';
    /** Charge the amount entered into another numeric field on the form. */
    public const AMOUNT_TYPE_FIELD = 'field';

    public static function getType(): string
    {
        return 'payment';
    }

    public static function getLabel(): string
    {
        return 'Payment';
    }

    /**
     * The field carries no posted value of its own — the amount is resolved
     * server-side — so there is nothing to validate here.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        return [];
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $currency = strtoupper((string) ($this->config['currency'] ?? ''));

        if (($this->config['amountType'] ?? self::AMOUNT_TYPE_FIXED) === self::AMOUNT_TYPE_FIXED && is_numeric($this->config['amount'] ?? null)) {
            $amount = number_format((float) $this->config['amount'], 2);
            $label = Craft::t('simple-form', 'Amount due:') . ' ' . trim($currency . ' ' . $amount);
        } else {
            $label = Craft::t('simple-form', 'Payment is calculated from your entries.');
        }

        $note = sprintf(
            '<p class="simple-form-payment-note" data-sf-payment="1">%s</p>',
            htmlspecialchars($label, ENT_QUOTES),
        );

        // The gateway renders its own payment form (card fields, etc.), posted
        // under the `paymentForm` namespace the submit controller reads. Null
        // when Commerce/a gateway is unavailable — the note alone is shown.
        $gatewayForm = Plugin::getInstance()->getPayments()->paymentFormHtml();
        if ($gatewayForm === null) {
            return $note;
        }

        return $note . sprintf(
            '<div class="simple-form-payment-fields" data-sf-payment-fields="1">%s</div>',
            $gatewayForm,
        );
    }
}
