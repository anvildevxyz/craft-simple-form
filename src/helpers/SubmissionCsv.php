<?php

namespace fabianhaef\simpleform\helpers;

use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\fields\CompositeFieldType;
use fabianhaef\simpleform\Plugin;

/**
 * Renders submissions to a human-friendly CSV for the Control Panel export:
 * metadata columns followed by one column per distinct field (header = the
 * field's label), with each cell the submitted value (multi-value fields
 * pipe-joined).
 *
 * Composite fields (Name/Address) are the exception: rather than pipe-joining
 * their sub-part map into one cell, each composite **flattens** into one column
 * per stored sub-field, header `"<field label> — <sub-field label>"`. Columns
 * are the union across the result set so every row aligns.
 */
final class SubmissionCsv
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @param array<int, Submission> $submissions
     */
    public static function fromSubmissions(array $submissions): string
    {
        $meta = ['ID', 'Form', 'Status', 'Submitted'];
        $columns = self::discoverColumns($submissions);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        $header = $meta;
        foreach ($columns as $col) {
            $header[] = $col['label'];
        }
        fputcsv($handle, $header);

        foreach ($submissions as $submission) {
            $form = $submission->getForm();
            $line = [
                (string) $submission->id,
                (string) ($form?->title ?? $form?->name ?? $submission->formId),
                (string) $submission->readStatus,
                $submission->dateCreated?->format('Y-m-d H:i:s') ?? '',
            ];

            foreach (self::rowValues($submission, $columns) as $value) {
                $line[] = $value;
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
     * keys + one key per field/sub-field label), for Craft's element-exporter
     * framework which formats the array to CSV/JSON/XML. Every row carries the
     * union of columns so they align.
     *
     * @param array<int, Submission> $submissions
     * @return list<array<string, string>>
     */
    public static function toRows(array $submissions): array
    {
        $columns = self::discoverColumns($submissions);

        $rows = [];
        foreach ($submissions as $submission) {
            $form = $submission->getForm();
            $row = [
                'ID' => (string) $submission->id,
                'Form' => (string) ($form->title ?? $form->name ?? $submission->formId),
                'Status' => (string) $submission->readStatus,
                'Submitted' => $submission->dateCreated?->format('Y-m-d H:i:s') ?? '',
            ];

            $values = self::rowValues($submission, $columns);
            foreach ($columns as $i => $col) {
                $row[$col['label']] = $values[$i];
            }

            $rows[] = $row;
        }

        return $rows;
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

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Build the first-seen-ordered list of export columns across the result set.
     * A plain field contributes one column (`['key' => 'field_<id>', 'label' =>
     * …]`); a composite field contributes one column per stored sub-field
     * (`['key' => 'field_<id>', 'sub' => '<subKey>', 'label' => '<field> —
     * <sub>']`). The union across submissions keeps mixed forms aligned.
     *
     * @param array<int, Submission> $submissions
     * @return list<array{key: string, sub: ?string, label: string}>
     */
    private static function discoverColumns(array $submissions): array
    {
        $columns = [];
        $seen = [];

        foreach ($submissions as $submission) {
            foreach (($submission->data ?? []) as $key => $entry) {
                $key = (string) $key;

                if (self::isComposite($entry)) {
                    $fieldLabel = (string) ($entry['label'] ?? $key);
                    foreach (self::compositeSubLabels($entry) as $sub => $subLabel) {
                        $colId = $key . '::' . $sub;
                        if (isset($seen[$colId])) {
                            continue;
                        }
                        $seen[$colId] = true;
                        $columns[] = [
                            'key' => $key,
                            'sub' => $sub,
                            'label' => $fieldLabel . ' — ' . $subLabel,
                        ];
                    }
                    continue;
                }

                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $columns[] = [
                    'key' => $key,
                    'sub' => null,
                    'label' => is_array($entry) ? (string) ($entry['label'] ?? $key) : $key,
                ];
            }
        }

        return $columns;
    }

    /**
     * The ordered scalar cell values for one submission against the discovered
     * column list (a missing field/sub-field yields an empty cell).
     *
     * @param list<array{key: string, sub: ?string, label: string}> $columns
     * @return list<string>
     */
    private static function rowValues(Submission $submission, array $columns): array
    {
        $data = $submission->data ?? [];

        $values = [];
        foreach ($columns as $col) {
            $entry = $data[$col['key']] ?? null;

            if ($col['sub'] !== null) {
                $map = is_array($entry) && is_array($entry['value'] ?? null) ? $entry['value'] : [];
                $values[] = self::scalar($map[$col['sub']] ?? '');
                continue;
            }

            $value = is_array($entry) ? ($entry['value'] ?? '') : $entry;
            $values[] = self::scalar($value);
        }

        return $values;
    }

    /**
     * Whether a stored data entry is a composite (Name/Address) whose value
     * should flatten into multiple columns rather than pipe-join into one cell.
     *
     * @param mixed $entry
     */
    private static function isComposite(mixed $entry): bool
    {
        if (!is_array($entry) || !isset($entry['type']) || !is_array($entry['value'] ?? null)) {
            return false;
        }

        $fieldType = Plugin::getInstance()
            ->getFieldTypeRegistry()
            ->getFieldType((string) $entry['type']);

        return $fieldType instanceof CompositeFieldType;
    }

    /**
     * The sub-field label map ([subKey => label]) for a composite entry. Labels
     * come from the field type's definitions (resolved/translated), keyed by the
     * sub-keys actually stored on the submission, in stored order.
     *
     * @param array<string, mixed> $entry
     * @return array<string, string>
     */
    private static function compositeSubLabels(array $entry): array
    {
        $value = is_array($entry['value'] ?? null) ? $entry['value'] : [];

        $fieldType = Plugin::getInstance()
            ->getFieldTypeRegistry()
            ->getFieldType((string) ($entry['type'] ?? ''));

        $labels = [];
        if ($fieldType instanceof CompositeFieldType) {
            foreach ($fieldType->enabledSubFields() as $key => $sub) {
                $labels[$key] = $sub['label'];
            }
        }

        // Fall back to the raw sub-key for any stored part the definitions don't
        // cover (e.g. an old submission from a since-changed config).
        $result = [];
        foreach (array_keys($value) as $sub) {
            $sub = (string) $sub;
            $result[$sub] = $labels[$sub] ?? $sub;
        }

        return $result;
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
}
