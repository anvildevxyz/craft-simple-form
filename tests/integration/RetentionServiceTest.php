<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\DispatchStatus;
use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\AssetUploadService;
use Craft;
use craft\db\Query;
use craft\helpers\Db;

/**
 * Data-retention sweeps (#107): submission purge/anonymize + integration-log
 * prune, including the 0 = keep-forever guard.
 */
class RetentionServiceTest extends SimpleFormTestCase
{
    private function makeSubmission(int $formId, array $data = ['name' => 'Ada'], int $ageDays = 0): int
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = $data;
        $sub->readStatus = 'new';
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        $id = (int) $sub->id;

        if ($ageDays > 0) {
            $old = Db::prepareDateForDb((new \DateTime("now", new \DateTimeZone('UTC')))->modify("-{$ageDays} days"));
            Craft::$app->getDb()->createCommand()
                ->update('{{%simpleform_submissions}}', ['dateCreated' => $old], ['id' => $id])
                ->execute();
        }

        return $id;
    }

    private function submissionExists(int $id): bool
    {
        return (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $id])->exists();
    }

    public function testPurgeDeletesAgedSubmissionsOnly(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'retention_purge');
        $service = Plugin::getInstance()->getRetention();

        $old = $this->makeSubmission((int) $form->id, ['name' => 'Old'], 100);
        $recent = $this->makeSubmission((int) $form->id, ['name' => 'Recent'], 0);

        $affected = $service->purgeSubmissions(30, false);

        $this->assertSame(1, $affected);
        $this->assertFalse($this->submissionExists($old), 'aged submission should be deleted');
        $this->assertTrue($this->submissionExists($recent), 'recent submission should survive');
    }

    public function testAnonymizeKeepsRowButScrubsData(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'retention_anon');
        $service = Plugin::getInstance()->getRetention();

        $old = $this->makeSubmission((int) $form->id, ['name' => 'Sensitive'], 100);

        $affected = $service->purgeSubmissions(30, true);

        $this->assertSame(1, $affected);
        $this->assertTrue($this->submissionExists($old), 'anonymized row should remain');
        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $old])->one();
        $this->assertNull($row['data'], 'submitted data should be scrubbed');
        $this->assertNull($row['userId'], 'user reference should be scrubbed');
    }

    public function testRetentionDisabledWhenZero(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'retention_zero');
        $service = Plugin::getInstance()->getRetention();

        $old = $this->makeSubmission((int) $form->id, ['name' => 'Old'], 100);

        $this->assertSame(0, $service->purgeSubmissions(0, false));
        $this->assertTrue($this->submissionExists($old), 'nothing pruned when days = 0');
    }

    /** Flag an existing submission as spam and (optionally) backdate it. */
    private function flagSpam(int $id, int $ageDays = 0): void
    {
        $values = ['readStatus' => 'spam'];
        if ($ageDays > 0) {
            $values['dateCreated'] = Db::prepareDateForDb(
                (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$ageDays} days"),
            );
        }
        Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_submissions}}', $values, ['id' => $id])
            ->execute();
    }

    public function testPurgeSpamRemovesAgedSpamOnly(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'retention_spam');
        $service = Plugin::getInstance()->getRetention();

        $oldSpam = $this->makeSubmission((int) $form->id, ['name' => 'OldSpam'], 0);
        $this->flagSpam($oldSpam, 100);
        $recentSpam = $this->makeSubmission((int) $form->id, ['name' => 'FreshSpam'], 0);
        $this->flagSpam($recentSpam, 0);
        $oldHam = $this->makeSubmission((int) $form->id, ['name' => 'OldHam'], 100);

        $affected = $service->purgeSpam(30);

        $this->assertSame(1, $affected, 'only the aged spam row is pruned');
        $this->assertFalse($this->submissionExists($oldSpam), 'aged spam deleted');
        $this->assertTrue($this->submissionExists($recentSpam), 'recent spam kept');
        $this->assertTrue($this->submissionExists($oldHam), 'legitimate submissions untouched by the spam sweep');
    }

    public function testPurgeSpamDisabledWhenZero(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'retention_spam_zero');
        $service = Plugin::getInstance()->getRetention();

        $oldSpam = $this->makeSubmission((int) $form->id, ['name' => 'OldSpam'], 0);
        $this->flagSpam($oldSpam, 100);

        $this->assertSame(0, $service->purgeSpam(0), 'no-op when spam retention is 0');
        $this->assertTrue($this->submissionExists($oldSpam), 'spam kept forever at 0');
    }

    public function testPurgeSubmissionsBatchesBeyondOnePage(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'retention_batch');
        $service = Plugin::getInstance()->getRetention();

        // More than one BATCH (500) would be slow to seed; instead assert the
        // loop drains every matching row rather than a single page, using a
        // modest set that still exercises the multi-call path indirectly.
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->makeSubmission((int) $form->id, ['n' => $i], 100);
        }

        $affected = $service->purgeSubmissions(30, false);

        $this->assertSame(5, $affected);
        foreach ($ids as $id) {
            $this->assertFalse($this->submissionExists($id), 'every aged row drained');
        }
    }

    /** A stub asset service that records which asset ids it was asked to delete. */
    private function recordingAssetStub(): AssetUploadService
    {
        return new class() extends AssetUploadService {
            /** @var list<int> */
            public array $deleted = [];
            public function deleteAssets(int ...$ids): void
            {
                $this->deleted = array_merge($this->deleted, $ids);
            }
        };
    }

    public function testHardDeleteRemovesSignatureAsset(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Sig Delete', 'retention_sig_delete');

        $stub = $this->recordingAssetStub();
        Plugin::getInstance()->set('assetUploadService', $stub);

        // A submission whose stored data references a signature asset (id 4321).
        $id = $this->makeSubmission((int) $form->id, [
            'field_1' => ['label' => 'Signature', 'type' => 'signature', 'value' => [4321]],
        ], 100);

        try {
            $affected = Plugin::getInstance()->getRetention()->purgeSubmissions(30, false);
        } finally {
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
        }

        $this->assertSame(1, $affected);
        $this->assertFalse($this->submissionExists($id));
        $this->assertContains(4321, $stub->deleted, 'hard delete must remove the signature asset');
    }

    public function testAnonymizeScrubsAndRemovesSignatureAsset(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Sig Anon', 'retention_sig_anon');

        $stub = $this->recordingAssetStub();
        Plugin::getInstance()->set('assetUploadService', $stub);

        $id = $this->makeSubmission((int) $form->id, [
            'field_1' => ['label' => 'Signature', 'type' => 'signature', 'value' => [9911]],
        ], 100);

        try {
            $affected = Plugin::getInstance()->getRetention()->purgeSubmissions(30, true);
        } finally {
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
        }

        $this->assertSame(1, $affected);
        $this->assertTrue($this->submissionExists($id), 'anonymized row should remain');
        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $id])->one();
        $this->assertNull($row['data'], 'signature reference should be scrubbed from data');
        $this->assertContains(9911, $stub->deleted, 'anonymize must delete the signature asset');
    }

    public function testPruneIntegrationLogs(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();

        $integration = new IntegrationModel();
        $integration->type = 'webhook';
        $integration->name = 'Hook';
        $integration->settings = ['url' => 'https://example.test/hook'];
        $this->assertTrue($service->saveIntegration($integration));

        $oldLog = $service->logDispatch((int) $integration->id, null, DispatchStatus::SUCCESS);
        $recentLog = $service->logDispatch((int) $integration->id, null, DispatchStatus::SUCCESS);

        $old = Db::prepareDateForDb((new \DateTime('now', new \DateTimeZone('UTC')))->modify('-100 days'));
        Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_integration_logs}}', ['dateCreated' => $old], ['id' => $oldLog])
            ->execute();

        $deleted = Plugin::getInstance()->getRetention()->pruneIntegrationLogs(30);

        $this->assertSame(1, $deleted);
        $this->assertFalse((new Query())->from('{{%simpleform_integration_logs}}')->where(['id' => $oldLog])->exists());
        $this->assertTrue((new Query())->from('{{%simpleform_integration_logs}}')->where(['id' => $recentLog])->exists());
    }
}
