<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\models\CouponModel;
use yii\base\Component;

/**
 * CRUD + redemption logic for payment coupons (#246). The discount math lives on
 * {@see CouponModel} (pure, unit-tested); this service owns persistence,
 * case-insensitive lookup, server-side evaluation against an amount, and the
 * usage counter the payment path bumps on a successful charge.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class CouponsService extends Component
{
    private const TABLE = '{{%simpleform_coupons}}';

    /**
     * @return list<CouponModel>
     */
    public function getAll(): array
    {
        return array_map(
            fn(array $row): CouponModel => $this->rowToModel($row),
            (new Query())->from(self::TABLE)->orderBy(['code' => SORT_ASC])->all(),
        );
    }

    public function getById(int $id): ?CouponModel
    {
        $row = (new Query())->from(self::TABLE)->where(['id' => $id])->one();
        return is_array($row) ? $this->rowToModel($row) : null;
    }

    /**
     * Look up a coupon by code, case-insensitively (portable LOWER() match so the
     * behaviour is the same on MySQL and Postgres). Blank code returns null.
     */
    public function getByCode(string $code): ?CouponModel
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['=', 'LOWER([[code]])', mb_strtolower($code)])
            ->one();

        return is_array($row) ? $this->rowToModel($row) : null;
    }

    /**
     * Validate a code + amount and resolve the discount, server-side (the front
     * end never sends a trusted total). Returns the resolved coupon, the discount
     * and net total, or a user-safe `error` message when it can't be applied.
     *
     * @return array{coupon: CouponModel|null, discount: float, total: float, error: string|null}
     */
    public function evaluate(string $code, float $amount): array
    {
        $fail = fn(string $message): array => ['coupon' => null, 'discount' => 0.0, 'total' => round($amount, 2), 'error' => $message];

        if (trim($code) === '') {
            return $fail(Craft::t('simple-form', 'Enter a coupon code.'));
        }

        $coupon = $this->getByCode($code);
        // Don't distinguish unknown from disabled — both are simply "not valid".
        if ($coupon === null || !$coupon->enabled) {
            return $fail(Craft::t('simple-form', 'This coupon code isn’t valid.'));
        }
        if ($coupon->isExpired()) {
            return $fail(Craft::t('simple-form', 'This coupon code has expired.'));
        }
        if ($coupon->isUsedUp()) {
            return $fail(Craft::t('simple-form', 'This coupon code has reached its usage limit.'));
        }

        $discount = $coupon->discountFor($amount);

        return [
            'coupon' => $coupon,
            'discount' => $discount,
            'total' => round($amount - $discount, 2),
            'error' => null,
        ];
    }

    /**
     * Insert/update a coupon. Validates the model and rejects a duplicate code
     * (case-insensitive) on another row. Returns false with errors set on failure.
     */
    public function save(CouponModel $coupon): bool
    {
        $coupon->code = trim($coupon->code);
        if (!$coupon->validate()) {
            return false;
        }

        $existing = $this->getByCode($coupon->code);
        if ($existing !== null && $existing->id !== $coupon->id) {
            $coupon->addError('code', Craft::t('simple-form', 'A coupon with this code already exists.'));
            return false;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $row = [
            'code' => $coupon->code,
            'type' => $coupon->type,
            'amount' => $coupon->amount,
            'expiryDate' => $coupon->expiryDate !== null ? Db::prepareDateForDb($coupon->expiryDate) : null,
            'maxUsages' => $coupon->maxUsages,
            'enabled' => $coupon->enabled,
            'dateUpdated' => $now,
        ];

        $db = Craft::$app->getDb();
        if ($coupon->id !== null) {
            $db->createCommand()->update(self::TABLE, $row, ['id' => $coupon->id])->execute();
        } else {
            $db->createCommand()->insert(self::TABLE, $row + [
                'usageCount' => 0,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
            $coupon->id = (int) $db->getLastInsertID();
        }

        return true;
    }

    public function delete(int $id): bool
    {
        return (int) Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['id' => $id])->execute() > 0;
    }

    /**
     * Atomically bump a coupon's redemption counter — called once a charge that
     * used it settles, so the usage limit is honored.
     */
    public function incrementUsage(int $id): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, ['usageCount' => new \yii\db\Expression('[[usageCount]] + 1')], ['id' => $id])
            ->execute();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToModel(array $row): CouponModel
    {
        $coupon = new CouponModel();
        $coupon->id = (int) $row['id'];
        $coupon->code = (string) $row['code'];
        $coupon->type = (string) $row['type'];
        $coupon->amount = (float) $row['amount'];
        $coupon->expiryDate = !empty($row['expiryDate']) ? new \DateTime((string) $row['expiryDate'], new \DateTimeZone('UTC')) : null;
        $coupon->maxUsages = $row['maxUsages'] !== null ? (int) $row['maxUsages'] : null;
        $coupon->usageCount = (int) $row['usageCount'];
        $coupon->enabled = (bool) $row['enabled'];
        $coupon->uid = $row['uid'] ?? null;

        return $coupon;
    }
}
