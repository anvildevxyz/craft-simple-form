<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Per-form duplicate-submission prevention (#140). Three new columns on the
 * shared forms row (form-level settings, not translatable content):
 *  - `preventDuplicates` — opt-in toggle.
 *  - `duplicateWindowMinutes` — lookback window; 0 = "ever".
 *  - `duplicateKey` — what makes two submissions a duplicate: `email`, `content`, or `ip`.
 *
 * Plus a `sourceIp` column on the submissions row so the `ip` dedupe key can
 * compare against prior submissions across requests.
 *
 * New columns only; no data backfill, no breaking change.
 */
class m260620_000005_form_prevent_duplicates extends Migration
{
    private const FORMS = '{{%simpleform_forms}}';
    private const SUBMISSIONS = '{{%simpleform_submissions}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'preventDuplicates')) {
            $this->addColumn(self::FORMS, 'preventDuplicates', $this->boolean()->notNull()->defaultValue(false)->after('allowSaveResume'));
        }

        if (!$this->db->columnExists(self::FORMS, 'duplicateWindowMinutes')) {
            $this->addColumn(self::FORMS, 'duplicateWindowMinutes', $this->integer()->notNull()->defaultValue(0)->after('preventDuplicates'));
        }

        if (!$this->db->columnExists(self::FORMS, 'duplicateKey')) {
            $this->addColumn(self::FORMS, 'duplicateKey', $this->string(16)->notNull()->defaultValue('email')->after('duplicateWindowMinutes'));
        }

        if (!$this->db->columnExists(self::SUBMISSIONS, 'sourceIp')) {
            $this->addColumn(self::SUBMISSIONS, 'sourceIp', $this->string(45)->after('spamReason'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::SUBMISSIONS, 'sourceIp')) {
            $this->dropColumn(self::SUBMISSIONS, 'sourceIp');
        }

        foreach (['duplicateKey', 'duplicateWindowMinutes', 'preventDuplicates'] as $column) {
            if ($this->db->columnExists(self::FORMS, $column)) {
                $this->dropColumn(self::FORMS, $column);
            }
        }

        return true;
    }
}
