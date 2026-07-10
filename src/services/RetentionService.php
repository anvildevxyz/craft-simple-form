<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use yii\base\Component;
use yii\db\Expression;

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
    private const NOTIFICATION_LOGS = '{{%simpleform_notification_logs}}';

    /** Delete elements in bounded batches so a large backlog never loads at once. */
    private const BATCH = 500;

    /**
     * Run all retention sweeps. Returns counts for logging/telemetry.
     *
     * @return array{submissions: int, integrationLogs: int, notificationLogs: int, auditLog: int, drafts: int}
     */
    public function runGarbageCollection(): array
    {
        return [
            'submissions' => $this->purgeSubmissions(),
            'integrationLogs' => $this->pruneIntegrationLogs(),
            'notificationLogs' => $this->pruneNotificationLogs(),
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
        // Edition-blind at runtime: a configured retention/anonymization policy
        // keeps running regardless of edition, so a Pro->Solo downgrade never
        // silently stops purging PII. Solo is prevented from *enabling* the policy
        // in the first place (the settings authoring gate); a `0` threshold is
        // still a no-op below.
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
     * Every submission id tied to an email address, for GDPR subject-access /
     * right-to-erasure (#314). Matching scans both the linked Craft user's email
     * and the submitted JSON `data` blob (any field value — Email, Text, or a
     * composite/repeater sub-value — equal to the address), so it catches the
     * email wherever it was captured. Scanning is done in PHP, not via a JSON
     * `LIKE`, to stay database-agnostic (MySQL + Postgres).
     *
     * The submissions table is streamed in {@see self::BATCH}-sized chunks via
     * `Query::batch()` rather than loaded with `all()`, so a large table never
     * materializes every `data` blob in memory at once (#325).
     *
     * @return list<int>
     */
    public function findSubmissionIdsByEmail(string $email): array
    {
        $needle = mb_strtolower(trim($email));
        if ($needle === '') {
            return [];
        }

        $matchingUserIds = $this->userIdsForEmail($needle);

        $ids = [];
        $query = (new Query())
            ->select(['id', 'userId', 'data'])
            ->from(self::SUBMISSIONS);
        foreach ($query->batch(self::BATCH) as $rows) {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if ($row['userId'] !== null && isset($matchingUserIds[(int) $row['userId']])) {
                    $ids[] = $id;
                    continue;
                }

                $data = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);
                if ($this->valueContainsEmail($data, $needle)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Erase (delete or anonymize) the given submissions, honoring the existing
     * `anonymizeInsteadOfDelete` setting unless `$anonymize` overrides it (#314).
     * Reuses the same asset-scrubbing delete/anonymize path as the retention
     * sweep. Returns the number of submissions affected.
     *
     * @param list<int> $ids
     */
    public function eraseSubmissions(array $ids, ?bool $anonymize = null): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        $anonymize ??= Plugin::getInstance()->getSettings()->anonymizeInsteadOfDelete;

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
     * Delete notification send-log rows older than `retainNotificationLogsDays`.
     * Returns the number of rows deleted. No-op when the setting is 0.
     */
    public function pruneNotificationLogs(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->retainNotificationLogsDays;
        if ($days <= 0) {
            return 0;
        }

        return (int) Craft::$app->getDb()->createCommand()
            ->delete(self::NOTIFICATION_LOGS, ['<', 'dateCreated', Db::prepareDateForDb($this->cutoff($days))])
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

    /**
     * The set (id => true) of Craft user ids whose (lowercased) email matches
     * $needle. Queried directly against the (small, bounded) users table —
     * independent of how many submissions exist — rather than by first scanning
     * every submission row for a `userId`, so this never grows with submission
     * volume. The comparison is done in SQL via `LOWER()`, which is portable
     * across MySQL and Postgres.
     *
     * @return array<int, true>
     */
    private function userIdsForEmail(string $needle): array
    {
        $matching = [];
        $users = (new Query())
            ->select(['id'])
            ->from('{{%users}}')
            ->where(new Expression('LOWER(TRIM([[email]])) = :needle', [':needle' => $needle]))
            ->column();
        foreach ($users as $id) {
            $matching[(int) $id] = true;
        }

        return $matching;
    }

    /**
     * Whether $value (a submitted `data` blob, an entry, or a scalar) contains
     * $needle as an email address anywhere in its structure. Recurses arrays so a
     * match inside a composite (Name/Address) sub-value or a repeater row still
     * counts. $needle must already be lowercased/trimmed.
     */
    private function valueContainsEmail(mixed $value, string $needle): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->valueContainsEmail($child, $needle)) {
                    return true;
                }
            }

            return false;
        }

        return is_scalar($value) && mb_strtolower(trim((string) $value)) === $needle;
    }

    private function cutoff(int $days): \DateTime
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$days} days");
    }
}
