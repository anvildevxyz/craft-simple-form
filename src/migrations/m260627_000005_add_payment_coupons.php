<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Payment coupons (#246): a site-owner-defined coupon table (fixed/percentage
 * discount, optional expiry + usage limit) and the applied coupon recorded on
 * each submission.
 *
 * Idempotent (table/column-existence guarded) because the integration/smoke
 * suites re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260627_000005_add_payment_coupons extends Migration
{
    public function safeUp(): bool
    {
        $coupons = '{{%simpleform_coupons}}';
        if (!$this->db->tableExists($coupons)) {
            $this->createTable($coupons, [
                'id' => $this->primaryKey(),
                'code' => $this->string(64)->notNull(),
                'type' => $this->string(16)->notNull()->defaultValue('fixed'),
                'amount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
                'expiryDate' => $this->dateTime(),
                'maxUsages' => $this->integer()->unsigned(),
                'usageCount' => $this->integer()->notNull()->defaultValue(0),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            // Codes are matched case-insensitively in the service, but a unique
            // index keeps exact duplicates out.
            $this->createIndex(null, $coupons, ['code'], true);
        }

        $submissions = '{{%simpleform_submissions}}';
        if (!$this->db->columnExists($submissions, 'couponCode')) {
            $this->addColumn($submissions, 'couponCode', $this->string(64)->after('orderId'));
        }
        if (!$this->db->columnExists($submissions, 'discountAmount')) {
            $this->addColumn($submissions, 'discountAmount', $this->decimal(14, 4)->after('couponCode'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $submissions = '{{%simpleform_submissions}}';
        foreach (['discountAmount', 'couponCode'] as $column) {
            if ($this->db->columnExists($submissions, $column)) {
                $this->dropColumn($submissions, $column);
            }
        }

        $this->dropTableIfExists('{{%simpleform_coupons}}');

        return true;
    }
}
