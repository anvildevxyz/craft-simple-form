<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\Plugin;
use craft\db\Query;

/**
 * #140 — Per-form duplicate-submission prevention: the email and content dedupe
 * keys, the lookback window, and the per-form scope.
 *
 * @group requires-craft
 */
class DuplicatePreventionTest extends SimpleFormTestCase
{
    private function dupForm(string $handle, string $key, int $window = 0): Form
    {
        $form = $this->createForm('Dup ' . $handle, $handle);
        $form->preventDuplicates = true;
        $form->duplicateKey = $key;
        $form->duplicateWindowMinutes = $window;
        $this->assertTrue(\Craft::$app->getElements()->saveElement($form));
        return $form;
    }

    public function testEmailKeyBlocksSecondSubmission(): void
    {
        $this->requireCraft();
        $form = $this->dupForm('dup_email', Form::DUPLICATE_KEY_EMAIL);
        $emailField = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $service = Plugin::getInstance()->getSubmissionService();

        $first = $service->submit($form, ['field_' . $emailField => 'bob@example.com'], ['skipCaptcha' => true]);
        $this->assertSame(SubmissionStatus::NEW, $first['submission']->readStatus);

        $second = $service->submit($form, ['field_' . $emailField => 'bob@example.com'], ['skipCaptcha' => true]);
        $this->assertSame(SubmissionStatus::SPAM, $second['submission']->readStatus);
        $this->assertSame('duplicate', $second['submission']->spamReason);

        // A different email is allowed.
        $third = $service->submit($form, ['field_' . $emailField => 'alice@example.com'], ['skipCaptcha' => true]);
        $this->assertSame(SubmissionStatus::NEW, $third['submission']->readStatus);
    }

    public function testContentKeyBlocksIdenticalPayload(): void
    {
        $this->requireCraft();
        $form = $this->dupForm('dup_content', Form::DUPLICATE_KEY_CONTENT);
        $fieldId = $this->createField((int) $form->id, 'text', 'message', 'Message');
        $service = Plugin::getInstance()->getSubmissionService();

        $first = $service->submit($form, ['field_' . $fieldId => 'hello there'], ['skipCaptcha' => true]);
        $this->assertSame(SubmissionStatus::NEW, $first['submission']->readStatus);

        $dup = $service->submit($form, ['field_' . $fieldId => 'hello there'], ['skipCaptcha' => true]);
        $this->assertSame(SubmissionStatus::SPAM, $dup['submission']->readStatus);

        $different = $service->submit($form, ['field_' . $fieldId => 'something else'], ['skipCaptcha' => true]);
        $this->assertSame(SubmissionStatus::NEW, $different['submission']->readStatus);
    }

    public function testWindowAllowsSubmissionOutsideRange(): void
    {
        $this->requireCraft();
        // 10-minute window. Manually backdate the first submission past it.
        $form = $this->dupForm('dup_window', Form::DUPLICATE_KEY_EMAIL, 10);
        $emailField = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $service = Plugin::getInstance()->getSubmissionService();

        $first = $service->submit($form, ['field_' . $emailField => 'win@example.com'], ['skipCaptcha' => true]);
        $this->assertSame(SubmissionStatus::NEW, $first['submission']->readStatus);

        // Push the first submission's dateCreated outside the window.
        \Craft::$app->getDb()->createCommand()->update(
            '{{%elements}}',
            ['dateCreated' => date('Y-m-d H:i:s', time() - 3600)],
            ['id' => $first['submission']->id],
        )->execute();

        $second = $service->submit($form, ['field_' . $emailField => 'win@example.com'], ['skipCaptcha' => true]);
        $this->assertSame(SubmissionStatus::NEW, $second['submission']->readStatus, 'outside the window is allowed');
    }

    public function testDisabledFormDoesNotDedupe(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Dup Off', 'dup_off');
        $emailField = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $service = Plugin::getInstance()->getSubmissionService();

        $service->submit($form, ['field_' . $emailField => 'x@example.com'], ['skipCaptcha' => true]);
        $second = $service->submit($form, ['field_' . $emailField => 'x@example.com'], ['skipCaptcha' => true]);

        $this->assertSame(SubmissionStatus::NEW, $second['submission']->readStatus);
        $count = (new Query())->from('{{%simpleform_submissions}}')->where(['formId' => $form->id])->count();
        $this->assertSame(2, (int) $count);
    }

    public function testDuplicateSettingsRoundTripThroughTheQuery(): void
    {
        $this->requireCraft();
        $form = $this->dupForm('dup_roundtrip', Form::DUPLICATE_KEY_CONTENT, 15);

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertTrue($reloaded->preventDuplicates);
        $this->assertSame(Form::DUPLICATE_KEY_CONTENT, $reloaded->duplicateKey);
        $this->assertSame(15, $reloaded->duplicateWindowMinutes);
    }

    public function testDuplicateScopeIsPerForm(): void
    {
        $this->requireCraft();
        $formA = $this->dupForm('dup_scope_a', Form::DUPLICATE_KEY_EMAIL);
        $formB = $this->dupForm('dup_scope_b', Form::DUPLICATE_KEY_EMAIL);
        $fieldA = $this->createField((int) $formA->id, 'email', 'email', 'Email');
        $fieldB = $this->createField((int) $formB->id, 'email', 'email', 'Email');
        $service = Plugin::getInstance()->getSubmissionService();

        $service->submit($formA, ['field_' . $fieldA => 'same@example.com'], ['skipCaptcha' => true]);
        // Same email on a *different* form is not a duplicate.
        $onB = $service->submit($formB, ['field_' . $fieldB => 'same@example.com'], ['skipCaptcha' => true]);

        $this->assertSame(SubmissionStatus::NEW, $onB['submission']->readStatus);
        $this->assertInstanceOf(Submission::class, $onB['submission']);
    }
}
