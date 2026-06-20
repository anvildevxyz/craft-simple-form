<?php

namespace fabianhaef\simpleform\services;

use craft\db\Query;
use craft\helpers\Db;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\integrations\DispatchStatus;
use yii\base\Component;

/**
 * Aggregate read-only stats for the Submissions analytics dashboard (#111).
 * Counts go through the Submission element query so they agree with the index
 * listing (raw siteId-column counts can diverge from the element's site).
 */
class ReportsService extends Component
{
    /**
     * Submission counts per read status (plus total) for a site, optionally one form.
     *
     * @return array{total: int, new: int, read: int, archived: int, spam: int}
     */
    public function statusBreakdown(int $siteId, ?int $formId = null): array
    {
        $count = function(?string $status) use ($siteId, $formId): int {
            $query = Submission::find()->siteId($siteId);
            if ($formId) {
                $query->formId($formId);
            }
            if ($status !== null) {
                $query->status($status);
            }
            return (int) $query->count();
        };

        return [
            'total' => $count(null),
            'new' => $count(SubmissionStatus::NEW),
            'read' => $count(SubmissionStatus::READ),
            'archived' => $count(SubmissionStatus::ARCHIVED),
            'spam' => $count(SubmissionStatus::SPAM),
        ];
    }

    /**
     * The spam vs ham split (spam count, non-spam count) for a site/form.
     *
     * @return array{spam: int, ham: int}
     */
    public function spamRate(int $siteId, ?int $formId = null): array
    {
        $breakdown = $this->statusBreakdown($siteId, $formId);
        return [
            'spam' => $breakdown['spam'],
            'ham' => $breakdown['total'] - $breakdown['spam'],
        ];
    }

    /**
     * Zero-filled daily submission counts for the last N days (ascending).
     *
     * @return list<array{date: string, count: int}>
     */
    public function submissionsPerDay(int $siteId, int $days = 30, ?int $formId = null): array
    {
        $days = max(1, $days);
        $since = (new \DateTime("today -{$days} days", new \DateTimeZone('UTC')));

        $query = (new Query())
            ->select(['d' => 'DATE([[dateCreated]])', 'c' => 'COUNT(*)'])
            ->from('{{%simpleform_submissions}}')
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)])
            ->groupBy(['d']);
        if ($formId) {
            $query->andWhere(['formId' => $formId]);
        }

        $counts = [];
        foreach ($query->all() as $row) {
            $counts[(string) $row['d']] = (int) $row['c'];
        }

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = (new \DateTime("today -{$i} days", new \DateTimeZone('UTC')))->format('Y-m-d');
            $series[] = ['date' => $day, 'count' => $counts[$day] ?? 0];
        }

        return $series;
    }

    /**
     * Submission totals grouped by form, highest first.
     *
     * @return list<array{formId: int, name: string, count: int}>
     */
    public function perFormTotals(int $siteId): array
    {
        $totals = [];
        foreach (Form::find()->siteId($siteId)->all() as $form) {
            $totals[] = [
                'formId' => (int) $form->id,
                'name' => (string) ($form->title ?? $form->name),
                'count' => (int) Submission::find()->siteId($siteId)->formId((int) $form->id)->count(),
            ];
        }

        usort($totals, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        return $totals;
    }

    /**
     * Integration dispatch outcomes across the log (success/failed/pending).
     *
     * @return array{success: int, failed: int, pending: int}
     */
    public function dispatchHealth(): array
    {
        $rows = (new Query())
            ->select(['status', 'c' => 'COUNT(*)'])
            ->from('{{%simpleform_integration_logs}}')
            ->groupBy(['status'])
            ->all();

        $health = [
            DispatchStatus::SUCCESS => 0,
            DispatchStatus::FAILED => 0,
            DispatchStatus::PENDING => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($health[$status])) {
                $health[$status] = (int) $row['c'];
            }
        }

        return $health;
    }
}
