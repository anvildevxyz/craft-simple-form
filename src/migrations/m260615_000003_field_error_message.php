<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Optional per-site validation error-message override for a field.
 *
 * When set, a failed submission reports this message (in the editor's own
 * wording/language) instead of the field type's default; when null the default
 * resolves through the translation catalogs for the active language, so the
 * message is always localized and never blank.
 */
class m260615_000003_field_error_message extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%simpleform_fields_sites}}';

        if (!$this->db->columnExists($table, 'errorMessage')) {
            $this->addColumn($table, 'errorMessage', $this->text()->after('optionLabels'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%simpleform_fields_sites}}';

        if ($this->db->columnExists($table, 'errorMessage')) {
            $this->dropColumn($table, 'errorMessage');
        }

        return true;
    }
}
