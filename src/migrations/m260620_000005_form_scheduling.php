<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Form scheduling (open/close dates) + a total submission cap.
 *
 * The window bounds and the cap are form-level settings, so they live on the
 * shared `simpleform_forms` row (not translatable). The "closed" message shown
 * in place of the form is per-site content, so it lives on
 * `simpleform_forms_sites`. All four columns are nullable — a form with none
 * set behaves exactly as before.
 */
class m260620_000005_form_scheduling extends Migration
{
    private const FORMS_TABLE = '{{%simpleform_forms}}';
    private const FORMS_SITES_TABLE = '{{%simpleform_forms_sites}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS_TABLE, 'openDate')) {
            $this->addColumn(self::FORMS_TABLE, 'openDate', $this->dateTime()->null()->after('allowSaveResume'));
        }

        if (!$this->db->columnExists(self::FORMS_TABLE, 'closeDate')) {
            $this->addColumn(self::FORMS_TABLE, 'closeDate', $this->dateTime()->null()->after('openDate'));
        }

        if (!$this->db->columnExists(self::FORMS_TABLE, 'submissionLimit')) {
            $this->addColumn(self::FORMS_TABLE, 'submissionLimit', $this->integer()->unsigned()->null()->after('closeDate'));
        }

        if (!$this->db->columnExists(self::FORMS_SITES_TABLE, 'closedMessage')) {
            $this->addColumn(self::FORMS_SITES_TABLE, 'closedMessage', $this->text()->null()->after('emailBody'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::FORMS_SITES_TABLE, 'closedMessage')) {
            $this->dropColumn(self::FORMS_SITES_TABLE, 'closedMessage');
        }

        foreach (['submissionLimit', 'closeDate', 'openDate'] as $column) {
            if ($this->db->columnExists(self::FORMS_TABLE, $column)) {
                $this->dropColumn(self::FORMS_TABLE, $column);
            }
        }

        return true;
    }
}
