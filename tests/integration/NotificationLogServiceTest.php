<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\EmailService;
use anvildev\simpleform\services\NotificationLogService;
use Craft;
use craft\db\Query;
use craft\test\TestMailer;
use yii\mail\MessageInterface;

/**
 * Notification send log: records every outbound email and exposes it for CP review.
 *
 * @group requires-craft
 */
class NotificationLogServiceTest extends SimpleFormTestCase
{
    public function testSendSubmissionEmailWritesLogRow(): void
    {
        $this->requireCraft();

        $form = $this->createForm(
            'Notify Log',
            'notifyLogForm',
            'Notify Log',
            emailTo: 'owner@example.com',
            emailSubject: 'Logged subject',
        );
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);
        $reloaded = \anvildev\simpleform\elements\Form::find()->id($form->id)->one();

        $before = (int) (new Query())->from('{{%simpleform_notification_logs}}')->count();

        $this->captureSentMessages(function() use ($reloaded, $fieldId): void {
            $submission = new \anvildev\simpleform\elements\Submission();
            $submission->formId = $reloaded->id;
            $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $submission->readStatus = 'new';
            $data = [
                'field_' . $fieldId => [
                    'label' => 'Full Name',
                    'type' => 'text',
                    'value' => 'Ada Lovelace',
                ],
            ];
            $submission->data = $data;
            Craft::$app->getElements()->saveElement($submission);

            (new EmailService())->sendSubmissionEmail($reloaded, $submission, $data);
        });

        $after = (int) (new Query())->from('{{%simpleform_notification_logs}}')->count();
        $this->assertSame($before + 1, $after, 'One log row should be written per send');

        $entries = Plugin::getInstance()->getNotificationLog()->recent(10, (int) $form->id);
        $this->assertNotEmpty($entries);
        $latest = $entries[0];
        $this->assertSame(NotificationLogService::STATUS_SUCCESS, $latest['status']);
        $this->assertSame('Logged subject', $latest['subject']);
        $this->assertContains('owner@example.com', $latest['recipients']);
        $this->assertSame('Legacy email', $latest['notificationName']);
    }

    /**
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
            $work();
        }

        return $collected;
    }
}
