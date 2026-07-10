<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Query-string prefill (#316): a per-form `prefillFromQuery` default on
 * `simpleform_forms`. When on, visible fields opt into query-string prefill by
 * default; each field can still override with its own `prefillFromQuery` config
 * flag. Off (the default) keeps existing forms unaffected.
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260709_000001_add_query_prefill extends Migration
{
    public function safeUp(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if (!$this->db->columnExists($forms, 'prefillFromQuery')) {
            $this->addColumn($forms, 'prefillFromQuery', $this->boolean()->notNull()->defaultValue(false)->after('renderMode'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if ($this->db->columnExists($forms, 'prefillFromQuery')) {
            $this->dropColumn($forms, 'prefillFromQuery');
        }

        return true;
    }
}
