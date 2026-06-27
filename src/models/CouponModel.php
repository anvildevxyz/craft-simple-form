<?php

namespace anvildev\simpleform\models;

use craft\base\Model;
use DateTime;

/**
 * A site-owner-defined payment coupon (#246): a fixed or percentage discount
 * applied to a Commerce payment-form's amount, with an optional expiry and usage
 * limit.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class CouponModel extends Model
{
    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENTAGE = 'percentage';

    public ?int $id = null;
    public string $code = '';
    public string $type = self::TYPE_FIXED;
    public float $amount = 0.0;
    public ?DateTime $expiryDate = null;
    /** Max times the coupon may be redeemed; null = unlimited. */
    public ?int $maxUsages = null;
    public int $usageCount = 0;
    public bool $enabled = true;
    public ?string $uid = null;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['code'], 'required'],
            [['code'], 'string', 'max' => 64],
            [['type'], 'in', 'range' => [self::TYPE_FIXED, self::TYPE_PERCENTAGE]],
            [['amount'], 'number', 'min' => 0],
            // A percentage discount can't exceed 100%.
            [['amount'], 'number', 'max' => 100, 'when' => fn(): bool => $this->type === self::TYPE_PERCENTAGE],
            [['maxUsages'], 'integer', 'min' => 1],
            [['usageCount'], 'integer', 'min' => 0],
            [['enabled'], 'boolean'],
        ];
    }

    /**
     * Whether the coupon may currently be redeemed (enabled, unexpired, and under
     * its usage cap). The reason a coupon is unusable is surfaced separately by
     * the service so the visitor gets a specific message.
     */
    public function isRedeemable(): bool
    {
        return $this->enabled && !$this->isExpired() && !$this->isUsedUp();
    }

    public function isExpired(): bool
    {
        return $this->expiryDate !== null && $this->expiryDate < new DateTime('now', new \DateTimeZone('UTC'));
    }

    public function isUsedUp(): bool
    {
        return $this->maxUsages !== null && $this->usageCount >= $this->maxUsages;
    }

    /**
     * The discount this coupon applies to an amount, in the payment currency,
     * never more than the amount itself (the total can't go negative).
     */
    public function discountFor(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $discount = $this->type === self::TYPE_PERCENTAGE
            ? $amount * ($this->amount / 100)
            : $this->amount;

        return round(min(max($discount, 0.0), $amount), 2);
    }
}
