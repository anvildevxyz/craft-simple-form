<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;

/**
 * Append-only audit trail (#114). Records who changed what (forms, fields,
 * integrations, notifications, submission statuses). Logging is best-effort — an
 * audit failure must never break the operation being recorded.
 */
class AuditService extends Component
{
    private const TABLE = '{{%simpleform_audit_log}}';

    /**
     * Record one audit entry, attributed to the current user when there is one.
     */
    public function log(string $action, string $targetType, ?int $targetId = null, string $summary = ''): void
    {
        try {
            $now = Db::prepareDateForDb(new \DateTime());
            Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
                'userId' => Craft::$app->getUser()->getId(),
                'action' => $action,
                'targetType' => $targetType,
                'targetId' => $targetId,
                'summary' => StringHelper::safeTruncate($summary, 1000),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            Craft::warning('Audit log write failed: ' . $e->getMessage(), 'simple-form');
        }
    }

    /**
     * Recent audit rows, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100, ?string $action = null): array
    {
        $query = (new Query())
            ->from(self::TABLE)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);
        if ($action !== null && $action !== '') {
            $query->where(['action' => $action]);
        }

        return $query->all();
    }

    /**
     * Delete audit rows older than $days. No-op when $days <= 0.
     */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = (new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$days} days");

        return (int) Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }
}
