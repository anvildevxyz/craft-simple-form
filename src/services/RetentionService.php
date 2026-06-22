<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Data-retention housekeeping (#107). Prunes submissions and integration dispatch
 * logs past their configured age on Craft's garbage-collection run. All thresholds
 * are opt-in: 0 days = keep forever.
 *
 * Submissions can be either hard-deleted (default) or anonymized in place
 * (`anonymizeInsteadOfDelete`), which scrubs the PII-bearing `data` + `userId`
 * while keeping the row so aggregate counts/stats survive.
 */
class RetentionService extends Component
{
    private const SUBMISSIONS = '{{%simpleform_submissions}}';
    private const INTEGRATION_LOGS = '{{%simpleform_integration_logs}}';

    /** Delete elements in bounded batches so a large backlog never loads at once. */
    private const BATCH = 500;

    /**
     * Run all retention sweeps. Returns counts for logging/telemetry.
     *
     * @return array{submissions: int, integrationLogs: int, auditLog: int}
     */
    public function runGarbageCollection(): array
    {
        return [
            'submissions' => $this->purgeSubmissions(),
            'integrationLogs' => $this->pruneIntegrationLogs(),
            'auditLog' => Plugin::getInstance()->getAudit()->prune(
                Plugin::getInstance()->getSettings()->retainAuditLogDays,
            ),
            'drafts' => Plugin::getInstance()->getDrafts()->gcExpired(),
        ];
    }

    /**
     * Prune (or anonymize) submissions older than `retainSubmissionsDays`.
     * Returns the number of submissions affected. No-op when the setting is 0.
     */
    public function purgeSubmissions(?int $days = null, ?bool $anonymize = null, ?int $formId = null): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $days ??= $settings->retainSubmissionsDays;
        $anonymize ??= $settings->anonymizeInsteadOfDelete;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb($this->cutoff($days));
        $query = (new Query())
            ->select(['id'])
            ->from(self::SUBMISSIONS)
            ->where(['<', 'dateCreated', $cutoff]);
        if ($formId !== null) {
            $query->andWhere(['formId' => $formId]);
        }
        $ids = array_map('intval', $query->column());
        if ($ids === []) {
            return 0;
        }

        return $anonymize ? $this->anonymize($ids) : $this->hardDelete($ids);
    }

    /**
     * Delete integration dispatch-log rows older than `retainIntegrationLogsDays`.
     * Returns the number of rows deleted. No-op when the setting is 0.
     */
    public function pruneIntegrationLogs(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->retainIntegrationLogsDays;
        if ($days <= 0) {
            return 0;
        }

        return (int) Craft::$app->getDb()->createCommand()
            ->delete(self::INTEGRATION_LOGS, ['<', 'dateCreated', Db::prepareDateForDb($this->cutoff($days))])
            ->execute();
    }

    /**
     * @param list<int> $ids
     */
    private function anonymize(array $ids): int
    {
        // A signature/file is PII held in an asset volume, not just the JSON row.
        // Delete those assets before nulling the data so anonymization scrubs the
        // image too, not only the reference (#129).
        $this->deleteSubmissionAssets($ids);

        // Null the JSON data + user reference in place; the element row, count and
        // readStatus survive so stats stay meaningful.
        return (int) Craft::$app->getDb()->createCommand()
            ->update(self::SUBMISSIONS, ['data' => null, 'userId' => null], ['id' => $ids])
            ->execute();
    }

    /**
     * @param list<int> $ids
     */
    private function hardDelete(array $ids): int
    {
        // Remove asset-bearing field values (file + signature) so no orphaned
        // image survives the submission it belonged to (#129).
        $this->deleteSubmissionAssets($ids);

        $elements = Craft::$app->getElements();
        $db = Craft::$app->getDb();
        $deleted = 0;
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            foreach ($chunk as $id) {
                if ($elements->deleteElementById($id, Submission::class, null, true)) {
                    $deleted++;
                }
            }
            // simpleform_submissions has no cascading FK to elements.id, so the
            // PII-bearing row doesn't go with the element — remove it explicitly.
            $db->createCommand()->delete(self::SUBMISSIONS, ['id' => $chunk])->execute();
        }

        return $deleted;
    }

    /**
     * Collect the asset ids stored by file/signature fields across the given
     * submissions and delete them via the shared asset-cleanup path, so removing
     * or anonymizing a submission never leaves its asset behind.
     *
     * @param list<int> $ids
     */
    private function deleteSubmissionAssets(array $ids): void
    {
        $rows = (new Query())
            ->select(['data'])
            ->from(self::SUBMISSIONS)
            ->where(['id' => $ids])
            ->column();

        $assetIds = [];
        foreach ($rows as $raw) {
            $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $entry) {
                if (!is_array($entry) || !in_array($entry['type'] ?? null, FieldTypeRegistry::ASSET_TYPES, true)) {
                    continue;
                }
                foreach ((array) ($entry['value'] ?? []) as $assetId) {
                    if (is_numeric($assetId)) {
                        $assetIds[(int) $assetId] = true;
                    }
                }
            }
        }

        if ($assetIds !== []) {
            Plugin::getInstance()->getAssetUploadService()->deleteAssets(...array_keys($assetIds));
        }
    }

    private function cutoff(int $days): \DateTime
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$days} days");
    }
}
