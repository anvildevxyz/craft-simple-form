<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\test\TestMailer;
use fabianhaef\simpleform\services\EmailService;
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
        $reloaded = \fabianhaef\simpleform\elements\Form::find()->id($form->id)->one();
        $this->assertSame('owner@example.com', $reloaded->emailTo);

        $sent = $this->captureSentMessages(function () use ($reloaded, $fieldId): void {
            $submission = new \fabianhaef\simpleform\elements\Submission();
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

    public function testNoEmailWhenRecipientNotConfigured(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Silent', 'silentForm', 'Silent');
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', false);

        $sent = $this->captureSentMessages(function () use ($form, $fieldId): void {
            $submission = new \fabianhaef\simpleform\elements\Submission();
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
            $mailer->callback = function (MessageInterface $message) use (&$collected, $original): void {
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
