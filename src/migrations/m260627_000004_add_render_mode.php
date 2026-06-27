<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Conversational render mode (#239): a per-form `renderMode` on
 * `simpleform_forms` (`standard` default, `conversational`).
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260627_000004_add_render_mode extends Migration
{
    public function safeUp(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if (!$this->db->columnExists($forms, 'renderMode')) {
            $this->addColumn($forms, 'renderMode', $this->string(20)->notNull()->defaultValue('standard')->after('capturePartials'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if ($this->db->columnExists($forms, 'renderMode')) {
            $this->dropColumn($forms, 'renderMode');
        }

        return true;
    }
}
