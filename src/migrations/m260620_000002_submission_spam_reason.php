<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Record WHY a submission was flagged as spam so the spam-review queue can show
 * it: `akismet` when Akismet scored it, `manual` when a CP user marked it.
 * Null for non-spam submissions.
 */
class m260620_000002_submission_spam_reason extends Migration
{
    private const TABLE = '{{%simpleform_submissions}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'spamReason')) {
            $this->addColumn(self::TABLE, 'spamReason', $this->string(64)->after('readStatus'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'spamReason')) {
            $this->dropColumn(self::TABLE, 'spamReason');
        }

        return true;
    }
}
