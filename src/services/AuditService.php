<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\Editions;
use yii\base\Component;

/**
 * Append-only audit trail (#114). Records who changed what (forms, fields,
 * integrations, notifications, submission statuses). Logging is best-effort — an
 * audit failure must never break the operation being recorded.
 */
class AuditService extends Component
{
    public const ACTION_FORM_SAVE = 'form.save';
    public const ACTION_FORM_EXPORT = 'form.export';
    public const ACTION_FORM_DELETE = 'form.delete';
    public const ACTION_FORM_DUPLICATE = 'form.duplicate';
    public const ACTION_FORM_IMPORT = 'form.import';
    public const ACTION_SUBMISSION_EDIT = 'submission.edit';
    public const ACTION_SUBMISSION_STATUS = 'submission.status';
    public const ACTION_INTEGRATION_CREATE = 'integration.create';
    public const ACTION_INTEGRATION_SAVE = 'integration.save';
    public const ACTION_INTEGRATION_DELETE = 'integration.delete';
    public const ACTION_NOTIFICATION_CREATE = 'notification.create';
    public const ACTION_NOTIFICATION_SAVE = 'notification.save';
    public const ACTION_NOTIFICATION_DELETE = 'notification.delete';

    public const TARGET_FORM = 'form';
    public const TARGET_SUBMISSION = 'submission';
    public const TARGET_INTEGRATION = 'integration';
    public const TARGET_NOTIFICATION = 'notification';

    private const TABLE = '{{%simpleform_audit_log}}';

    /**
     * Record one audit entry, attributed to the current user when there is one.
     */
    public function log(string $action, string $targetType, ?int $targetId = null, string $summary = ''): void
    {
        // The audit trail is a Pro governance feature — a no-op on Solo.
        if (!Editions::can(Editions::CAP_GOVERNANCE)) {
            return;
        }

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
        if (($action ?? '') !== '') {
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
