<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\migrations\m260622_000001_merge_default_notification;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\Plugin;

/**
 * The catch-up migration that folds a form's legacy Email Settings into a
 * "Default notification" once email config moved to the Notifications screen.
 * Mirrors the original notifications migration's behaviour, but only for forms
 * that still have a legacy emailTo and no notification rows.
 *
 * @group requires-craft
 */
class FormEmailMergeMigrationTest extends SimpleFormTestCase
{
    private function runMigration(): void
    {
        $migration = new m260622_000001_merge_default_notification();
        $migration->db = Craft::$app->getDb();
        $migration->compact = true; // suppress migration echo in test output
        $this->assertTrue($migration->safeUp());
    }

    public function testLegacyEmailFormGainsDefaultNotification(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Legacy', 'merge_legacy', null, null, 'owner@example.test', 'Hello');

        // Precondition: no notifications yet.
        $this->assertSame([], Plugin::getInstance()->getNotifications()->getForForm((int) $form->id));

        $this->runMigration();

        $notifications = Plugin::getInstance()->getNotifications()->getForForm((int) $form->id);
        $this->assertCount(1, $notifications);

        $default = $notifications[0];
        $this->assertSame(NotificationModel::RECIPIENT_FIXED, $default->recipientType);
        $this->assertSame('owner@example.test', $default->recipient);
        $this->assertSame('Hello', $default->subject);
        $this->assertTrue($default->enabled);
    }

    public function testFormWithExistingNotificationIsLeftAlone(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Already', 'merge_existing', null, null, 'owner@example.test');

        $existing = new NotificationModel();
        $existing->formId = (int) $form->id;
        $existing->name = 'Manual';
        $existing->recipient = 'manual@example.test';
        $this->assertTrue(Plugin::getInstance()->getNotifications()->save($existing));

        $this->runMigration();

        // Still exactly the one we created — no duplicate Default notification.
        $notifications = Plugin::getInstance()->getNotifications()->getForForm((int) $form->id);
        $this->assertCount(1, $notifications);
        $this->assertSame('manual@example.test', $notifications[0]->recipient);
    }

    public function testFormWithoutEmailIsSkipped(): void
    {
        $this->requireCraft();

        $form = $this->createForm('NoEmail', 'merge_none');

        $this->runMigration();

        $this->assertSame([], Plugin::getInstance()->getNotifications()->getForForm((int) $form->id));
    }
}
