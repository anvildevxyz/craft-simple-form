<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\services\EmailService;
use Craft;
use craft\test\TestMailer;
use yii\mail\MessageInterface;

/**
 * Verifies that submitting to a form configured with a notification recipient
 * actually composes and sends an email. The Craft test framework swaps in a
 * TestMailer whose send is a no-op callback, so we wrap that callback to capture
 * the outgoing message and assert on its recipient/subject/body.
 *
 * @group requires-craft
 */
class EmailNotificationTest extends SimpleFormTestCase
{
    public function testSubmissionEmailIsSentToConfiguredRecipient(): void
    {
        $this->requireCraft();

        $form = $this->createForm(
            'Notify',
            'notifyForm',
            'Notify',
            emailTo: 'owner@example.com',
            emailSubject: 'You got a submission',
        );
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        // Reload through the element query so emailTo/emailSubject come from the DB.
        $reloaded = \anvildev\simpleform\elements\Form::find()->id($form->id)->one();
        $this->assertSame('owner@example.com', $reloaded->emailTo);

        $sent = $this->captureSentMessages(function() use ($reloaded, $fieldId): void {
            $submission = new \anvildev\simpleform\elements\Submission();
            $submission->formId = $reloaded->id;
            $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $submission->readStatus = 'new';
            $data = [
                'field_' . $fieldId => [
                    'label' => 'Full Name',
                    'type' => 'text',
                    'value' => 'Grace Hopper',
                ],
            ];
            $submission->data = $data;
            Craft::$app->getElements()->saveElement($submission);

            $result = (new EmailService())->sendSubmissionEmail($reloaded, $submission, $data);
            $this->assertTrue($result, 'sendSubmissionEmail should return true');
        });

        $this->assertCount(1, $sent, 'Exactly one notification email should be sent');

        /** @var \craft\mail\Message $message */
        $message = $sent[0];
        $this->assertArrayHasKey('owner@example.com', $message->getTo());
        $this->assertSame('You got a submission', $message->getSubject());
        $this->assertStringContainsString('Grace Hopper', $this->messageBody($message));
    }

    public function testPerSiteEmailBodyTemplateIsRenderedAndReplacesDefault(): void
    {
        $this->requireCraft();

        // A per-site body template — the editor authors this in the site's own
        // language; it references the submission/form so we can prove it renders.
        $form = $this->createForm('Localized', 'localizedForm', 'Localized', emailTo: 'owner@example.com');
        // The editor authors a per-site body template (in the site's own language).
        $form->emailBody = 'CUSTOM-BODY token={{ submission.id }} for {{ form.handle }}';
        Craft::$app->getElements()->saveElement($form);
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        // emailBody must come back from the per-site row, like emailSubject does.
        $reloaded = \anvildev\simpleform\elements\Form::find()->id($form->id)->one();
        $this->assertSame(
            'CUSTOM-BODY token={{ submission.id }} for {{ form.handle }}',
            $reloaded->emailBody,
            'emailBody should load per-site from the DB',
        );

        $sent = $this->captureSentMessages(function() use ($reloaded, $fieldId): void {
            $submission = new \anvildev\simpleform\elements\Submission();
            $submission->formId = $reloaded->id;
            $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $submission->readStatus = 'new';
            $data = ['field_' . $fieldId => ['label' => 'Full Name', 'type' => 'text', 'value' => 'Ada']];
            $submission->data = $data;
            Craft::$app->getElements()->saveElement($submission);

            (new EmailService())->sendSubmissionEmail($reloaded, $submission, $data);
        });

        $this->assertCount(1, $sent);
        $body = $this->messageBody($sent[0]);
        // The per-site template is rendered as Twig (id + handle interpolated)...
        $this->assertStringContainsString('CUSTOM-BODY token=', $body);
        $this->assertStringContainsString('for localizedForm', $body);
        // ...and replaces the default generated template entirely.
        $this->assertStringNotContainsString('New Form Submission', $body);
    }

    /**
     * F2 / CWE-94 (SSTI): a notification body authored by a non-admin must not
     * be able to reach craft.app / the database through Twig. The sandbox makes
     * the render throw, so the service falls back to the default template and
     * the secret never appears in the email.
     */
    public function testMaliciousTemplateCannotReachApplicationAndFallsBackToDefault(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Evil', 'evilBodyForm', 'Evil', emailTo: 'owner@example.com');
        // Reaching craft.app at all is the SSTI vector; the sandbox must block it.
        $form->emailBody = 'LEAK={{ craft.app.config.general.securityKey }}';
        Craft::$app->getElements()->saveElement($form);
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);
        $reloaded = \anvildev\simpleform\elements\Form::find()->id($form->id)->one();

        $sent = $this->captureSentMessages(function() use ($reloaded, $fieldId): void {
            $submission = new \anvildev\simpleform\elements\Submission();
            $submission->formId = $reloaded->id;
            $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $submission->readStatus = 'new';
            $data = ['field_' . $fieldId => ['label' => 'Full Name', 'type' => 'text', 'value' => 'Ada']];
            $submission->data = $data;
            Craft::$app->getElements()->saveElement($submission);

            (new EmailService())->sendSubmissionEmail($reloaded, $submission, $data);
        });

        $this->assertCount(1, $sent);
        $body = $this->messageBody($sent[0]);
        // The sandbox throws on craft.app access, so the whole custom body is
        // discarded — its literal "LEAK=" prefix must not survive...
        $this->assertStringNotContainsString('LEAK=', $body, 'sandboxed render must be blocked, not partially emitted');
        // ...and the service falls back to the default template.
        $this->assertStringContainsString('New Form Submission', $body);
    }

    public function testBlankEmailBodyFallsBackToDefaultTemplate(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Default', 'defaultBodyForm', 'Default', emailTo: 'owner@example.com');
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);
        $reloaded = \anvildev\simpleform\elements\Form::find()->id($form->id)->one();

        $sent = $this->captureSentMessages(function() use ($reloaded, $fieldId): void {
            $submission = new \anvildev\simpleform\elements\Submission();
            $submission->formId = $reloaded->id;
            $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $submission->readStatus = 'new';
            $data = ['field_' . $fieldId => ['label' => 'Full Name', 'type' => 'text', 'value' => 'Ada']];
            $submission->data = $data;
            Craft::$app->getElements()->saveElement($submission);

            (new EmailService())->sendSubmissionEmail($reloaded, $submission, $data);
        });

        $this->assertCount(1, $sent);
        // No per-site body → the default template is used, never blank.
        $this->assertStringContainsString('New Form Submission', $this->messageBody($sent[0]));
    }

    public function testNoEmailWhenRecipientNotConfigured(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Silent', 'silentForm', 'Silent');
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', false);

        $sent = $this->captureSentMessages(function() use ($form, $fieldId): void {
            $submission = new \anvildev\simpleform\elements\Submission();
            $submission->formId = $form->id;
            $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $submission->readStatus = 'new';
            $data = ['field_' . $fieldId => ['label' => 'Full Name', 'type' => 'text', 'value' => 'x']];
            $submission->data = $data;
            Craft::$app->getElements()->saveElement($submission);

            $result = (new EmailService())->sendSubmissionEmail($form, $submission, $data);
            $this->assertFalse($result, 'sendSubmissionEmail should short-circuit without a recipient');
        });

        $this->assertCount(0, $sent);
    }

    /**
     * Run $work with the test mailer's callback wrapped to collect messages.
     *
     * @return list<MessageInterface>
     */
    private function captureSentMessages(callable $work): array
    {
        $mailer = Craft::$app->getMailer();
        $collected = [];

        if ($mailer instanceof TestMailer) {
            $original = $mailer->callback;
            $mailer->callback = function(MessageInterface $message) use (&$collected, $original): void {
                $collected[] = $message;
                if (is_callable($original)) {
                    $original($message);
                }
            };
            try {
                $work();
            } finally {
                $mailer->callback = $original;
            }
        } else {
            // Fall back to file transport's behaviour if a non-test mailer is active.
            $work();
        }

        return $collected;
    }

    private function messageBody(MessageInterface $message): string
    {
        if (method_exists($message, 'getSwiftMessage')) {
            return (string) $message;
        }
        return (string) $message;
    }
}
