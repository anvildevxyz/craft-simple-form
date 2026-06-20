<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Per-form custom render-template path (#137). A nullable site-templates path
 * (e.g. `_simple-form/landing`) pointing at a theme directory of Twig partials
 * that override the plugin's built-in form markup. Lives on the shared forms row
 * because it is structural, not translatable content.
 */
class m260620_000005_form_template_path extends Migration
{
    private const TABLE = '{{%simpleform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'templatePath')) {
            $this->addColumn(self::TABLE, 'templatePath', $this->string()->null()->after('allowSaveResume'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'templatePath')) {
            $this->dropColumn(self::TABLE, 'templatePath');
        }

        return true;
    }
}
