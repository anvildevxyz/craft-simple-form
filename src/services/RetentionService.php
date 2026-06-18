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
        $ids = $query->column();

        $ids = array_map('intval', $ids);
        if ($ids === []) {
            return 0;
        }

        if ($anonymize) {
            return $this->anonymize($ids);
        }

        return $this->hardDelete($ids);
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

    private function cutoff(int $days): \DateTime
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$days} days");
    }
}
