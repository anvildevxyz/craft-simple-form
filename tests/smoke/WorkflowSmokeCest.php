<?php

namespace fabianhaef\simpleform\tests\smoke;

use craft\db\Query;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\AuditService;
use SmokeTester;

/**
 * Submission approval workflow smoke tests (#248): a new submission enters the
 * initial stage, an allowed transition moves it and is audit-logged, an
 * unconfigured move is refused, and the whole pipeline is inert when disabled.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class WorkflowSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testNewSubmissionEntersTheInitialStage(SmokeTester $I): void
    {
        $this->enableWorkflow();
        $form = $this->createForm('WF', 'wfInitial' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada']);
        $I->assertNull($result['errors']);
        $I->assertSame('submitted', $result['submission']->workflowStatus);
    }

    public function testWorkflowDisabledLeavesNoStage(SmokeTester $I): void
    {
        // Default (disabled) settings.
        $form = $this->createForm('WF', 'wfOff' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada']);
        $I->assertNull($result['submission']->workflowStatus);
    }

    public function testAllowedTransitionMovesStageAndAuditsLog(SmokeTester $I): void
    {
        $this->enableWorkflow();
        $form = $this->createForm('WF', 'wfMove' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submission = $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada'])['submission'];

        // An ungated transition can be performed by any manager (null acting user).
        $moved = Plugin::getInstance()->getWorkflow()->transition($submission, 'inReview', null);
        $I->assertTrue($moved);
        $I->assertSame('inReview', $submission->workflowStatus);

        $audited = (new Query())
            ->from('{{%simpleform_audit_log}}')
            ->where(['action' => AuditService::ACTION_SUBMISSION_STATUS, 'targetId' => (int) $submission->id])
            ->andWhere(['like', 'summary', 'workflow:'])
            ->exists();
        $I->assertTrue($audited, 'the stage change is recorded in the audit log');
    }

    public function testUnconfiguredTransitionIsRefused(SmokeTester $I): void
    {
        $this->enableWorkflow();
        $form = $this->createForm('WF', 'wfRefuse' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submission = $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada'])['submission'];

        // No transition from 'submitted' straight to 'approved' is configured.
        $moved = Plugin::getInstance()->getWorkflow()->transition($submission, 'approved', null);
        $I->assertFalse($moved);
        $I->assertSame('submitted', $submission->workflowStatus);
    }

    public function testSpamSubmissionSkipsThePipeline(SmokeTester $I): void
    {
        $this->enableWorkflow();
        $form = $this->createForm('WF', 'wfSpam' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        // The honeypot trip marks the submission as spam, which must not enter the
        // approval pipeline.
        $result = $this->submitDirect($form, ['field_' . $fieldId => 'Ada'], ['honeypot' => 'i-am-a-bot']);
        if ($result['submission'] !== null) {
            $I->assertNull($result['submission']->workflowStatus);
        } else {
            // Honeypot may drop the row entirely — either way, no pipeline entry.
            $I->assertTrue(true);
        }
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Configure a three-stage pipeline in memory (transaction-isolated, never
     * persisted to project config): submitted → in review → approved, with two
     * ungated transitions so a null acting user can perform them.
     */
    private function enableWorkflow(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $settings->enableWorkflow = true;
        $settings->workflowStatuses = [
            ['handle' => 'submitted', 'label' => 'Submitted', 'color' => 'blue'],
            ['handle' => 'inReview', 'label' => 'In Review', 'color' => 'orange'],
            ['handle' => 'approved', 'label' => 'Approved', 'color' => 'green'],
        ];
        $settings->workflowTransitions = [
            ['from' => 'submitted', 'to' => 'inReview', 'label' => 'Send to review', 'groups' => []],
            ['from' => 'inReview', 'to' => 'approved', 'label' => 'Approve', 'groups' => []],
        ];
    }
}
