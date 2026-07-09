<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\models\NotificationModel;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\NotificationLogService;
use Craft;
use SmokeTester;

/**
 * Notification email smoke tests (functional).
 *
 * Exercises the full submit → queue/send notification path for both legacy
 * form email columns and per-form notification rows.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class NotificationsSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testLegacyEmailSentOnSubmit(SmokeTester $I): void
    {
        $form = $this->createForm('Notify', 'notify' . uniqid(), 'owner@example.com');
        $form->emailSubject = 'You got a submission';
        Craft::$app->getElements()->saveElement($form);
        $fieldId = $this->createField((int) $form->id, 'text', 'fullName', 'Full Name', true);
        $reloaded = $this->reloadForm($form);

        $result = null;
        $sent = $this->captureSentMessages(function() use ($reloaded, $fieldId, &$result): void {
            $result = $this->withSyncSideEffects(function() use ($reloaded, $fieldId) {
                return $this->submitDirect($reloaded, ['field_' . $fieldId => 'Grace Hopper']);
            });
        });

        $I->assertNull($result['errors']);

        $I->assertCount(1, $sent);
        $I->assertArrayHasKey('owner@example.com', $sent[0]->getTo());
        $I->assertSame('You got a submission', $sent[0]->getSubject());
        $I->assertStringContainsString('Grace Hopper', $this->messageBody($sent[0]));
    }

    public function testPerFormNotificationSentOnSubmit(SmokeTester $I): void
    {
        $form = $this->createForm('Ops Notify', 'opsNotify' . uniqid());
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $notification = new NotificationModel();
        $notification->formId = (int) $form->id;
        $notification->name = 'Ops alert';
        $notification->enabled = true;
        $notification->recipientType = NotificationModel::RECIPIENT_FIXED;
        $notification->recipient = 'ops@example.test';
        $notification->subject = 'New lead';
        $notification->body = 'Email: {{ email }}';
        Plugin::getInstance()->getNotifications()->save($notification);

        $sent = $this->captureSentMessages(function() use ($form, $emailId): void {
            $this->withSyncSideEffects(function() use ($form, $emailId): void {
                $this->submitDirect($form, ['field_' . $emailId => 'lead@example.com']);
            });
        });

        $I->assertCount(1, $sent);
        $I->assertArrayHasKey('ops@example.test', $sent[0]->getTo());
        $I->assertSame('New lead', $sent[0]->getSubject());
    }

    public function testNoEmailWhenRecipientNotConfigured(SmokeTester $I): void
    {
        $form = $this->createForm('Silent', 'silent' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $result = null;
        $sent = $this->captureSentMessages(function() use ($form, $fieldId, &$result): void {
            $result = $this->withSyncSideEffects(function() use ($form, $fieldId) {
                return $this->submitDirect($form, ['field_' . $fieldId => 'Ada']);
            });
        });

        $I->assertInstanceOf(Submission::class, $result['submission']);
        $I->assertCount(0, $sent);
    }

    public function testManualResendReDeliversAndLogsReference(SmokeTester $I): void
    {
        $form = $this->createForm('Resend Notify', 'resendNotify' . uniqid());
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $notification = new NotificationModel();
        $notification->formId = (int) $form->id;
        $notification->name = 'Resendable alert';
        $notification->enabled = true;
        $notification->recipientType = NotificationModel::RECIPIENT_FIXED;
        $notification->recipient = 'ops@example.test';
        $notification->subject = 'New lead';
        $notification->body = 'Email: {{ email }}';
        Plugin::getInstance()->getNotifications()->save($notification);

        // Original submit → one delivery, one log row.
        $result = null;
        $firstSend = $this->captureSentMessages(function() use ($form, $emailId, &$result): void {
            $result = $this->withSyncSideEffects(function() use ($form, $emailId) {
                return $this->submitDirect($form, ['field_' . $emailId => 'lead@example.com']);
            });
        });

        $I->assertCount(1, $firstSend);
        $submission = $result['submission'];
        $I->assertInstanceOf(Submission::class, $submission);

        $log = Plugin::getInstance()->getNotificationLog();
        $original = $log->getForSubmission((int) $submission->id);
        $I->assertCount(1, $original);
        $originalId = (int) $original[0]['id'];

        // Manual resend → a second delivery.
        $resendMessages = $this->captureSentMessages(function() use ($originalId): void {
            $this->withSyncSideEffects(function() use ($originalId): void {
                $resent = Plugin::getInstance()->getEmailService()->resendFromLog($originalId);
                if (!$resent) {
                    throw new \RuntimeException('resendFromLog returned false');
                }
            });
        });

        $I->assertCount(1, $resendMessages);
        $I->assertArrayHasKey('ops@example.test', $resendMessages[0]->getTo());

        // A fresh log row was written referencing the original send.
        $rows = $log->getForSubmission((int) $submission->id);
        $I->assertCount(2, $rows);

        $resendRow = null;
        foreach ($rows as $row) {
            if ((int) ($row['resentFromId'] ?? 0) === $originalId) {
                $resendRow = $row;
                break;
            }
        }

        $I->assertNotNull($resendRow, 'A new log row referencing the original send was written.');
        $I->assertSame(NotificationLogService::STATUS_SUCCESS, $resendRow['status']);
        $I->assertNotSame($originalId, (int) $resendRow['id']);
    }

    public function testAutoresponderUsesSubmitterEmail(SmokeTester $I): void
    {
        $form = $this->createForm('Autoresponder', 'auto' . uniqid());
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $notification = new NotificationModel();
        $notification->formId = (int) $form->id;
        $notification->name = 'Thanks';
        $notification->recipientType = NotificationModel::RECIPIENT_FIELD;
        $notification->recipient = 'email';
        $notification->subject = 'We received your message';
        Plugin::getInstance()->getNotifications()->save($notification);

        $sent = $this->captureSentMessages(function() use ($form, $emailId): void {
            $this->withSyncSideEffects(function() use ($form, $emailId): void {
                $this->submitDirect($form, ['field_' . $emailId => 'visitor@example.com']);
            });
        });

        $I->assertCount(1, $sent);
        $I->assertArrayHasKey('visitor@example.com', $sent[0]->getTo());
    }
}
