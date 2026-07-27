<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;
use craft\db\Query;

/**
 * Scheduling window + submission-quota enforcement.
 *
 * Covers both the Form-level availability helpers and the server-side guard in
 * SubmissionService::submit() — the guard is the differentiator, since it
 * rejects a crafted POST (the "HTML bypass") that never went through the
 * rendered form.
 *
 * @group requires-craft
 */
class FormSchedulingTest extends SimpleFormTestCase
{
    public function testOpenEndedFormAcceptsSubmissions(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Open', 'schedOpenForm', 'Open');
        $this->assertTrue($form->isAcceptingSubmissions());
        $this->assertNull($form->getClosedReason());
    }

    public function testBeforeOpenDateIsRejected(): void
    {
        $this->requireCraft();

        $form = $this->createForm('NotYet', 'schedNotYetForm', 'NotYet');
        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);
        $this->saveScheduling($form, openDate: new \DateTime('+2 days'));

        $this->assertFalse($form->isAcceptingSubmissions());
        $this->assertSame(Form::CLOSED_NOT_YET, $form->getClosedReason());

        $this->assertSubmitRejectedWithoutRow($form, $fieldId);
    }

    public function testAfterCloseDateIsRejected(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Ended', 'schedEndedForm', 'Ended');
        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);
        $this->saveScheduling($form, closeDate: new \DateTime('-1 day'));

        $this->assertFalse($form->isAcceptingSubmissions());
        $this->assertSame(Form::CLOSED_ENDED, $form->getClosedReason());

        $this->assertSubmitRejectedWithoutRow($form, $fieldId);
    }

    public function testWithinWindowIsAccepted(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Window', 'schedWindowForm', 'Window');
        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);
        $this->saveScheduling($form, openDate: new \DateTime('-1 day'), closeDate: new \DateTime('+1 day'));

        $this->assertTrue($form->isAcceptingSubmissions());

        $result = $this->submit($form, $fieldId, 'inside');
        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);
    }

    public function testAtQuotaIsRejected(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Quota', 'schedQuotaForm', 'Quota');
        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);
        $this->saveScheduling($form, submissionLimit: 2);

        // First two submissions fill the quota.
        $this->assertInstanceOf(Submission::class, $this->submit($form, $fieldId, 'one')['submission']);
        $this->assertInstanceOf(Submission::class, $this->submit($form, $fieldId, 'two')['submission']);

        // Reload so the request-scoped count cache reflects the new rows.
        $reloaded = Form::find()->id($form->id)->one();
        $this->assertSame(2, $reloaded->getSubmissionCount());
        $this->assertFalse($reloaded->isAcceptingSubmissions());
        $this->assertSame(Form::CLOSED_FULL, $reloaded->getClosedReason());

        $this->assertSubmitRejectedWithoutRow($reloaded, $fieldId);
    }

    public function testSpamRowsDoNotCountTowardQuota(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SpamQuota', 'schedSpamQuotaForm', 'SpamQuota');
        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);

        // One real submission + one spam row inserted directly.
        $this->submit($form, $fieldId, 'real');

        $spam = new Submission();
        $spam->formId = (int) $form->id;
        $spam->siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $spam->data = [];
        $spam->readStatus = SubmissionStatus::SPAM;
        $spam->spamReason = 'akismet';
        Craft::$app->getElements()->saveElement($spam);

        $reloaded = Form::find()->id($form->id)->one();
        // Spam excluded → count is 1, not 2.
        $this->assertSame(1, $reloaded->getSubmissionCount());
    }

    public function testServerGuardRejectsCraftedPostEvenWhenHtmlBypassed(): void
    {
        $this->requireCraft();

        // Closed form: a crafted POST straight to submit() (no rendered form)
        // must still be rejected with a `form`-keyed closed message and persist
        // no row.
        $form = $this->createForm('Bypass', 'schedBypassForm', 'Bypass');
        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);
        $this->saveScheduling($form, closeDate: new \DateTime('-1 day'));

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $result = $this->service()->submit($form, [$fieldId => 'crafted'], ['skipCaptcha' => true]);

        $this->assertNull($result['submission']);
        $this->assertNotNull($result['errors']);
        $this->assertArrayHasKey('form', $result['errors']);

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after, 'A closed form must persist no submission row');
    }

    public function testHoneypotDropsBeforeAvailabilityCheck(): void
    {
        $this->requireCraft();

        // A closed form + a filled honeypot: the honeypot must win (silent drop,
        // no errors) so a bot gets no signal about the closed state.
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->enableHoneypot;
        $settings->enableHoneypot = true;

        try {
            $form = $this->createForm('Honey', 'schedHoneyForm', 'Honey');
            $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);
            $this->saveScheduling($form, closeDate: new \DateTime('-1 day'));

            $result = $this->service()->submit($form, [$fieldId => 'x'], [
                'honeypot' => 'i-am-a-bot',
                'skipCaptcha' => true,
            ]);

            $this->assertNull($result['submission']);
            $this->assertNull($result['errors'], 'Honeypot drop must surface no error, even on a closed form');
        } finally {
            $settings->enableHoneypot = $original;
        }
    }

    public function testCloseBeforeOpenFailsValidation(): void
    {
        $this->requireCraft();

        $form = $this->createForm('BadWindow', 'schedBadWindowForm', 'BadWindow');
        $form->openDate = new \DateTime('+2 days');
        $form->closeDate = new \DateTime('+1 day');

        $this->assertFalse($form->validate(['closeDate']));
        $this->assertArrayHasKey('closeDate', $form->getErrors());
    }

    /**
     * Persist scheduling settings onto an already-saved form, then re-fetch so
     * the hydrated element carries them.
     */
    private function saveScheduling(
        Form $form,
        ?\DateTime $openDate = null,
        ?\DateTime $closeDate = null,
        ?int $submissionLimit = null,
    ): void {
        $form->openDate = $openDate;
        $form->closeDate = $closeDate;
        $form->submissionLimit = $submissionLimit;
        $this->assertTrue(Craft::$app->getElements()->saveElement($form), implode(', ', $form->getFirstErrors()));
    }

    /**
     * @return array{submission: Submission|null, errors: array<string, mixed>|null}
     */
    private function submit(Form $form, int $fieldId, string $value): array
    {
        return $this->service()->submit($form, [$fieldId => $value], ['skipCaptcha' => true]);
    }

    private function assertSubmitRejectedWithoutRow(Form $form, int $fieldId): void
    {
        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $result = $this->service()->submit($form, [$fieldId => 'x'], ['skipCaptcha' => true]);

        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('form', (array) $result['errors']);

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after);
    }

    private function service(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }
}
