<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Per-form opt-in for front-end submission editing (#144), plus an optional edit
 * window — both on the shared forms row because they are form-level settings, not
 * translatable content. Also adds the per-submission edit-token state columns: we
 * persist only a SHA-256 hash of the token (the token itself lives solely in the
 * edit URL) plus an absolute expiry, so a database read alone can't reissue a
 * working edit link. New columns only — existing forms keep editing off, so there
 * is no behavior change until an operator turns it on.
 */
class m260621_000001_form_allow_editing extends Migration
{
    private const FORMS = '{{%simpleform_forms}}';
    private const SUBMISSIONS = '{{%simpleform_submissions}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::FORMS, 'allowEditing')) {
            $this->addColumn(self::FORMS, 'allowEditing', $this->boolean()->notNull()->defaultValue(false)->after('allowSaveResume'));
        }

        if (!$this->db->columnExists(self::FORMS, 'editWindowMinutes')) {
            $this->addColumn(self::FORMS, 'editWindowMinutes', $this->integer()->notNull()->defaultValue(0)->after('allowEditing'));
        }

        if (!$this->db->columnExists(self::SUBMISSIONS, 'editTokenHash')) {
            $this->addColumn(self::SUBMISSIONS, 'editTokenHash', $this->char(64)->null()->after('orderId'));
        }

        if (!$this->db->columnExists(self::SUBMISSIONS, 'editTokenExpires')) {
            $this->addColumn(self::SUBMISSIONS, 'editTokenExpires', $this->dateTime()->null()->after('editTokenHash'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach (['editTokenExpires', 'editTokenHash'] as $column) {
            if ($this->db->columnExists(self::SUBMISSIONS, $column)) {
                $this->dropColumn(self::SUBMISSIONS, $column);
            }
        }

        if ($this->db->columnExists(self::FORMS, 'editWindowMinutes')) {
            $this->dropColumn(self::FORMS, 'editWindowMinutes');
        }

        if ($this->db->columnExists(self::FORMS, 'allowEditing')) {
            $this->dropColumn(self::FORMS, 'allowEditing');
        }

        return true;
    }
}
