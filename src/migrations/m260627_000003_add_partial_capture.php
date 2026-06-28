<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Passive partial capture (#242): a per-form opt-in on `simpleform_forms`, and a
 * `passive` flag on `simpleform_form_drafts` distinguishing an auto-captured
 * partial from a user-initiated save-and-continue draft.
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260627_000003_add_partial_capture extends Migration
{
    public function safeUp(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if (!$this->db->columnExists($forms, 'capturePartials')) {
            $this->addColumn($forms, 'capturePartials', $this->boolean()->notNull()->defaultValue(false)->after('autoCaptureAttribution'));
        }

        $drafts = '{{%simpleform_form_drafts}}';
        if (!$this->db->columnExists($drafts, 'passive')) {
            $this->addColumn($drafts, 'passive', $this->boolean()->notNull()->defaultValue(false)->after('data'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if ($this->db->columnExists($forms, 'capturePartials')) {
            $this->dropColumn($forms, 'capturePartials');
        }

        $drafts = '{{%simpleform_form_drafts}}';
        if ($this->db->columnExists($drafts, 'passive')) {
            $this->dropColumn($drafts, 'passive');
        }

        return true;
    }
}
