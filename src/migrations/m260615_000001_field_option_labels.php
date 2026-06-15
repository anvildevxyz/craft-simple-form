<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Per-site translatable option labels for select/radio/checkbox fields.
 *
 * The option *value* stays canonical (and locale-independent) on the shared
 * field config; per-site label overrides live here, keyed by option value, so a
 * missing translation falls back to the source label and submitted/stored
 * values never differ across sites.
 */
class m260615_000001_field_option_labels extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%simpleform_fields_sites}}';

        // value => localized label map for this (field, site); null when no
        // option labels have been translated for the site.
        if (!$this->db->columnExists($table, 'optionLabels')) {
            $this->addColumn($table, 'optionLabels', $this->json()->after('helpText'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%simpleform_fields_sites}}';

        if ($this->db->columnExists($table, 'optionLabels')) {
            $this->dropColumn($table, 'optionLabels');
        }

        return true;
    }
}
