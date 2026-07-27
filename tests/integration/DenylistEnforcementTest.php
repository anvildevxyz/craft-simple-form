<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\models\Settings;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;

/**
 * #140 — Settings-driven denylists enforced in SubmissionService::submit():
 * keyword/email/IP matching, the flag vs. block fork, and that flagged
 * submissions withhold their notifications.
 *
 * @group requires-craft
 */
class DenylistEnforcementTest extends SimpleFormTestCase
{
    /**
     * Configure the denylist settings, run a closure, then restore.
     *
     * @param array<string, mixed> $overrides
     */
    private function withDenylist(array $overrides, callable $fn): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->enableDenylists = true;
            $settings->denylistMode = Settings::DENYLIST_FLAG;
            $settings->setAttributes($overrides, false);
            $fn();
        } finally {
            $settings->setAttributes($original, false);
        }
    }

    public function testKeywordFlagsAsSpamWithReason(): void
    {
        $this->requireCraft();
        $this->withDenylist(['blockedKeywords' => "casino\ncrypto"], function(): void {
            $form = $this->createForm('KW Flag', 'kw_flag');
            $fieldId = $this->createField((int) $form->id, 'text', 'message', 'Message');

            $result = Plugin::getInstance()->getSubmissionService()
                ->submit($form, ['field_' . $fieldId => 'Win at the Casino tonight'], ['skipCaptcha' => true]);

            $this->assertNotNull($result['submission']);
            $this->assertSame(SubmissionStatus::SPAM, $result['submission']->readStatus);
            $this->assertSame('keyword:casino', $result['submission']->spamReason);
        });
    }

    public function testEmailDomainFlagsAndCleanEmailPasses(): void
    {
        $this->requireCraft();
        $this->withDenylist(['blockedEmails' => '@mailinator.com'], function(): void {
            $form = $this->createForm('Email Flag', 'email_flag');
            $emailField = $this->createField((int) $form->id, 'email', 'email', 'Email');

            $service = Plugin::getInstance()->getSubmissionService();

            $blocked = $service->submit($form, ['field_' . $emailField => 'bob@mailinator.com'], ['skipCaptcha' => true]);
            $this->assertSame(SubmissionStatus::SPAM, $blocked['submission']->readStatus);
            $this->assertSame('email:bob@mailinator.com', $blocked['submission']->spamReason);

            $clean = $service->submit($form, ['field_' . $emailField => 'bob@gmail.com'], ['skipCaptcha' => true]);
            $this->assertSame(SubmissionStatus::NEW, $clean['submission']->readStatus);
            $this->assertNull($clean['submission']->spamReason);
        });
    }

    public function testBlockModeDropsSilently(): void
    {
        $this->requireCraft();
        $this->withDenylist(
            ['blockedKeywords' => 'casino', 'denylistMode' => Settings::DENYLIST_BLOCK],
            function(): void {
                $form = $this->createForm('KW Block', 'kw_block');
                $fieldId = $this->createField((int) $form->id, 'text', 'message', 'Message');

                $result = Plugin::getInstance()->getSubmissionService()
                    ->submit($form, ['field_' . $fieldId => 'casino night'], ['skipCaptcha' => true]);

                $this->assertNull($result['submission'], 'block mode drops the submission');
                $this->assertNull($result['errors']);

                $count = (new Query())->from('{{%simpleform_submissions}}')->where(['formId' => $form->id])->count();
                $this->assertSame(0, (int) $count, 'no row persisted in block mode');
            },
        );
    }

    public function testDisabledDenylistIsNoop(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->enableDenylists = false;
            $settings->blockedKeywords = 'casino';
            $form = $this->createForm('KW Off', 'kw_off');
            $fieldId = $this->createField((int) $form->id, 'text', 'message', 'Message');

            $result = Plugin::getInstance()->getSubmissionService()
                ->submit($form, ['field_' . $fieldId => 'casino night'], ['skipCaptcha' => true]);

            $this->assertSame(SubmissionStatus::NEW, $result['submission']->readStatus);
        } finally {
            $settings->setAttributes($original, false);
        }
    }

    public function testMalformedCidrIsRejectedAtSettingsSave(): void
    {
        $this->requireCraft();
        $settings = new Settings();
        $settings->defaultEmailSender = 'from@example.com';
        $settings->blockedIps = "203.0.113.0/24\n203.0.113.0/99";

        $this->assertFalse($settings->validate());
        $this->assertNotEmpty($settings->getErrors('blockedIps'));
    }

    public function testValidIpListPassesValidation(): void
    {
        $this->requireCraft();
        $settings = new Settings();
        $settings->defaultEmailSender = 'from@example.com';
        $settings->blockedIps = "203.0.113.5\n2001:db8::/32\n10.0.0.0/8";

        $settings->validate();
        $this->assertEmpty($settings->getErrors('blockedIps'));
    }
}
