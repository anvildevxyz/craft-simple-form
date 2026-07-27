<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\fields\AggregationKind;
use anvildev\simpleform\Plugin;
use yii\base\Component;

/**
 * Quiz scoring (#241): turns a quiz form's submitted choice answers into a raw
 * score, max, percentage and (optional) letter grade.
 *
 * Only choice fields (select/radio/checkbox — those declaring
 * {@see AggregationKind::Choice}) are scored. Each option may be flagged
 * `correct` with a `points` weight (default 1). A field's max is the sum of its
 * correct options' weights; a respondent earns a correct option's weight for
 * selecting it. There is no negative marking. The result is computed once at
 * submit and stored on the submission, so it never shifts when the answer key
 * later changes.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class QuizScoringService extends Component
{
    /**
     * Score a submission's data against its form's answer key.
     *
     * @param array<string, mixed> $data the persisted submission data (`field_<id>` => {label, type, value})
     * @return array{score: int, maxScore: int, percentage: int|null, grade: string|null}
     */
    public function scoreSubmission(Form $form, array $data, int $siteId): array
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, $siteId);
        $registry = Plugin::getInstance()->getFieldTypeRegistry();

        $scoredFields = [];
        foreach ($fields as $field) {
            $instance = $registry->getFieldType((string) $field['type'], $field['config']);
            if ($instance === null || $instance->aggregation() !== AggregationKind::Choice) {
                continue;
            }

            $options = $field['config']['options'] ?? [];
            $scoredFields[] = [
                'key' => 'field_' . $field['id'],
                'options' => is_array($options) ? $options : [],
            ];
        }

        return $this->computeScore($scoredFields, $data, $this->parseGradeBands($form->quizGradeBands));
    }

    /**
     * Pure scoring core — no DB, no Craft — so it is directly unit-testable.
     *
     * @param list<array{key: string, options: array<int, mixed>}> $scoredFields
     * @param array<string, mixed> $data
     * @param list<array{min: int, label: string}> $bands grade bands, highest threshold first
     * @return array{score: int, maxScore: int, percentage: int|null, grade: string|null}
     */
    public function computeScore(array $scoredFields, array $data, array $bands = []): array
    {
        $score = 0;
        $maxScore = 0;

        foreach ($scoredFields as $field) {
            $selected = $this->selectedValues($data[$field['key']] ?? null);

            foreach ($field['options'] as $option) {
                if (!is_array($option) || empty($option['correct'])) {
                    continue;
                }
                $weight = $this->weight($option);
                $maxScore += $weight;
                $value = isset($option['value']) ? (string) $option['value'] : '';
                if (in_array($value, $selected, true)) {
                    $score += $weight;
                }
            }
        }

        $percentage = $maxScore > 0 ? (int) round($score / $maxScore * 100) : null;

        return [
            'score' => $score,
            'maxScore' => $maxScore,
            'percentage' => $percentage,
            'grade' => $percentage !== null ? $this->gradeFor($percentage, $bands) : null,
        ];
    }

    /**
     * Parse the author's grade-band text — one band per line as
     * `<minPercent> <label>` (e.g. `90 Excellent`) — into a list sorted from the
     * highest threshold down. Lines without a leading integer are ignored.
     *
     * @return list<array{min: int, label: string}>
     */
    public function parseGradeBands(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $bands = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (!preg_match('/^\s*(\d{1,3})\s+(.+?)\s*$/', $line, $m)) {
                continue;
            }
            $bands[] = ['min' => min(100, (int) $m[1]), 'label' => $m[2]];
        }

        usort($bands, static fn(array $a, array $b): int => $b['min'] <=> $a['min']);
        return $bands;
    }

    /**
     * The label of the highest band whose threshold the percentage meets, or
     * null when no band applies.
     *
     * @param list<array{min: int, label: string}> $bands highest threshold first
     */
    private function gradeFor(int $percentage, array $bands): ?string
    {
        foreach ($bands as $band) {
            if ($percentage >= $band['min']) {
                return $band['label'];
            }
        }
        return null;
    }

    /**
     * The respondent's selected option values for a field, as a list of strings.
     * Handles both single-choice (scalar) and multi-choice (array) stored values.
     *
     * @param mixed $entry the field's persisted `{label, type, value}` entry
     * @return list<string>
     */
    private function selectedValues(mixed $entry): array
    {
        $value = is_array($entry) && array_key_exists('value', $entry) ? $entry['value'] : $entry;
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];
        $out = [];
        foreach ($values as $v) {
            if ($v !== null && $v !== '') {
                $out[] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * An option's point weight: its configured `points` clamped to ≥ 0, or the
     * default 1 when unset or non-positive.
     *
     * @param array<string, mixed> $option
     */
    private function weight(array $option): int
    {
        $points = isset($option['points']) && is_numeric($option['points']) ? (int) $option['points'] : 1;
        return max(0, $points) ?: 1;
    }
}
