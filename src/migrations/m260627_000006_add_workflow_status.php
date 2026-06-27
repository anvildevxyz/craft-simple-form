<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Configurable submission approval workflow (#248): a nullable per-submission
 * workflow-stage handle, separate from the existing new/read/archived/spam
 * triage status. Null = not in a custom pipeline (the default, identical to
 * today); a handle places the submission at that owner-defined stage.
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260627_000006_add_workflow_status extends Migration
{
    public function safeUp(): bool
    {
        $submissions = '{{%simpleform_submissions}}';
        if (!$this->db->columnExists($submissions, 'workflowStatus')) {
            $this->addColumn($submissions, 'workflowStatus', $this->string(64)->after('readStatus'));
            $this->createIndex(null, $submissions, ['workflowStatus']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $submissions = '{{%simpleform_submissions}}';
        if ($this->db->columnExists($submissions, 'workflowStatus')) {
            $this->dropColumn($submissions, 'workflowStatus');
        }

        return true;
    }
}
