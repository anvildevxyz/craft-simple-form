<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\controllers\SubmitController;
use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\models\SubmitMessageModel;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;
use craft\web\Response;

/**
 * Conditional submit messages (#265) — the evaluation seam in
 * {@see \anvildev\simpleform\services\SubmissionService::resolvePostSubmit()}:
 * first-matching-rule-wins ordering, no-match / zero-rows fall back to the
 * default, per-site message resolution, dangling field-handle safety, the
 * `message`-action scope, the edition gate, and transport parity through the
 * front-end submit endpoint.
 *
 * @group requires-craft
 */
class ConditionalSubmitMessageTest extends SimpleFormTestCase
{
    private ?string $originalEdition = null;

    protected function tearDown(): void
    {
        if ($this->originalEdition !== null) {
            Plugin::getInstance()->edition = $this->originalEdition;
            $this->originalEdition = null;
        }
        parent::tearDown();
    }

    private function service(): SubmissionService
    {
        return Plugin::getInstance()->getSubmissionService();
    }

    private function setSolo(): void
    {
        $plugin = Plugin::getInstance();
        $this->originalEdition ??= $plugin->edition;
        $plugin->edition = Editions::SOLO;
    }

    /**
     * @param array<string, mixed>|null $conditional
     */
    private function addMessage(int $formId, ?array $conditional, string $message, ?int $sortOrder = null): SubmitMessageModel
    {
        $model = new SubmitMessageModel();
        $model->formId = $formId;
        $model->conditional = $conditional;
        $model->messages = [(int) Craft::$app->getSites()->getCurrentSite()->id => $message];
        $model->sortOrder = $sortOrder;
        $this->assertTrue(Plugin::getInstance()->getSubmitMessages()->save($model), implode(', ', $model->getFirstErrors()));
        return $model;
    }

    /**
     * A `field => value` `eq` show-rule condition.
     *
     * @return array<string, mixed>
     */
    private function ruleEq(string $handle, string $value): array
    {
        return [
            'enabled' => true,
            'match' => 'all',
            'action' => 'show',
            'rules' => [['field' => $handle, 'operator' => 'eq', 'value' => $value]],
        ];
    }

    /**
     * Submit a form and return the persisted submission + data map.
     *
     * @param array<string, mixed> $values keyed by `field_<id>`
     * @return array{submission: Submission, data: array<string, mixed>}
     */
    private function submit(Form $form, array $values): array
    {
        $result = $this->service()->submit($form, $values, ['skipCaptcha' => true]);
        $this->assertInstanceOf(Submission::class, $result['submission']);
        return ['submission' => $result['submission'], 'data' => $result['data']];
    }

    private function resolve(Form $form, array $values): string
    {
        $sub = $this->submit($form, $values);
        return $this->service()->resolvePostSubmit($form, $sub['submission'], $sub['data'])['message'];
    }

    public function testZeroRowsResolveExactlyAsDefault(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM None', 'csm_none');
        $form->submitMessage = 'Default thanks';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertSame('Default thanks', $this->resolve($reloaded, ['field_' . $fieldId => 'sales']));
    }

    public function testMatchingRowOverridesDefault(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM Match', 'csm_match');
        $form->submitMessage = 'Default thanks';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');
        $this->addMessage((int) $form->id, $this->ruleEq('reason', 'sales'), 'A specialist will call you within 24h.');

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertSame('A specialist will call you within 24h.', $this->resolve($reloaded, ['field_' . $fieldId => 'sales']));
    }

    public function testNonMatchingRowFallsBackToDefault(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM NoMatch', 'csm_nomatch');
        $form->submitMessage = 'Default thanks';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');
        $this->addMessage((int) $form->id, $this->ruleEq('reason', 'sales'), 'Sales copy');

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertSame('Default thanks', $this->resolve($reloaded, ['field_' . $fieldId => 'support']));
    }

    public function testFirstBySortOrderWinsWhenMultipleMatch(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM First', 'csm_first');
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');

        // Both rules match `reason = sales`; the lower sortOrder must win.
        $this->addMessage((int) $form->id, $this->ruleEq('reason', 'sales'), 'Winner (sortOrder 1)', 1);
        $this->addMessage((int) $form->id, $this->ruleEq('reason', 'sales'), 'Loser (sortOrder 2)', 2);

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertSame('Winner (sortOrder 1)', $this->resolve($reloaded, ['field_' . $fieldId => 'sales']));
    }

    public function testMatchedMessageIsInterpolated(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM Interp', 'csm_interp');
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');
        $this->addMessage((int) $form->id, $this->ruleEq('reason', 'sales'), 'Thanks, {reason} team notified.');

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertSame('Thanks, sales team notified.', $this->resolve($reloaded, ['field_' . $fieldId => 'sales']));
    }

    public function testDanglingFieldHandleEvaluatesAsNonMatching(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM Dangling', 'csm_dangling');
        $form->submitMessage = 'Default thanks';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');
        // The rule references a handle that does not exist on the form.
        $this->addMessage((int) $form->id, $this->ruleEq('deletedHandle', 'sales'), 'Should never show');

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertSame('Default thanks', $this->resolve($reloaded, ['field_' . $fieldId => 'sales']));
    }

    public function testUrlActionNeverEvaluatesConditionalMessages(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM Url', 'csm_url');
        $form->postSubmitAction = 'url';
        $form->redirectUrl = '/thanks';
        $form->submitMessage = 'Default thanks';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');
        $this->addMessage((int) $form->id, $this->ruleEq('reason', 'sales'), 'Conditional copy');

        $reloaded = Form::find()->id($form->id)->one();
        // The url action keeps the default message untouched (redirect is separate).
        $this->assertSame('Default thanks', $this->resolve($reloaded, ['field_' . $fieldId => 'sales']));
    }

    public function testDowngradedSoloSkipsEvaluationWithoutError(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM Downgrade', 'csm_downgrade');
        $form->submitMessage = 'Default thanks';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');
        // Row created while effectively Pro.
        $this->addMessage((int) $form->id, $this->ruleEq('reason', 'sales'), 'Pro-only copy');

        $this->setSolo();

        $reloaded = Form::find()->id($form->id)->one();
        // A downgraded install keeps the stored row but skips evaluating it.
        $this->assertSame('Default thanks', $this->resolve($reloaded, ['field_' . $fieldId => 'sales']));
    }

    public function testSubmitControllerJsonReflectsResolvedConditionalMessage(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CSM Transport', 'csm_transport');
        $form->submitMessage = 'Default thanks';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField((int) $form->id, 'text', 'reason', 'Reason');
        $this->addMessage((int) $form->id, $this->ruleEq('reason', 'sales'), 'A specialist will call you.');

        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => 'csm_transport', 'field_' . $fieldId => 'sales']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new SubmitController('submit', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $data = $controller->actionIndex()->data;

        $this->assertIsArray($data);
        $this->assertTrue($data['success']);
        $this->assertSame('A specialist will call you.', $data['message']);
        $this->assertNull($data['redirectUrl']);
    }
}
