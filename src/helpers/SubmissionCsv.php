<?php

namespace fabianhaef\simpleform\helpers;

use fabianhaef\simpleform\elements\Submission;

/**
 * Renders submissions to a human-friendly CSV for the Control Panel export:
 * metadata columns followed by one column per distinct field (header = the
 * field's label), with each cell the submitted value (multi-value fields
 * pipe-joined). Columns are the union across the result set so every row aligns.
 */
final class SubmissionCsv
{
    /**
     * @param array<int, Submission> $submissions
     */
    public static function fromSubmissions(array $submissions): string
    {
        $meta = ['ID', 'Form', 'Status', 'Submitted'];

        // First-seen order of field columns, keyed by the stored data key
        // (field_<id>) so values align even when forms differ across the set.
        $fieldCols = [];
        foreach ($submissions as $submission) {
            foreach (($submission->data ?? []) as $key => $entry) {
                if (!isset($fieldCols[$key])) {
                    $fieldCols[$key] = is_array($entry) ? (string) ($entry['label'] ?? $key) : (string) $key;
                }
            }
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_merge($meta, array_values($fieldCols)));

        foreach ($submissions as $submission) {
            $form = $submission->getForm();
            $line = [
                (string) $submission->id,
                (string) ($form?->title ?? $form?->name ?? $submission->formId),
                (string) $submission->readStatus,
                $submission->dateCreated?->format('Y-m-d H:i:s') ?? '',
            ];

            $data = $submission->data ?? [];
            foreach (array_keys($fieldCols) as $key) {
                $entry = $data[$key] ?? null;
                $value = is_array($entry) ? ($entry['value'] ?? '') : $entry;
                $line[] = self::scalar($value);
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The same data as {@see fromSubmissions()} but as associative rows (metadata
     * keys + one key per field label), for Craft's element-exporter framework
     * which formats the array to CSV/JSON/XML. Every row carries the union of
     * columns so they align.
     *
     * @param array<int, Submission> $submissions
     * @return list<array<string, string>>
     */
    public static function toRows(array $submissions): array
    {
        $fieldCols = [];
        foreach ($submissions as $submission) {
            foreach (($submission->data ?? []) as $key => $entry) {
                if (!isset($fieldCols[$key])) {
                    $fieldCols[$key] = is_array($entry) ? (string) ($entry['label'] ?? $key) : (string) $key;
                }
            }
        }

        $rows = [];
        foreach ($submissions as $submission) {
            $form = $submission->getForm();
            $row = [
                'ID' => (string) $submission->id,
                'Form' => (string) ($form->title ?? $form->name ?? $submission->formId),
                'Status' => (string) $submission->readStatus,
                'Submitted' => $submission->dateCreated?->format('Y-m-d H:i:s') ?? '',
            ];

            $data = $submission->data ?? [];
            foreach ($fieldCols as $key => $label) {
                $entry = $data[$key] ?? null;
                $value = is_array($entry) ? ($entry['value'] ?? '') : $entry;
                $row[$label] = self::scalar($value);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return '1';
        }
        if (is_array($value)) {
            return implode('|', array_map([self::class, 'scalar'], $value));
        }
        return (string) $value;
    }
}
