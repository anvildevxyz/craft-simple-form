<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\EmailService;

/**
 * Per-form notifications (#112): recipient resolution (fixed + autoresponder),
 * condition gating, enabled flag, and the EmailService notification path.
 */
class NotificationsServiceTest extends SimpleFormTestCase
{
    /** @return array{0: Form, 1: int, 2: int} [form, emailFieldId, subscribeFieldId] */
    private function seedForm(string $handle): array
    {
        $form = $this->createForm('Contact', $handle);
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $subId = $this->createField((int) $form->id, 'text', 'subscribe', 'Subscribe');
        return [$form, $emailId, $subId];
    }

    private function submissionFor(Form $form): Submission
    {
        $sub = new Submission();
        $sub->formId = (int) $form->id;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        return $sub;
    }

    /** @param array<string,mixed> $extra */
    private function save(int $formId, array $extra): NotificationModel
    {
        $n = new NotificationModel();
        $n->formId = $formId;
        $n->name = $extra['name'] ?? 'N';
        $n->enabled = $extra['enabled'] ?? true;
        $n->recipientType = $extra['recipientType'] ?? NotificationModel::RECIPIENT_FIXED;
        $n->recipient = $extra['recipient'] ?? 'ops@example.test';
        $n->conditional = $extra['conditional'] ?? null;
        $this->assertTrue(Plugin::getInstance()->getNotifications()->save($n));
        return $n;
    }

    public function testReplyToMustBeAValidEmail(): void
    {
        $this->requireCraft();
        [$form] = $this->seedForm('replyToValidation');

        // F11 (CWE-93): a CRLF/header-injection replyTo is rejected by validation.
        $bad = new NotificationModel();
        $bad->formId = (int) $form->id;
        $bad->name = 'Bad reply-to';
        $bad->recipient = 'ops@example.test';
        $bad->replyTo = "attacker@evil.test\r\nBcc: victim@corp.test";
        $this->assertFalse($bad->validate(), 'invalid replyTo should fail validation');
        $this->assertArrayHasKey('replyTo', $bad->getErrors());

        // A normal address still validates, and an empty value is allowed.
        $good = new NotificationModel();
        $good->formId = (int) $form->id;
        $good->name = 'Good reply-to';
        $good->recipient = 'ops@example.test';
        $good->replyTo = 'support@example.test';
        $this->assertTrue($good->validate(), implode(',', $good->getFirstErrors()));
    }

    public function testFixedRecipientResolvesAndSplitsAddresses(): void
    {
        $this->requireCraft();
        [$form, $emailId] = $this->seedForm('notif_fixed');
        $this->save((int) $form->id, ['recipient' => 'a@example.test, b@example.test']);

        $data = ['field_' . $emailId => ['label' => 'Email', 'type' => 'email', 'value' => 'user@example.test']];
        $resolved = Plugin::getInstance()->getNotifications()->resolveForSubmission($form, $this->submissionFor($form), $data);

        $this->assertCount(1, $resolved);
        $this->assertSame(['a@example.test', 'b@example.test'], $resolved[0]['recipients']);
    }

    public function testAutoresponderResolvesSubmitterEmail(): void
    {
        $this->requireCraft();
        [$form, $emailId] = $this->seedForm('notif_auto');
        $this->save((int) $form->id, ['recipientType' => NotificationModel::RECIPIENT_FIELD, 'recipient' => 'email']);

        $data = ['field_' . $emailId => ['label' => 'Email', 'type' => 'email', 'value' => 'visitor@example.test']];
        $resolved = Plugin::getInstance()->getNotifications()->resolveForSubmission($form, $this->submissionFor($form), $data);

        $this->assertCount(1, $resolved);
        $this->assertSame(['visitor@example.test'], $resolved[0]['recipients']);
    }

    public function testDisabledNotificationIsExcluded(): void
    {
        $this->requireCraft();
        [$form, $emailId] = $this->seedForm('notif_disabled');
        $this->save((int) $form->id, ['enabled' => false]);

        $data = ['field_' . $emailId => ['label' => 'Email', 'type' => 'email', 'value' => 'user@example.test']];
        $this->assertCount(0, Plugin::getInstance()->getNotifications()->resolveForSubmission($form, $this->submissionFor($form), $data));
    }

    public function testConditionGatesSending(): void
    {
        $this->requireCraft();
        [$form, $emailId, $subId] = $this->seedForm('notif_cond');
        $this->save((int) $form->id, [
            'recipient' => 'ops@example.test',
            'conditional' => [
                'enabled' => true,
                'match' => 'all',
                'action' => 'show',
                'rules' => [['field' => 'subscribe', 'operator' => 'eq', 'value' => 'yes']],
            ],
        ]);

        $service = Plugin::getInstance()->getNotifications();
        $base = ['field_' . $emailId => ['label' => 'Email', 'type' => 'email', 'value' => 'user@example.test']];

        $matching = $base + ['field_' . $subId => ['label' => 'Subscribe', 'type' => 'text', 'value' => 'yes']];
        $this->assertCount(1, $service->resolveForSubmission($form, $this->submissionFor($form), $matching));

        $notMatching = $base + ['field_' . $subId => ['label' => 'Subscribe', 'type' => 'text', 'value' => 'no']];
        $this->assertCount(0, $service->resolveForSubmission($form, $this->submissionFor($form), $notMatching));
    }

    public function testEmailServiceSendsViaNotificationsWithoutLegacyEmailTo(): void
    {
        $this->requireCraft();
        // Form has NO emailTo, so a sent email proves the notification path ran.
        [$form, $emailId] = $this->seedForm('notif_send');
        $this->save((int) $form->id, ['recipient' => 'ops@example.test']);

        $submission = $this->submissionFor($form);
        $this->assertTrue(Craft::$app->getElements()->saveElement($submission));
        $data = ['field_' . $emailId => ['label' => 'Email', 'type' => 'email', 'value' => 'user@example.test']];

        $this->assertTrue((new EmailService())->sendSubmissionEmail($form, $submission, $data));
    }
}
