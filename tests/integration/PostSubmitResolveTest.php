<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;

/**
 * Per-form post-submit resolution (#133): message fallback to global Settings,
 * `{handle}`/`{submissionId}` interpolation, URL encoding, and the entry action.
 *
 * @group requires-craft
 */
class PostSubmitResolveTest extends SimpleFormTestCase
{
    private function service(): SubmissionService
    {
        return Plugin::getInstance()->getSubmissionService();
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
        $this->assertArrayHasKey('data', $result);

        return ['submission' => $result['submission'], 'data' => $result['data']];
    }

    public function testBlankMessageFallsBackToGlobalSettings(): void
    {
        $this->requireCraft();

        $globalMessage = Plugin::getInstance()->getSettings()->submitMessage;
        $form = $this->createForm('Plain', 'resolve_plain');
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', false);

        $sub = $this->submit($form, ['field_' . $fieldId => 'Ada']);
        $resolved = $this->service()->resolvePostSubmit($form, $sub['submission'], $sub['data']);

        $this->assertSame($globalMessage, $resolved['message']);
        $this->assertNull($resolved['redirectUrl']);
    }

    public function testPerFormMessageOverridesGlobalAndInterpolates(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Newsletter', 'resolve_msg');
        $form->submitMessage = 'Thanks {firstName}! (#{submissionId})';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField($form->id, 'text', 'firstName', 'First Name', false);

        $reloaded = Form::find()->id($form->id)->one();
        $sub = $this->submit($reloaded, ['field_' . $fieldId => 'Ada']);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $sub['submission'], $sub['data']);

        $this->assertSame('Thanks Ada! (#' . $sub['submission']->id . ')', $resolved['message']);
    }

    public function testUnknownAndMissingPlaceholdersResolveToEmpty(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Sparse', 'resolve_unknown');
        $form->submitMessage = 'Hi {nope}{firstName}';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField($form->id, 'text', 'firstName', 'First Name', false);

        $reloaded = Form::find()->id($form->id)->one();
        // Submit with the field left empty → handle resolves to empty string.
        $sub = $this->submit($reloaded, ['field_' . $fieldId => '']);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $sub['submission'], $sub['data']);

        $this->assertSame('Hi ', $resolved['message']);
    }

    public function testUrlActionInterpolatesAndEncodesValues(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Contact', 'resolve_url');
        $form->postSubmitAction = 'url';
        $form->redirectUrl = '/thanks?e={email}';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField($form->id, 'email', 'email', 'Email', false);

        $reloaded = Form::find()->id($form->id)->one();
        $sub = $this->submit($reloaded, ['field_' . $fieldId => 'ada@example.com']);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $sub['submission'], $sub['data']);

        $this->assertSame('/thanks?e=ada%40example.com', $resolved['redirectUrl']);
    }

    public function testUnsafeRedirectUrlIsNullAfterInterpolation(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Contact', 'resolve_unsafe');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);

        $reloaded = Form::find()->id($form->id)->one();
        $reloaded->postSubmitAction = 'url';
        // Bypass save-time validation: simulate a legacy/unsafe stored template.
        $reloaded->redirectUrl = '//evil.example/phish';
        $sub = $this->submit($reloaded, ['field_' . $fieldId => 'x']);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $sub['submission'], $sub['data']);

        $this->assertNull($resolved['redirectUrl']);
    }

    public function testArrayValueJoinsForPlaceholder(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Topics', 'resolve_array');
        $form->submitMessage = 'Picked: {topics}';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField($form->id, 'checkbox', 'topics', 'Topics', false, [
            'options' => [
                ['label' => 'A', 'value' => 'a'],
                ['label' => 'B', 'value' => 'b'],
            ],
        ]);

        $reloaded = Form::find()->id($form->id)->one();
        $sub = $this->submit($reloaded, ['field_' . $fieldId => ['a', 'b']]);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $sub['submission'], $sub['data']);

        $this->assertSame('Picked: a, b', $resolved['message']);
    }

    public function testMessageActionAlwaysHasNullRedirect(): void
    {
        $this->requireCraft();

        $form = $this->createForm('MsgOnly', 'resolve_msgonly');
        // Even with a stray redirectUrl set, the `message` action never redirects.
        $form->redirectUrl = '/ignored';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);

        $reloaded = Form::find()->id($form->id)->one();
        $sub = $this->submit($reloaded, ['field_' . $fieldId => 'x']);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $sub['submission'], $sub['data']);

        $this->assertNull($resolved['redirectUrl']);
    }

    public function testEntryActionWithMissingEntryYieldsNullRedirect(): void
    {
        $this->requireCraft();

        $form = $this->createForm('EntryRedirect', 'resolve_entry');
        $form->postSubmitAction = 'entry';
        // A non-existent entry id → null redirect, inline message shown instead.
        $form->redirectEntryId = 999999;
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);

        $reloaded = Form::find()->id($form->id)->one();
        $sub = $this->submit($reloaded, ['field_' . $fieldId => 'x']);
        $resolved = $this->service()->resolvePostSubmit($reloaded, $sub['submission'], $sub['data']);

        $this->assertNull($resolved['redirectUrl']);
    }
}
