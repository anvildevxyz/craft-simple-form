<?php

namespace fabianhaef\simpleform\helpers;

use Craft;
use craft\elements\Asset;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\fields\CompositeFieldType;
use fabianhaef\simpleform\fields\ElementRelationFieldType;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\FieldTypeRegistry;

/**
 * Renders submissions to a human-friendly CSV for the Control Panel export:
 * metadata columns followed by one column per distinct field (header = the
 * field's label), with each cell the submitted value (multi-value fields
 * pipe-joined). Columns are the union across the result set so every row aligns.
 *
 * Asset-bearing fields (file, signature) store asset ids; their cells are
 * rendered as the asset URL (or an `Asset #id` reference when no public URL
 * exists), never raw base64 (#129).
 *
 * Composite fields (Name/Address) are the exception: rather than pipe-joining
 * their sub-part map into one cell, each composite **flattens** into one column
 * per stored sub-field, header `"<field label> — <sub-field label>"`.
 */
final class SubmissionCsv
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Field types whose stored value is a list of asset ids that should export
     * as asset URLs/references rather than raw ids.
     *
     * @var list<string>
     */
    private const ASSET_TYPES = ['file', 'signature'];

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
     * Render a stored data entry (`{label, type, value}`) to a single CSV cell.
     *
     * Calculation fields carry a pre-formatted `display` string
     * (prefix/decimals/suffix) preferred for the human-readable export.
     * Asset-bearing fields (file, signature) are resolved to asset URLs first via
     * {@see self::valueForExport()} so the export is a link, never raw base64 or
     * an opaque id. Element-relation fields resolve their stored element ids to
     * titles via {@see self::cellValue()}. Field types with a structured stored
     * value (e.g. Phone's `{raw, e164, country}`) then decide their own export
     * shape via {@see \fabianhaef\simpleform\fields\FieldType::exportValue()};
     * everything else falls back to the generic scalar/pipe-join. The cell is
     * always passed through formula neutralization.
     *
     * @param mixed $entry a stored data entry, or a bare value for legacy rows
     */
    private static function cell(mixed $entry): string
    {
        $type = is_array($entry) ? (string) ($entry['type'] ?? '') : '';

        // Calculation fields export their pre-formatted display string.
        if (is_array($entry) && isset($entry['display']) && is_string($entry['display'])) {
            return self::scalar($entry['display']);
        }

        // Asset fields resolve their id list to URLs before any other shaping.
        if ($type !== '' && in_array($type, self::ASSET_TYPES, true)) {
            return self::scalar(self::valueForExport($entry));
        }

        // Element-relation fields resolve their id list to element titles.
        if ($type !== '' && in_array($type, FieldTypeRegistry::RELATION_TYPES, true)) {
            return self::scalar(self::cellValue($entry));
        }

        $value = is_array($entry) ? ($entry['value'] ?? '') : $entry;

        // Stored values that {@see self::scalar()} flattens specially — a Consent
        // record ({consented, …}) or a repeater's list of row objects — must take
        // that path, not a field type's generic exportValue() (which would
        // pipe-join the record into an opaque cell).
        if (is_array($value) && (array_key_exists('consented', $value) || self::isRepeaterValue($value))) {
            return self::scalar($value);
        }

        if ($type !== '') {
            $fieldType = Plugin::getInstance()->getFieldTypeRegistry()->getFieldType($type);
            if ($fieldType !== null) {
                return self::neutralizeFormula($fieldType->exportValue($value));
            }
        }

        return self::scalar($value);
    }

    /**
     * Resolve a stored field entry to its exportable value. Asset-bearing fields
     * (file, signature) carry a list of asset ids; those become asset URLs (or an
     * `Asset #id` reference when the volume has no public URL) so the export is a
     * link, never raw base64 or an opaque id. Other fields export their value
     * verbatim.
     */
    private static function valueForExport(mixed $entry): mixed
    {
        if (!is_array($entry)) {
            return $entry;
        }

        $value = $entry['value'] ?? '';
        if (!in_array($entry['type'] ?? null, self::ASSET_TYPES, true)) {
            return $value;
        }

        $refs = [];
        foreach ((array) $value as $assetId) {
            if (!is_numeric($assetId)) {
                continue;
            }
            $refs[] = self::assetReference((int) $assetId);
        }
        return $refs;
    }

    /** The public URL for an asset, or an `Asset #id` reference as a fallback. */
    private static function assetReference(int $assetId): string
    {
        $asset = Asset::find()->id($assetId)->one();
        if ($asset instanceof Asset) {
            $url = $asset->getUrl();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return 'Asset #' . $assetId;
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
            // A consent record flattens to a clear scalar; the full
            // textVersion/textHash stay in the stored JSON.
            if (array_key_exists('consented', $value)) {
                return self::consentScalar($value);
            }
            // A repeater value is an ordered list of row objects (associative
            // arrays). It can't map to one tidy cell, so serialize the whole
            // value to JSON (lossless) — matching the PRD's v1 export shape.
            // Flat multi-value fields (e.g. file-id or checkbox arrays) stay
            // pipe-joined as before.
            if (self::isRepeaterValue($value)) {
                return self::neutralizeFormula((string) json_encode($value));
            }
            return implode('|', array_map([self::class, 'scalar'], $value));
        }
        return self::neutralizeFormula((string) $value);
    }

    /**
     * Flatten a Consent field record (#125) to a human-readable cell, e.g.
     * `Yes (2026-06-20 14:05)` or `No`. The exact timestamp comes from the
     * server-stamped `consentedAt`; full text/hash stay in the JSON.
     *
     * @param array<string, mixed> $record
     */
    private static function consentScalar(array $record): string
    {
        if (empty($record['consented'])) {
            return 'No';
        }

        $at = '';
        if (!empty($record['consentedAt']) && is_string($record['consentedAt'])) {
            try {
                $at = ' (' . (new \DateTimeImmutable($record['consentedAt']))->format('Y-m-d H:i') . ')';
            } catch (\Exception) {
                $at = '';
            }
        }

        return 'Yes' . $at;
    }

    /**
     * Whether a value is a repeater value — an ordered list whose first element
     * is an associative array (a row object). Flat multi-value arrays
     * (checkbox/file lists of scalars) are not.
     *
     * @param array<int|string, mixed> $value
     */
    private static function isRepeaterValue(array $value): bool
    {
        if ($value === [] || array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }
        return is_array($value[0]);
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
                    $fieldLabel = $entry['label'];
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
                    'label' => $entry['label'],
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

            // Non-composite cells flow through the full per-type renderer so
            // asset URLs, relation titles, calculation display strings, and
            // structured field exports (e.g. Phone) all survive.
            $values[] = self::cell($entry);
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
}
