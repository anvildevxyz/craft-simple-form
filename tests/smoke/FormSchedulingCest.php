<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;

/**
 * Form Scheduling + Quota Smoke Tests
 *
 * Exercises the public-facing behaviour of open/close dates and the submission
 * cap: the rendered form is replaced by the closed message, and a crafted
 * submission to the shared service path is rejected server-side even when the
 * HTML form was never rendered.
 */
class FormSchedulingCest
{
    private int $siteId;
    private string $formHandle;
    private int $formId;
    private int $fieldId;

    public function _before(FunctionalTester $I): void
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $form = new Form();
        $form->siteId = $this->siteId;
        $form->name = 'scheduling-test-' . uniqid();
        $form->handle = $this->formHandle = 'schedTest' . uniqid();
        $form->title = 'Scheduling Test';
        $form->emailTo = 'admin@test.com';
        Craft::$app->getElements()->saveElement($form);
        $this->formId = (int) $form->id;

        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'note',
            'required' => false,
            'config' => '[]',
            'sortOrder' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();
        $this->fieldId = (int) $db->getLastInsertID();
        $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
            'fieldId' => $this->fieldId,
            'siteId' => $this->siteId,
            'label' => 'Note',
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();
    }

    public function testNotYetOpenShowsClosedMessageInsteadOfForm(FunctionalTester $I): void
    {
        $form = Form::find()->id($this->formId)->one();
        $form->openDate = new \DateTime('+2 days');
        $form->closedMessage = 'Registration opens soon.';
        Craft::$app->getElements()->saveElement($form);

        $html = Craft::$app->getView()->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('simple-form--closed', $html, 'Closed wrapper should render');
        $I->assertStringContainsString('Registration opens soon.', $html, 'Closed message should show');
        $I->assertStringNotContainsString('<form', $html, 'No form element when not yet open');
    }

    public function testClosedFormDefaultsMessageWhenBlank(FunctionalTester $I): void
    {
        $form = Form::find()->id($this->formId)->one();
        $form->closeDate = new \DateTime('-1 day');
        Craft::$app->getElements()->saveElement($form);

        $html = Craft::$app->getView()->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('simple-form--closed', $html);
        $I->assertStringContainsString('no longer accepting submissions', $html, 'Falls back to default closed copy');
    }

    public function testServerRejectsSubmissionWhenClosed(FunctionalTester $I): void
    {
        $form = Form::find()->id($this->formId)->one();
        $form->closeDate = new \DateTime('-1 day');
        Craft::$app->getElements()->saveElement($form);

        $before = Submission::find()->formId($this->formId)->siteId('*')->status(null)->count();

        $result = Plugin::getInstance()->getSubmissionService()->submit(
            $form,
            [$this->fieldId => 'crafted'],
            ['skipCaptcha' => true],
        );

        $I->assertNull($result['submission'], 'Closed form must not persist a submission');
        $I->assertArrayHasKey('form', (array) $result['errors']);

        $after = Submission::find()->formId($this->formId)->siteId('*')->status(null)->count();
        $I->assertSame($before, $after, 'No submission row stored for a closed form');
    }

    public function testQuotaClosesFormAfterLimitReached(FunctionalTester $I): void
    {
        $form = Form::find()->id($this->formId)->one();
        $form->submissionLimit = 1;
        Craft::$app->getElements()->saveElement($form);

        $service = Plugin::getInstance()->getSubmissionService();

        // First submission fills the quota.
        $first = $service->submit($form, [$this->fieldId => 'one'], ['skipCaptcha' => true]);
        $I->assertInstanceOf(Submission::class, $first['submission']);

        // Reload so the count cache reflects the new row, then the next is rejected.
        $reloaded = Form::find()->id($this->formId)->one();
        $I->assertFalse($reloaded->isAcceptingSubmissions());

        $second = $service->submit($reloaded, [$this->fieldId => 'two'], ['skipCaptcha' => true]);
        $I->assertNull($second['submission'], 'Over-quota submission rejected');
        $I->assertArrayHasKey('form', (array) $second['errors']);

        // The render also shows the closed message.
        $html = Craft::$app->getView()->renderString('{{ simpleForm("' . $this->formHandle . '") }}');
        $I->assertStringContainsString('simple-form--closed', $html);
    }
}
