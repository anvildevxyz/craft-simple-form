<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * Conversational render mode (#239): a conversational form renders one screen
 * per question with the navigator, a standard form is unaffected, and the whole
 * form still submits once through the unchanged pipeline.
 *
 * @group requires-craft
 */
class ConversationalRenderTest extends SimpleFormTestCase
{
    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }

    /**
     * @return array{0: Form, 1: int[]}
     */
    private function threeFieldForm(string $handle, string $mode): array
    {
        $form = $this->createForm('Survey', $handle, 'Survey');
        $form->renderMode = $mode;
        Craft::$app->getElements()->saveElement($form);
        $ids = [
            $this->createField((int) $form->id, 'text', 'name', 'Name'),
            $this->createField((int) $form->id, 'email', 'email', 'Email'),
            $this->createField((int) $form->id, 'text', 'city', 'City'),
        ];
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);
        return [$form, $ids];
    }

    public function testConversationalRendersOneScreenPerQuestion(): void
    {
        $this->requireCraft();
        [$form] = $this->threeFieldForm('conversationalRender', 'conversational');

        $html = Plugin::getInstance()->getFormRender()->renderForm('conversationalRender');

        // Three questions → three screens, the conversational class, and the
        // navigator with the translatable progress template.
        $this->assertStringContainsString('simple-form--conversational', $html);
        $this->assertSame(3, substr_count($html, 'class="simple-form-step"'));
        $this->assertStringContainsString('class="simple-form-step-nav"', $html);
        $this->assertStringContainsString('data-sf-progress="Question {current} of {total}"', $html);
        $this->assertStringContainsString('data-sf-multistep="3"', $html);
    }

    public function testStandardModeIsUnaffected(): void
    {
        $this->requireCraft();
        $this->threeFieldForm('standardRender', 'standard');

        $html = Plugin::getInstance()->getFormRender()->renderForm('standardRender');

        // No conversational chrome on a standard single-page form.
        $this->assertStringNotContainsString('simple-form--conversational', $html);
        $this->assertStringNotContainsString('class="simple-form-step"', $html);
        $this->assertStringNotContainsString('simple-form-step-nav', $html);
        // A plain submit button, exactly as before.
        $this->assertStringContainsString('class="simple-form-submit-btn"', $html);
    }

    public function testConversationalFormSubmitsOnceThroughTheUnchangedPipeline(): void
    {
        $this->requireCraft();
        [$form, $ids] = $this->threeFieldForm('conversationalSubmit', 'conversational');

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'conversationalSubmit',
            'field_' . $ids[0] => 'Ada',
            'field_' . $ids[1] => 'ada@example.com',
            'field_' . $ids[2] => 'London',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);
        $this->assertSame(1, (int) Submission::find()->formId((int) $form->id)->status(null)->count(), 'exactly one Submission');
        $this->assertSame('Ada', $result['submission']->data['field_' . $ids[0]]['value']);
    }
}
