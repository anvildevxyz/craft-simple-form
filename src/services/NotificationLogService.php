<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Form;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yii\base\Component;

/**
 * Append-only log of outbound notification emails (#143 follow-up). Each send
 * attempt is recorded so operators can review delivery history in the CP.
 * Logging is best-effort — a log write failure must never break email delivery.
 *
 * @author Fabian Haefliger
 */
class NotificationLogService extends Component
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    private const TABLE = '{{%simpleform_notification_logs}}';

    /**
     * Record one send attempt.
     *
     * @param list<string> $recipients
     */
    public function logSend(
        int $formId,
        ?int $submissionId,
        ?int $notificationId,
        ?string $notificationName,
        bool $success,
        array $recipients,
        string $subject,
        string $message = '',
    ): void {
        try {
            $now = Db::prepareDateForDb(new \DateTime());
            Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
                'formId' => $formId,
                'submissionId' => $submissionId,
                'notificationId' => $notificationId,
                'notificationName' => StringHelper::safeTruncate($notificationName ?? '', 255) ?: null,
                'status' => $success ? self::STATUS_SUCCESS : self::STATUS_FAILED,
                'recipients' => Json::encode(array_values($recipients)),
                'subject' => StringHelper::safeTruncate($subject, 255),
                'message' => StringHelper::safeTruncate($message, 1000),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            Craft::warning('Notification log write failed: ' . $e->getMessage(), 'simple-form');
        }
    }

    /**
     * Recent send-log rows, newest first, enriched for CP display.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(
        int $limit = 100,
        ?int $formId = null,
        ?string $status = null,
    ): array {
        $query = (new Query())
            ->from(['l' => self::TABLE])
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC])
            ->limit($limit);

        if ($formId !== null) {
            $query->andWhere(['l.formId' => $formId]);
        }
        if ($status !== null && in_array($status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true)) {
            $query->andWhere(['l.status' => $status]);
        }

        $rows = $query->all();
        if ($rows === []) {
            return [];
        }

        $formNames = $this->_formNames(array_unique(array_map('intval', array_column($rows, 'formId'))));
        $formatter = Craft::$app->getFormatter();

        return array_map(function(array $row) use ($formNames, $formatter): array {
            $recipients = Json::decodeIfJson($row['recipients'] ?? '[]');
            if (!is_array($recipients)) {
                $recipients = [];
            }

            $dateCreated = $row['dateCreated'] ?? null;
            $dateDisplay = is_string($dateCreated) && $dateCreated !== ''
                ? $formatter->asDatetime($dateCreated, 'short')
                : '—';

            return [
                ...$row,
                'formName' => $formNames[(int) $row['formId']] ?? ('#' . $row['formId']),
                'recipients' => $recipients,
                'recipientsDisplay' => implode(', ', array_map('strval', $recipients)),
                'dateDisplay' => $dateDisplay,
            ];
        }, $rows);
    }

    /**
     * @return array{total: int, success: int, failed: int}
     */
    public function stats(?int $formId = null): array
    {
        $query = (new Query())->from(self::TABLE);
        if ($formId !== null) {
            $query->where(['formId' => $formId]);
        }

        $total = (int) (clone $query)->count();
        $success = (int) (clone $query)->andWhere(['status' => self::STATUS_SUCCESS])->count();
        $failed = (int) (clone $query)->andWhere(['status' => self::STATUS_FAILED])->count();

        return ['total' => $total, 'success' => $success, 'failed' => $failed];
    }

    public function count(): int
    {
        return (int) (new Query())->from(self::TABLE)->count();
    }

    /**
     * Send-log rows for one submission, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function getForSubmission(int $submissionId): array
    {
        $rows = (new Query())
            ->from(['l' => self::TABLE])
            ->where(['l.submissionId' => $submissionId])
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC])
            ->limit(50)
            ->all();

        if ($rows === []) {
            return [];
        }

        $formNames = $this->_formNames(array_unique(array_map('intval', array_column($rows, 'formId'))));
        $formatter = Craft::$app->getFormatter();

        return array_map(function(array $row) use ($formNames, $formatter): array {
            $recipients = Json::decodeIfJson($row['recipients'] ?? '[]');
            if (!is_array($recipients)) {
                $recipients = [];
            }

            $dateCreated = $row['dateCreated'] ?? null;
            $dateDisplay = is_string($dateCreated) && $dateCreated !== ''
                ? $formatter->asDatetime($dateCreated, 'short')
                : '—';

            return [
                ...$row,
                'formName' => $formNames[(int) $row['formId']] ?? ('#' . $row['formId']),
                'recipients' => $recipients,
                'recipientsDisplay' => implode(', ', array_map('strval', $recipients)),
                'dateDisplay' => $dateDisplay,
            ];
        }, $rows);
    }

    /**
     * Delete send-log rows older than $days. No-op when $days <= 0.
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

    /**
     * @param list<int> $formIds
     * @return array<int, string>
     */
    private function _formNames(array $formIds): array
    {
        if ($formIds === []) {
            return [];
        }

        $names = [];
        foreach (Form::find()->siteId('*')->id($formIds)->status(null)->all() as $form) {
            $names[(int) $form->id] = (string) ($form->title ?? $form->name);
        }

        return $names;
    }
}
