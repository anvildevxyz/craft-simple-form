<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\fields\AggregationKind;
use anvildev\simpleform\integrations\DispatchStatus;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Aggregate read-only stats for the Submissions analytics dashboard (#111).
 * Counts go through the Submission element query so they agree with the index
 * listing (raw siteId-column counts can diverge from the element's site).
 *
 * @phpstan-type DailyCount array{date: string, count: int}
 */
class ReportsService extends Component
{
    /**
     * Cache tag for every memoized aggregate, invalidated whenever a submission
     * is saved or deleted (see {@see self::invalidateCache()}).
     */
    private const CACHE_TAG = 'simpleform-reports';

    /** Secondary time bound on cached aggregates (seconds). */
    private const CACHE_TTL = 300;

    /**
     * Discard every cached aggregate. Called on submission save/delete so the
     * dashboard/forms-index/Stats counts are never stale for more than one write.
     */
    public function invalidateCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    /**
     * Memoize an aggregate under the reports cache tag. Bypassed in devMode and
     * when Craft's cache is a dummy/disabled component, so those environments
     * always read fresh — mirrors {@see FormStructureService}.
     *
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    private function remember(string $key, callable $compute): mixed
    {
        if (!$this->cachingEnabled()) {
            return $compute();
        }

        $cache = Craft::$app->getCache();
        $full = self::CACHE_TAG . ':' . $key;
        $cached = $cache->get($full);
        if ($cached !== false) {
            return $cached;
        }

        $value = $compute();
        $cache->set($full, $value, self::CACHE_TTL, new TagDependency(['tags' => [self::CACHE_TAG]]));

        return $value;
    }

    /**
     * Whether aggregate memoization is active. Off in devMode and when Craft's
     * cache is a dummy/disabled component, so those environments always read
     * fresh — mirrors {@see FormStructureService}.
     */
    protected function cachingEnabled(): bool
    {
        return !App::devMode() && !(Craft::$app->getCache() instanceof \yii\caching\DummyCache);
    }

    /**
     * Submission counts per read status (plus total) for a site, optionally one form.
     *
     * @return array{total: int, new: int, read: int, archived: int, spam: int}
     */
    public function statusBreakdown(int $siteId, ?int $formId = null): array
    {
        return $this->remember('status:' . $siteId . ':' . ($formId ?? 'all'), function() use ($siteId, $formId): array {
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
        });
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
     * @return list<DailyCount>
     */
    public function submissionsPerDay(int $siteId, int $days = 30, ?int $formId = null): array
    {
        $days = max(1, $days);
        $utc = new \DateTimeZone('UTC');
        // Key on today's date so the rolling window rolls over at day boundaries
        // even before the TTL lapses.
        $today = (new \DateTime('now', $utc))->format('Y-m-d');
        $key = 'perday:' . $siteId . ':' . $days . ':' . ($formId ?? 'all') . ':' . $today;

        return $this->remember($key, function() use ($siteId, $days, $formId, $utc): array {
            $since = new \DateTime("today -{$days} days", $utc);

            $query = (new Query())
                ->select(['d' => 'DATE([[dateCreated]])', 'c' => 'COUNT(*)'])
                ->from('{{%simpleform_submissions}}')
                ->where(['siteId' => $siteId])
                ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)])
                ->groupBy(['d']);
            if ($formId) {
                $query->andWhere(['formId' => $formId]);
            }

            $counts = array_map('intval', array_column($query->all(), 'c', 'd'));

            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $day = (new \DateTime("today -{$i} days", $utc))->format('Y-m-d');
                $series[] = ['date' => $day, 'count' => $counts[$day] ?? 0];
            }

            return $series;
        });
    }

    /**
     * Submission totals grouped by form, highest first.
     *
     * @return list<array{formId: int, name: string, count: int}>
     */
    public function perFormTotals(int $siteId): array
    {
        return $this->remember('perform:' . $siteId, function() use ($siteId): array {
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
        });
    }

    /**
     * Per-field numeric stats for every rating/opinion scale field on a form:
     * the response count, average (rounded to one decimal), and a per-value
     * distribution (count keyed by the integer scale point, in ascending order).
     *
     * Submission values are stored as ints (see {@see \anvildev\simpleform\fields\FieldType::normalizeValue()}),
     * so they aggregate numerically. A non-numeric or out-of-set stored value is
     * skipped defensively.
     *
     * @return list<array{key: string, label: string, type: string, count: int, average: float, distribution: array<int, int>}>
     */
    public function scaleBreakdown(int $siteId, int $formId): array
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet($formId, $siteId);
        $scaleFields = [];
        foreach ($fields as $field) {
            if (in_array($field['type'], FieldTypeRegistry::SCALE_TYPES, true)) {
                $scaleFields['field_' . $field['id']] = [
                    'label' => $field['label'],
                    'type' => $field['type'],
                ];
            }
        }

        if ($scaleFields === []) {
            return [];
        }

        // Accumulate sum + per-value counts per field across the form's
        // non-spam submissions, reading each field's stored integer value.
        $keys = array_keys($scaleFields);
        $sums = array_fill_keys($keys, 0);
        $counts = array_fill_keys($keys, 0);
        $dist = array_fill_keys($keys, []);

        // Stream in bounded batches rather than ->all() so a form with millions
        // of submissions never hydrates its whole history into memory at once.
        $submissions = Submission::find()
            ->siteId($siteId)
            ->formId($formId)
            ->status(null)
            ->each(500);

        foreach ($submissions as $submission) {
            $data = $submission->data ?? [];
            foreach ($scaleFields as $key => $meta) {
                $entry = $data[$key] ?? null;
                $value = is_array($entry) ? ($entry['value'] ?? null) : null;
                if (!is_int($value) && !(is_string($value) && $value !== '' && (string) (int) $value === $value)) {
                    continue;
                }
                $value = (int) $value;
                $sums[$key] += $value;
                $counts[$key]++;
                $dist[$key][$value] = ($dist[$key][$value] ?? 0) + 1;
            }
        }

        $result = [];
        foreach ($scaleFields as $key => $meta) {
            ksort($dist[$key]);
            $result[] = [
                'key' => $key,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'count' => $counts[$key],
                'average' => $counts[$key] > 0 ? round($sums[$key] / $counts[$key], 1) : 0.0,
                'distribution' => $dist[$key],
            ];
        }

        return $result;
    }

    /**
     * Per-field survey report for one form (#240): every input field's response
     * count, plus per-option counts (choice fields) or a value distribution +
     * average (rating/opinion scale fields). Read-only over the stored
     * submission data — no migration, no new field types.
     *
     * Each field type names its own treatment via
     * {@see \anvildev\simpleform\fields\FieldType::aggregation()}, so the report
     * derives from the field set with no hardcoded type list. Layout blocks and
     * unknown types are skipped. Spam is excluded; an optional inclusive
     * YYYY-MM-DD `dateFrom`/`dateTo` scopes the window. Scoped to one site.
     *
     * Each row carries `key`, `label`, `type`, `kind` and `count`; choice rows
     * add an ordered `options` list (value/label/count), scale rows add
     * `average` + a zero-filled `distribution`.
     *
     * @return list<array<string, mixed>>
     */
    public function fieldReport(int $siteId, int $formId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet($formId, $siteId);
        $registry = Plugin::getInstance()->getFieldTypeRegistry();

        $meta = [];
        foreach ($fields as $field) {
            $instance = $registry->getFieldType((string) $field['type'], $field['config']);
            // Skip presentational layout blocks and any unknown/third-party type
            // that didn't register — the report only covers value-bearing fields.
            if ($instance === null || !$instance->isInput()) {
                continue;
            }

            $kind = $instance->aggregation();
            $entry = [
                'key' => 'field_' . $field['id'],
                'label' => (string) $field['label'],
                'type' => (string) $field['type'],
                'kind' => $kind->value,
            ];

            if ($kind === AggregationKind::Choice) {
                // Layer the per-site option-label overrides over the base labels
                // so the report reads the way the visitor saw the form.
                $options = $instance->aggregationOptions();
                $overrides = is_array($field['optionLabels'] ?? null) ? $field['optionLabels'] : [];
                foreach ($overrides as $value => $label) {
                    if (isset($options[$value]) && is_string($label) && $label !== '') {
                        $options[$value] = $label;
                    }
                }
                $entry['options'] = $options;
            } elseif ($kind === AggregationKind::Scale) {
                $entry['points'] = $instance->aggregationScalePoints();
            }

            $meta[] = $entry;
        }

        if ($meta === []) {
            return [];
        }

        return $this->aggregateFieldReport($meta, $this->reportDataRows($siteId, $formId, $dateFrom, $dateTo));
    }

    /**
     * The non-spam response count for a form on a site within the optional
     * inclusive YYYY-MM-DD range — the headline total for the survey report,
     * matching the window {@see self::fieldReport()} aggregates over.
     */
    public function responseCount(int $siteId, int $formId, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        return (int) $this->reportBaseQuery($siteId, $formId, $dateFrom, $dateTo)->count();
    }

    /**
     * Accumulate the per-field report from already-decoded submission payloads.
     * Pure (no DB, no Craft) so it is directly unit-testable: feed it the field
     * meta from {@see self::fieldReport()} plus a list of `data` payloads and it
     * returns the rendered report rows.
     *
     * Each meta row carries `key`, `label`, `type` and `kind`; choice rows add
     * an `options` value=>label map, scale rows a `points` int list.
     *
     * @param list<array<string, mixed>> $meta per-field meta from {@see self::fieldReport()}
     * @param iterable<array<string, mixed>> $rows decoded submission `data` payloads
     * @return list<array<string, mixed>>
     */
    public function aggregateFieldReport(array $meta, iterable $rows): array
    {
        $acc = [];
        foreach ($meta as $m) {
            $acc[$m['key']] = ['count' => 0, 'sum' => 0, 'options' => [], 'dist' => []];
        }

        foreach ($rows as $data) {
            foreach ($meta as $m) {
                $key = $m['key'];
                $entry = $data[$key] ?? null;
                // Stored shape is {label, type, value}; tolerate a bare value too.
                $value = is_array($entry) && array_key_exists('value', $entry) ? $entry['value'] : $entry;

                // A field counts as "answered" only with a non-empty value.
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                if ($m['kind'] === AggregationKind::Choice->value) {
                    // A checkbox stores a list; select/radio store a scalar.
                    $selected = is_array($value) ? $value : [$value];
                    $picked = false;
                    foreach ($selected as $v) {
                        if ($v === null || $v === '') {
                            continue;
                        }
                        $v = (string) $v;
                        $acc[$key]['options'][$v] = ($acc[$key]['options'][$v] ?? 0) + 1;
                        $picked = true;
                    }
                    if ($picked) {
                        $acc[$key]['count']++;
                    }
                } elseif ($m['kind'] === AggregationKind::Scale->value) {
                    // Values persist as ints; a forged non-integer is skipped.
                    if (!is_int($value) && !(is_string($value) && $value !== '' && (string) (int) $value === $value)) {
                        continue;
                    }
                    $value = (int) $value;
                    $acc[$key]['sum'] += $value;
                    $acc[$key]['count']++;
                    $acc[$key]['dist'][$value] = ($acc[$key]['dist'][$value] ?? 0) + 1;
                } else {
                    $acc[$key]['count']++;
                }
            }
        }

        $result = [];
        foreach ($meta as $m) {
            $key = $m['key'];
            $a = $acc[$key];
            $row = [
                'key' => $key,
                'label' => $m['label'],
                'type' => $m['type'],
                'kind' => $m['kind'],
                'count' => $a['count'],
            ];

            if ($m['kind'] === AggregationKind::Choice->value) {
                $counts = $a['options'];
                $options = [];
                // Configured options first, in authored order, zero-filled.
                foreach (($m['options'] ?? []) as $value => $label) {
                    $value = (string) $value;
                    $options[] = [
                        'value' => $value,
                        'label' => (string) $label,
                        'count' => $counts[$value] ?? 0,
                    ];
                    unset($counts[$value]);
                }
                // Then any stored value no longer in the option set (renamed or
                // removed since), labelled by its raw value.
                foreach ($counts as $value => $count) {
                    $options[] = ['value' => (string) $value, 'label' => (string) $value, 'count' => $count];
                }
                $row['options'] = $options;
            } elseif ($m['kind'] === AggregationKind::Scale->value) {
                $dist = [];
                foreach (($m['points'] ?? []) as $point) {
                    $dist[(int) $point] = $a['dist'][(int) $point] ?? 0;
                    unset($a['dist'][(int) $point]);
                }
                // Defensive: any out-of-range stored value still gets shown.
                foreach ($a['dist'] as $point => $count) {
                    $dist[(int) $point] = $count;
                }
                ksort($dist);
                $row['average'] = $a['count'] > 0 ? round($a['sum'] / $a['count'], 1) : 0.0;
                $row['distribution'] = $dist;
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * The decoded `data` payloads for a form's non-spam submissions on a site,
     * optionally within the inclusive date range. Reads only the JSON column via
     * a batched cursor ({@see Query::each()}) so it stays light on large
     * submission sets — no element hydration.
     *
     * @return iterable<array<string, mixed>>
     */
    private function reportDataRows(int $siteId, int $formId, ?string $dateFrom, ?string $dateTo): iterable
    {
        $query = $this->reportBaseQuery($siteId, $formId, $dateFrom, $dateTo)->select(['data']);

        foreach ($query->each() as $row) {
            $data = $row['data'];
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            yield is_array($data) ? $data : [];
        }
    }

    /**
     * The shared submissions query for the survey report: one site, one form,
     * spam excluded, scoped to the optional inclusive YYYY-MM-DD range. Date
     * literals are shape-validated (CWE-20) before being compared.
     *
     * @return Query<int, array<string, mixed>>
     */
    private function reportBaseQuery(int $siteId, int $formId, ?string $dateFrom, ?string $dateTo): Query
    {
        $query = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['siteId' => $siteId, 'formId' => $formId])
            ->andWhere(['not', ['readStatus' => SubmissionStatus::SPAM]]);

        if (is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $query->andWhere(['>=', 'dateCreated', "$dateFrom 00:00:00"]);
        }
        if (is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $query->andWhere(['<=', 'dateCreated', "$dateTo 23:59:59"]);
        }

        return $query;
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
            if (isset($health[(string) $row['status']])) {
                $health[(string) $row['status']] = (int) $row['c'];
            }
        }

        return $health;
    }
}
