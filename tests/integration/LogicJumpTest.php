<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;

/**
 * Logic jumps (#245) end-to-end: the server replays the jump path so a required
 * field on a step the answers jumped over does not block submission, while the
 * same field still applies when the path doesn't skip it. The render also emits
 * the jump rules the navigator reads.
 *
 * @group requires-craft
 */
class LogicJumpTest extends SimpleFormTestCase
{
    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }

    /**
     * Three-page form: page 1 a "plan" select that jumps straight to the email
     * page (skipping the required "company" page) when plan = personal.
     *
     * @return array{0: Form, 1: int, 2: int, 3: int}
     */
    private function jumpForm(string $handle): array
    {
        $form = $this->createForm('Signup', $handle, 'Signup');
        Craft::$app->getElements()->saveElement($form);

        $planId = $this->createField((int) $form->id, 'select', 'plan', 'Plan', true, [
            'page' => 1,
            'options' => [
                ['value' => 'personal', 'label' => 'Personal'],
                ['value' => 'business', 'label' => 'Business'],
            ],
            'jumps' => [
                ['operator' => 'eq', 'value' => 'personal', 'target' => 'email'],
            ],
        ]);
        $companyId = $this->createField((int) $form->id, 'text', 'company', 'Company', true, ['page' => 2]);
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email', true, ['page' => 3]);

        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        return [$form, $planId, $companyId, $emailId];
    }

    public function testJumpedOverRequiredFieldDoesNotBlockSubmission(): void
    {
        $this->requireCraft();
        [$form, $planId, $companyId, $emailId] = $this->jumpForm('jumpSkip');

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'jumpSkip',
            'field_' . $planId => 'personal',   // jumps past the required Company page
            'field_' . $emailId => 'ada@example.com',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['errors'], 'jumped-over required field must not block submission');
        $this->assertInstanceOf(Submission::class, $result['submission']);
        // The skipped field is not part of the stored submission.
        $this->assertArrayNotHasKey('field_' . $companyId, $result['submission']->data ?? []);
    }

    public function testRequiredFieldOnTheTakenPathStillApplies(): void
    {
        $this->requireCraft();
        [$form, $planId, $companyId, $emailId] = $this->jumpForm('jumpNoSkip');

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'jumpNoSkip',
            'field_' . $planId => 'business',   // no jump → Company page is on the path
            'field_' . $emailId => 'ada@example.com',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission'], 'a required field on the taken path still blocks');
        $this->assertIsArray($result['errors']);
        $this->assertArrayHasKey('field_' . $companyId, $result['errors']);
    }

    public function testRenderEmitsJumpRulesForTheNavigator(): void
    {
        $this->requireCraft();
        $this->jumpForm('jumpRender');

        $html = Plugin::getInstance()->getFormRender()->renderForm('jumpRender');

        $this->assertStringContainsString('data-sf-jumps=', $html);
        // The plan field's jump target resolves to the email page (step index 2).
        $this->assertStringContainsString('&quot;to&quot;:2', $html);
    }
}
