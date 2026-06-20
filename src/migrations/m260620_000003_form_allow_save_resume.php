<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Per-form opt-in for save-&-resume (drafts). Lives on the shared forms row
 * because it is a form-level setting, not translatable content.
 */
class m260620_000003_form_allow_save_resume extends Migration
{
    private const TABLE = '{{%simpleform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'allowSaveResume')) {
            $this->addColumn(self::TABLE, 'allowSaveResume', $this->boolean()->notNull()->defaultValue(false)->after('propagationMethod'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'allowSaveResume')) {
            $this->dropColumn(self::TABLE, 'allowSaveResume');
        }

        return true;
    }
}
