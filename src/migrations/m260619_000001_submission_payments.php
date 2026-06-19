<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Payment state on submissions (#116, minimal scope): when a form has a Payment
 * field, the submission records the amount due, an optional linked Commerce
 * order, and a payment status (pending → paid) used to gate notifications /
 * integrations until payment completes.
 */
class m260619_000001_submission_payments extends Migration
{
    private const TABLE = '{{%simpleform_submissions}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'paymentStatus')) {
            // null = no payment required; 'pending' = awaiting; 'paid' = complete.
            $this->addColumn(self::TABLE, 'paymentStatus', $this->string(20)->after('readStatus'));
        }
        if (!$this->db->columnExists(self::TABLE, 'paymentAmount')) {
            $this->addColumn(self::TABLE, 'paymentAmount', $this->decimal(14, 4)->after('paymentStatus'));
        }
        if (!$this->db->columnExists(self::TABLE, 'orderId')) {
            $this->addColumn(self::TABLE, 'orderId', $this->integer()->after('paymentAmount'));
            $this->createIndex(null, self::TABLE, ['orderId']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach (['orderId', 'paymentAmount', 'paymentStatus'] as $col) {
            if ($this->db->columnExists(self::TABLE, $col)) {
                $this->dropColumn(self::TABLE, $col);
            }
        }

        return true;
    }
}
