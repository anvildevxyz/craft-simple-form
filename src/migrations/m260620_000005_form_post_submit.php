<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Per-form post-submit behavior (#133).
 *
 * Splits the new columns by their nature:
 *  - The shared forms table gets `postSubmitAction` (the structural choice) and
 *    `redirectEntryId` (a shared Craft element id), neither of which is
 *    translatable.
 *  - The per-site forms table gets `submitMessage`, `errorMessage`, and
 *    `redirectUrl` — localized content authored per site, alongside `emailBody`.
 *
 * `postSubmitAction` defaults to `message` so existing forms keep their current
 * inline-message behavior. Per the house rule, `[[...]]`-quote any camelCase
 * identifier in raw SQL.
 */
class m260620_000005_form_post_submit extends Migration
{
    private const SHARED_TABLE = '{{%simpleform_forms}}';
    private const SITES_TABLE = '{{%simpleform_forms_sites}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::SHARED_TABLE, 'postSubmitAction')) {
            $this->addColumn(
                self::SHARED_TABLE,
                'postSubmitAction',
                $this->string(20)->notNull()->defaultValue('message')->after('allowSaveResume'),
            );
        }

        if (!$this->db->columnExists(self::SHARED_TABLE, 'redirectEntryId')) {
            $this->addColumn(
                self::SHARED_TABLE,
                'redirectEntryId',
                $this->integer()->null()->after('postSubmitAction'),
            );
        }

        if (!$this->db->columnExists(self::SITES_TABLE, 'submitMessage')) {
            $this->addColumn(self::SITES_TABLE, 'submitMessage', $this->text()->after('emailBody'));
        }

        if (!$this->db->columnExists(self::SITES_TABLE, 'errorMessage')) {
            $this->addColumn(self::SITES_TABLE, 'errorMessage', $this->text()->after('submitMessage'));
        }

        if (!$this->db->columnExists(self::SITES_TABLE, 'redirectUrl')) {
            $this->addColumn(self::SITES_TABLE, 'redirectUrl', $this->text()->after('errorMessage'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach (['redirectUrl', 'errorMessage', 'submitMessage'] as $column) {
            if ($this->db->columnExists(self::SITES_TABLE, $column)) {
                $this->dropColumn(self::SITES_TABLE, $column);
            }
        }

        foreach (['redirectEntryId', 'postSubmitAction'] as $column) {
            if ($this->db->columnExists(self::SHARED_TABLE, $column)) {
                $this->dropColumn(self::SHARED_TABLE, $column);
            }
        }

        return true;
    }
}
