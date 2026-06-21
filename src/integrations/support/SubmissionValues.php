<?php

namespace fabianhaef\simpleform\integrations\support;

use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\FormModel;

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
            $out[$handleByKey[$key] ?? $key] = $entry['value'];
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
            $label = $entry['label'];
            $value = $entry['value'];
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $lines[] = ($label !== '' ? $label : 'Field') . ': ' . (string) $value;
        }
        return $lines;
    }
}
