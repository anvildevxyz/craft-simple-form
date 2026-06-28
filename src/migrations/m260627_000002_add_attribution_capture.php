<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * UTM/referrer auto-capture (#249): a per-form opt-in flag on
 * `simpleform_forms`, and a JSON `attribution` map on `simpleform_submissions`
 * holding the captured utm_* params + referrer + landing page.
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260627_000002_add_attribution_capture extends Migration
{
    public function safeUp(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if (!$this->db->columnExists($forms, 'autoCaptureAttribution')) {
            $this->addColumn($forms, 'autoCaptureAttribution', $this->boolean()->notNull()->defaultValue(false)->after('quizGradeBands'));
        }

        $submissions = '{{%simpleform_submissions}}';
        if (!$this->db->columnExists($submissions, 'attribution')) {
            $this->addColumn($submissions, 'attribution', $this->json()->after('quizGrade'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if ($this->db->columnExists($forms, 'autoCaptureAttribution')) {
            $this->dropColumn($forms, 'autoCaptureAttribution');
        }

        $submissions = '{{%simpleform_submissions}}';
        if ($this->db->columnExists($submissions, 'attribution')) {
            $this->dropColumn($submissions, 'attribution');
        }

        return true;
    }
}
