<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Login-required + per-user submission limits + user association (#135).
 *
 * Form-level toggles + limits live on the shared `simpleform_forms` row; the two
 * visitor-facing messages are translatable content on `simpleform_forms_sites`.
 */
class m260620_000006_form_user_limits extends Migration
{
    private const FORMS = '{{%simpleform_forms}}';
    private const FORMS_SITES = '{{%simpleform_forms_sites}}';

    public function safeUp(): bool
    {
        // Shared (non-translatable) settings.
        if (!$this->db->columnExists(self::FORMS, 'requireLogin')) {
            $this->addColumn(self::FORMS, 'requireLogin', $this->boolean()->notNull()->defaultValue(false)->after('allowSaveResume'));
        }

        if (!$this->db->columnExists(self::FORMS, 'submissionsPerUser')) {
            $this->addColumn(self::FORMS, 'submissionsPerUser', $this->integer()->null()->after('requireLogin'));
        }

        if (!$this->db->columnExists(self::FORMS, 'guestLimitKey')) {
            $this->addColumn(self::FORMS, 'guestLimitKey', $this->string(16)->notNull()->defaultValue('none')->after('submissionsPerUser'));
        }

        // Per-site (translatable) messages.
        if (!$this->db->columnExists(self::FORMS_SITES, 'loginRequiredMessage')) {
            $this->addColumn(self::FORMS_SITES, 'loginRequiredMessage', $this->text()->null()->after('emailBody'));
        }

        if (!$this->db->columnExists(self::FORMS_SITES, 'userLimitMessage')) {
            $this->addColumn(self::FORMS_SITES, 'userLimitMessage', $this->text()->null()->after('loginRequiredMessage'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach (['guestLimitKey', 'submissionsPerUser', 'requireLogin'] as $column) {
            if ($this->db->columnExists(self::FORMS, $column)) {
                $this->dropColumn(self::FORMS, $column);
            }
        }

        foreach (['userLimitMessage', 'loginRequiredMessage'] as $column) {
            if ($this->db->columnExists(self::FORMS_SITES, $column)) {
                $this->dropColumn(self::FORMS_SITES, $column);
            }
        }

        return true;
    }
}
