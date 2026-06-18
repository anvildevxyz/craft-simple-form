<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\AkismetService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/** Akismet service with a mocked comment-check client. */
class MockAkismet extends AkismetService
{
    public function __construct(private MockHandler $mock)
    {
    }

    protected function httpClient(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->mock)]);
    }
}

/** Always-spam stand-in for testing the submit flag/block flow without HTTP. */
class AlwaysSpamAkismet extends AkismetService
{
    public function isSpam(Form $form, array $data): bool
    {
        return true;
    }
}

/**
 * #88 — Akismet content spam check: enum migration, verdict (mocked), and the
 * flag/block submit flow.
 *
 * @group requires-craft
 */
class AkismetTest extends SimpleFormTestCase
{
    public function testSpamStatusPersists(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Spam Status', 'spam_status_form');

        $sub = new Submission();
        $sub->formId = (int) $form->id;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = [];
        $sub->readStatus = SubmissionStatus::SPAM;
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));

        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $sub->id])->one();
        $this->assertSame('spam', $row['readStatus']);
    }

    public function testVerdictTrueAndFalse(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->enableAkismet = true;
            $settings->akismetApiKey = 'test-key';
            $form = $this->createForm('Verdict', 'akismet_verdict');
            $data = ['field_1' => ['label' => 'Message', 'type' => 'text', 'value' => 'buy cheap pills']];

            $spam = new MockAkismet(new MockHandler([new Response(200, [], 'true')]));
            $this->assertTrue($spam->isSpam($form, $data));

            $ham = new MockAkismet(new MockHandler([new Response(200, [], 'false')]));
            $this->assertFalse($ham->isSpam($form, $data));
        } finally {
            $settings->setAttributes($original, false);
        }
    }

    public function testDisabledMeansNoSpamAndNoCall(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->enableAkismet = false;
        $form = $this->createForm('Disabled', 'akismet_disabled');

        // No responses queued — a call would throw, proving it short-circuits.
        $provider = new MockAkismet(new MockHandler([]));
        $this->assertFalse($provider->isSpam($form, []));
    }

    public function testFlagModeSavesSubmissionAsSpam(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->enableAkismet = true;
            $settings->akismetApiKey = 'test-key';
            $settings->akismetMode = Settings::AKISMET_FLAG;
            Plugin::getInstance()->set('akismetService', new AlwaysSpamAkismet());

            $form = $this->createForm('Flag', 'akismet_flag');
            $fieldId = $this->createField($form->id, 'text', 'name', 'Name');

            $result = Plugin::getInstance()->getSubmissionService()
                ->submit($form, ['field_' . $fieldId => 'spammy'], ['skipCaptcha' => true]);

            $this->assertNotNull($result['submission']);
            $this->assertSame(SubmissionStatus::SPAM, $result['submission']->readStatus);
        } finally {
            $settings->setAttributes($original, false);
            Plugin::getInstance()->set('akismetService', AkismetService::class);
        }
    }

    public function testBlockModeDropsSubmission(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->enableAkismet = true;
            $settings->akismetApiKey = 'test-key';
            $settings->akismetMode = Settings::AKISMET_BLOCK;
            Plugin::getInstance()->set('akismetService', new AlwaysSpamAkismet());

            $form = $this->createForm('Block', 'akismet_block');
            $fieldId = $this->createField($form->id, 'text', 'name', 'Name');

            $result = Plugin::getInstance()->getSubmissionService()
                ->submit($form, ['field_' . $fieldId => 'spammy'], ['skipCaptcha' => true]);

            $this->assertNull($result['submission'], 'block mode drops the submission');
            $this->assertNull($result['errors']);

            $count = (new Query())->from('{{%simpleform_submissions}}')->where(['formId' => $form->id])->count();
            $this->assertSame(0, (int) $count, 'no row persisted in block mode');
        } finally {
            $settings->setAttributes($original, false);
            Plugin::getInstance()->set('akismetService', AkismetService::class);
        }
    }
}
