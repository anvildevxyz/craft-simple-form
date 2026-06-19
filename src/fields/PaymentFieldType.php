<?php

namespace fabianhaef\simpleform\fields;

use Craft;

/**
 * Payment field (#116, minimal scope). Marks a form as requiring payment and
 * defines the amount — either a fixed amount or read from another field's value.
 * On submit (when Craft Commerce is installed) a pending order is created and
 * notifications/integrations are gated until payment completes.
 *
 * Config keys:
 *  - amountType: 'fixed' | 'field' (default 'fixed')
 *  - amount:     fixed amount (when amountType = fixed)
 *  - amountField: handle of a numeric field holding the amount (when 'field')
 *  - currency:   ISO currency code (informational; the Commerce store currency
 *                is authoritative)
 */
class PaymentFieldType extends FieldType
{
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

        if (($this->config['amountType'] ?? 'fixed') === 'fixed' && is_numeric($this->config['amount'] ?? null)) {
            $amount = number_format((float) $this->config['amount'], 2);
            $label = Craft::t('simple-form', 'Amount due:') . ' ' . trim($currency . ' ' . $amount);
        } else {
            $label = Craft::t('simple-form', 'Payment is calculated from your entries.');
        }

        return sprintf(
            '<p class="simple-form-payment-note" data-sf-payment="1">%s</p>',
            htmlspecialchars($label, ENT_QUOTES),
        );
    }
}
