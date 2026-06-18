<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\DispatchStatus;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;

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
