<?php

namespace anvildev\simpleform\integrations\support;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\models\FormModel;

/**
 * Shared read helpers that turn a submission's stored `field_<id> => {label,
 * type, value}` data into connector-friendly shapes. Used by the Webhook and
 * chat connectors so handle/label resolution lives in one place.
 *
 * @phpstan-import-type SubmissionData from Submission
 */
final class SubmissionValues
{
    /**
     * Extract the stored value from one submission-data entry. An entry is either
     * the `{label, type, value}` shape (return its `value`, or `$default` when the
     * value is absent) or a raw scalar (returned as-is). Single-sources the
     * knowledge of that stored shape; callers pass `$default` (`''` vs `null`) to
     * keep their own missing-value behaviour.
     */
    public static function value(mixed $entry, mixed $default = null): mixed
    {
        return is_array($entry) ? ($entry['value'] ?? $default) : $entry;
    }

    /**
     * Extract the stored label from one submission-data entry, or `''` for a raw
     * legacy scalar row that carries no label. Counterpart to {@see value()}.
     */
    public static function label(mixed $entry): string
    {
        return is_array($entry) ? (string) ($entry['label'] ?? '') : '';
    }

    /**
     * Flatten the submission to a `handle => value` map. Falls back to the raw
     * `field_<id>` key when the field handle can't be resolved.
     *
     * @return array<string, mixed>
     */
    public static function byHandle(Submission $submission): array
    {
        $handleByKey = [];
        $form = $submission->getForm();
        if ($form !== null) {
            foreach ((new FormModel($form))->getFields() as $fieldId => $field) {
                $handleByKey['field_' . $fieldId] = $field->getName();
            }
        }

        $out = [];
        foreach ($submission->data ?? [] as $key => $entry) {
            $out[$handleByKey[$key] ?? $key] = self::value($entry);
        }
        return $out;
    }

    /**
     * Human-readable "Label: value" lines for a chat/notification message.
     *
     * @return list<string>
     */
    public static function labelledLines(Submission $submission): array
    {
        $lines = [];
        foreach ($submission->data ?? [] as $entry) {
            $label = self::label($entry);
            $value = self::value($entry);
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $lines[] = ($label !== '' ? $label : 'Field') . ': ' . (string) $value;
        }
        return $lines;
    }
}
