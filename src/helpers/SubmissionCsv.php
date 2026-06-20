<?php

namespace fabianhaef\simpleform\helpers;

use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\fields\ElementRelationFieldType;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\FieldTypeRegistry;

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
                $line[] = self::scalar(self::cellValue($entry));
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
                $row[$label] = self::scalar(self::cellValue($entry));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Extract a field's export value from its stored data entry. Element-relation
     * fields store live element ids; resolve those to the elements' titles
     * (multi-value cells are pipe-joined by {@see self::scalar()}), surviving
     * disabled/other-site elements and falling back to `#<id>` for any element
     * that no longer exists. All other fields export their raw stored value.
     *
     * @param mixed $entry a stored data entry ({label, type, value}) or a scalar
     */
    private static function cellValue(mixed $entry): mixed
    {
        if (!is_array($entry)) {
            return $entry;
        }

        $value = $entry['value'] ?? '';
        $type = $entry['type'] ?? null;

        if (!is_string($type) || !in_array($type, FieldTypeRegistry::RELATION_TYPES, true)) {
            return $value;
        }

        $field = Plugin::getInstance()->getFieldTypeRegistry()->getFieldType($type);
        if (!$field instanceof ElementRelationFieldType) {
            return $value;
        }

        $ids = is_array($value) ? $value : ($value === '' ? [] : [$value]);
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return '';
        }

        // Reuse the field type's read-time resolution (titles, deleted fallback).
        return array_values($field->labelsForIds($ids));
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
        return self::neutralizeFormula((string) $value);
    }

    /**
     * Neutralise CSV formula injection (CWE-1236). Submission values are
     * attacker-controlled (any public visitor) and are replayed into a CSV an
     * admin later opens in Excel/LibreOffice, where a leading =, +, -, @, tab
     * or carriage return makes the cell an executable formula. Prefixing such a
     * cell with a single quote forces the spreadsheet to treat it as text.
     */
    public static function neutralizeFormula(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
