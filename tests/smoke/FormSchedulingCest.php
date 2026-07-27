<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use Craft;
use SmokeTester;

/**
 * Form Scheduling + Quota Smoke Tests (functional).
 *
 * Exercises the public-facing behaviour of open/close dates and the submission
 * cap: the rendered form is replaced by the closed message (via the real
 * {@see \anvildev\simpleform\services\FormRenderService}), and a crafted
 * submission to the shared service path is rejected server-side even when the
 * HTML form was never rendered. Forms and fields are seeded through the data
 * layer (see {@see BaseSmokeCest}).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class FormSchedulingCest extends BaseSmokeCest
{
    // =========================================================================
    // PRIVATE PROPERTIES
    // =========================================================================

    private string $formHandle;

    private int $formId;

    private int $fieldId;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $form = $this->createForm('Scheduling Test', 'schedTest' . uniqid(), 'admin@test.com');
        $this->formId = (int)$form->id;
        $this->formHandle = $form->handle;
        $this->fieldId = $this->createField($this->formId, 'text', 'note', 'Note');
    }

    public function testNotYetOpenShowsClosedMessageInsteadOfForm(SmokeTester $I): void
    {
        $form = $this->form();
        $form->openDate = new \DateTime('+2 days');
        $form->closedMessage = 'Registration opens soon.';
        Craft::$app->getElements()->saveElement($form);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('simple-form--closed', $html, 'Closed wrapper should render');
        $I->assertStringContainsString('Registration opens soon.', $html, 'Closed message should show');
        $I->assertStringNotContainsString('<form', $html, 'No form element when not yet open');
    }

    public function testClosedFormDefaultsMessageWhenBlank(SmokeTester $I): void
    {
        $form = $this->form();
        $form->closeDate = new \DateTime('-1 day');
        Craft::$app->getElements()->saveElement($form);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('simple-form--closed', $html);
        $I->assertStringContainsString('no longer accepting submissions', $html, 'Falls back to default closed copy');
    }

    public function testServerRejectsSubmissionWhenClosed(SmokeTester $I): void
    {
        $form = $this->form();
        $form->closeDate = new \DateTime('-1 day');
        Craft::$app->getElements()->saveElement($form);

        $before = Submission::find()->formId($this->formId)->siteId('*')->status(null)->count();

        $result = $this->service()->submit(
            $form,
            [$this->fieldId => 'crafted'],
            ['skipCaptcha' => true],
        );

        $I->assertNull($result['submission'], 'Closed form must not persist a submission');
        $I->assertArrayHasKey('form', (array)$result['errors']);

        $after = Submission::find()->formId($this->formId)->siteId('*')->status(null)->count();
        $I->assertSame($before, $after, 'No submission row stored for a closed form');
    }

    public function testQuotaClosesFormAfterLimitReached(SmokeTester $I): void
    {
        $form = $this->form();
        $form->submissionLimit = 1;
        Craft::$app->getElements()->saveElement($form);

        $service = $this->service();

        // First submission fills the quota.
        $first = $service->submit($form, [$this->fieldId => 'one'], ['skipCaptcha' => true]);
        $I->assertInstanceOf(Submission::class, $first['submission']);

        // Reload so the count cache reflects the new row, then the next is rejected.
        $reloaded = $this->form();
        $I->assertFalse($reloaded->isAcceptingSubmissions());

        $second = $service->submit($reloaded, [$this->fieldId => 'two'], ['skipCaptcha' => true]);
        $I->assertNull($second['submission'], 'Over-quota submission rejected');
        $I->assertArrayHasKey('form', (array)$second['errors']);

        // The render also shows the closed message.
        $html = $this->renderForm($this->formHandle);
        $I->assertStringContainsString('simple-form--closed', $html);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * The form element reloaded fresh from the current site.
     */
    private function form(): Form
    {
        return Form::find()->id($this->formId)->siteId(Craft::$app->getSites()->getPrimarySite()->id)->one();
    }
}
