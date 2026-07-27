<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\controllers\NotificationLogController;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\models\NotificationModel;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\NotificationLogService;
use Craft;
use craft\elements\User;
use craft\web\Response;
use SmokeTester;
use yii\web\ForbiddenHttpException;

/**
 * Notification email smoke tests (functional).
 *
 * Exercises the full submit → queue/send notification path for both legacy
 * form email columns and per-form notification rows.
 *
 * @author Anvil Dev
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

    public function testCcAndBccHeadersOnSentMessage(SmokeTester $I): void
    {
        $form = $this->createForm('CC Notify', 'ccNotify' . uniqid());
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $notification = new NotificationModel();
        $notification->formId = (int) $form->id;
        $notification->name = 'Team alert';
        $notification->recipientType = NotificationModel::RECIPIENT_FIXED;
        $notification->recipient = 'ops@example.test';
        $notification->cc = 'team@example.test, lead@example.test';
        $notification->bcc = 'archive@example.test';
        $notification->subject = 'New lead';
        $I->assertTrue(Plugin::getInstance()->getNotifications()->save($notification));

        $sent = $this->captureSentMessages(function() use ($form, $emailId): void {
            $this->withSyncSideEffects(function() use ($form, $emailId): void {
                $this->submitDirect($form, ['field_' . $emailId => 'lead@example.com']);
            });
        });

        $I->assertCount(1, $sent);
        $I->assertArrayHasKey('ops@example.test', $sent[0]->getTo());
        $I->assertArrayHasKey('team@example.test', $sent[0]->getCc());
        $I->assertArrayHasKey('lead@example.test', $sent[0]->getCc());
        $I->assertArrayHasKey('archive@example.test', $sent[0]->getBcc());
    }

    public function testHeaderInjectionInCcIsRejected(SmokeTester $I): void
    {
        $form = $this->createForm('Injection', 'inject' . uniqid());

        $notification = new NotificationModel();
        $notification->formId = (int) $form->id;
        $notification->name = 'Bad';
        $notification->recipientType = NotificationModel::RECIPIENT_FIXED;
        $notification->recipient = 'ops@example.test';
        $notification->cc = "victim@example.test\r\nBcc: attacker@example.test";

        $I->assertFalse(Plugin::getInstance()->getNotifications()->save($notification));
        $I->assertArrayHasKey('cc', $notification->getErrors());
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

    /**
     * Security regression: the resend action re-dispatches outbound email, so
     * it must require MANAGE_SUBMISSIONS on top of the VIEW_SUBMISSIONS gate
     * shared by the rest of the log — a view-only user must be forbidden,
     * while a manage-capable user succeeds.
     */
    public function testResendRequiresManageSubmissionsPermission(SmokeTester $I): void
    {
        $form = $this->createForm('Resend Permission', 'resendPermission' . uniqid());
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

        $result = $this->withSyncSideEffects(function() use ($form, $emailId) {
            return $this->submitDirect($form, ['field_' . $emailId => 'lead@example.com']);
        });
        $submission = $result['submission'];
        $I->assertInstanceOf(Submission::class, $submission);

        $log = Plugin::getInstance()->getNotificationLog();
        $original = $log->getForSubmission((int) $submission->id);
        $I->assertCount(1, $original);
        $originalId = (int) $original[0]['id'];

        $viewerId = $this->seedUser('viewer-' . uniqid() . '@example.test', [
            SimpleFormPermissions::VIEW_SUBMISSIONS,
        ]);
        $managerId = $this->seedUser('manager-' . uniqid() . '@example.test', [
            SimpleFormPermissions::VIEW_SUBMISSIONS,
            SimpleFormPermissions::MANAGE_SUBMISSIONS,
        ]);

        // A view-only user is forbidden from resending.
        $forbidden = false;
        $this->asUser($viewerId, function() use ($originalId, &$forbidden): void {
            try {
                $this->callResend($originalId);
            } catch (ForbiddenHttpException) {
                $forbidden = true;
            }
        });
        $I->assertTrue($forbidden, 'A view-only user must be forbidden from resending notifications');
        $I->assertCount(1, $log->getForSubmission((int) $submission->id), 'No resend row written for the rejected attempt');

        // A manage-capable user can resend.
        $data = null;
        $this->asUser($managerId, function() use ($originalId, &$data): void {
            $this->withSyncSideEffects(function() use ($originalId, &$data): void {
                $data = $this->callResend($originalId);
            });
        });
        $I->assertTrue($data['success'] ?? false, 'A manage-capable user can resend notifications');
        $I->assertCount(2, $log->getForSubmission((int) $submission->id));
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

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Call the CP resend action directly (through its full `beforeAction`
     * lifecycle, so the permission gate under test actually runs) and return
     * its decoded JSON payload.
     *
     * @return array<string, mixed>
     * @throws ForbiddenHttpException if the active user lacks MANAGE_SUBMISSIONS
     */
    private function callResend(int $logId): array
    {
        $request = Craft::$app->getRequest();
        $request->getHeaders()->set('Accept', 'application/json');
        $request->setBodyParams(['logId' => $logId]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new NotificationLogController('notification-log', Plugin::getInstance());
        $controller->enableCsrfValidation = false;

        /** @var array<string, mixed> $data */
        $data = $controller->runAction('resend')->data;

        return $data;
    }

    /**
     * Seed a non-admin user with the given permissions and return its id.
     *
     * @param list<string> $permissions
     */
    private function seedUser(string $email, array $permissions): int
    {
        $user = new User();
        $user->email = $email;
        $user->username = $email;
        Craft::$app->getElements()->saveElement($user);

        Craft::$app->getUserPermissions()->saveUserPermissions((int) $user->id, $permissions);

        return (int) $user->id;
    }

    /**
     * Run a closure with the given user as the active identity, restoring the
     * prior (guest) identity afterwards.
     */
    private function asUser(int $userId, callable $fn): void
    {
        $userSession = Craft::$app->getUser();
        $previous = $userSession->getIdentity();
        try {
            $userSession->setIdentity(User::find()->id($userId)->one());
            $fn();
        } finally {
            $userSession->setIdentity($previous);
        }
    }
}
