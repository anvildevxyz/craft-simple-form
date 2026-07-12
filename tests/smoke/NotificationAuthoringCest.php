<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\controllers\NotificationsController;
use anvildev\simpleform\models\NotificationModel;
use anvildev\simpleform\Plugin;
use Craft;
use craft\elements\User;
use craft\web\Response;
use SmokeTester;
use Symfony\Component\Mime\Email;

/**
 * Notification authoring gaps (#290): plain-text alternative body + the
 * "Send test" controller action. The friendly operator labels and the
 * autoresponder recipient field-select are template-only and covered by the
 * Playwright craft-smoke-test scenarios; the service/controller behaviour behind
 * this feature is exercised here.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class NotificationAuthoringCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Every sent notification must be multipart: an HTML part AND a plain-text
     * alternative derived from it (deliverability, #290 item 5).
     */
    public function testNotificationCarriesPlainTextAlternative(SmokeTester $I): void
    {
        $form = $this->createForm('Alt Body', 'altBody' . uniqid());
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $notification = new NotificationModel();
        $notification->formId = (int) $form->id;
        $notification->name = 'Ops alert';
        $notification->recipientType = NotificationModel::RECIPIENT_FIXED;
        $notification->recipient = 'ops@example.test';
        $notification->subject = 'New lead';
        $I->assertTrue(Plugin::getInstance()->getNotifications()->save($notification));

        $sent = $this->captureSentMessages(function() use ($form, $emailId): void {
            $this->withSyncSideEffects(function() use ($form, $emailId): void {
                $this->submitDirect($form, ['field_' . $emailId => 'lead@example.com']);
            });
        });

        $I->assertCount(1, $sent);
        $email = $sent[0]->getSymfonyEmail();
        $I->assertInstanceOf(Email::class, $email);

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $I->assertNotEmpty($html, 'the message carries an HTML part');
        $I->assertNotEmpty($text, 'the message carries a plain-text alternative');
        $I->assertIsString($text);
        $I->assertStringNotContainsString('<', $text, 'the plain-text part has no HTML tags');
    }

    /**
     * The "Send test" action composes and delivers one test copy of a saved
     * notification to the posted recipient, under the MANAGE_FORMS gate
     * (#290 item 4).
     */
    public function testTestSendDeliversToRecipient(SmokeTester $I): void
    {
        $form = $this->createForm('Test Send', 'testSend' . uniqid());
        $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $notification = new NotificationModel();
        $notification->formId = (int) $form->id;
        $notification->name = 'Deliverability check';
        $notification->recipientType = NotificationModel::RECIPIENT_FIXED;
        $notification->recipient = 'ops@example.test';
        $notification->subject = 'New lead';
        $notification->body = 'Hello from {{ form.title }}';
        $I->assertTrue(Plugin::getInstance()->getNotifications()->save($notification));

        $to = 'tester-' . uniqid() . '@example.test';

        $sent = $this->captureSentMessages(function() use ($form, $notification, $to): void {
            $this->asAdmin(function() use ($form, $notification, $to): void {
                $response = $this->callTestSend((int) $form->id, (int) $notification->id, $to);
                $this->assertRedirect($response);
            });
        });

        $I->assertCount(1, $sent, 'exactly one test notification was sent');
        $I->assertArrayHasKey($to, $sent[0]->getTo(), 'the test copy went to the posted recipient');
        $I->assertSame('New lead', $sent[0]->getSubject());
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Call the CP test-send action through its full lifecycle (so the permission
     * gate under test runs) and return the redirect Response.
     */
    private function callTestSend(int $formId, int $notificationId, string $to): Response
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formId' => $formId,
            'notificationId' => $notificationId,
            'testEmail' => $to,
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new NotificationsController('notifications', Plugin::getInstance());
        $controller->enableCsrfValidation = false;

        return $controller->runAction('test-send');
    }

    /**
     * Assert a controller Response is a redirect (the test-send success path).
     */
    private function assertRedirect(Response $response): void
    {
        if ($response->getStatusCode() < 300 || $response->getStatusCode() >= 400) {
            throw new \RuntimeException('Expected a redirect response, got ' . $response->getStatusCode());
        }
    }

    /**
     * Run $work with a freshly-seeded admin as the active identity, restoring the
     * prior identity afterwards.
     */
    private function asAdmin(callable $work): void
    {
        $user = new User();
        $user->admin = true;
        $user->email = 'notif-admin-' . uniqid() . '@example.test';
        $user->username = $user->email;
        Craft::$app->getElements()->saveElement($user);

        $session = Craft::$app->getUser();
        $previous = $session->getIdentity();
        try {
            $session->setIdentity($user);
            $work();
        } finally {
            $session->setIdentity($previous);
        }
    }
}
