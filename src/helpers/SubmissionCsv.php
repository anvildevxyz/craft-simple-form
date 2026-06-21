<?php

namespace fabianhaef\simpleform\helpers;

use Craft;
use craft\elements\Asset;
use fabianhaef\simpleform\elements\Submission;
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
 */
final class SubmissionCsv
{
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
                $line[] = self::cell($data[$key] ?? null);
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
                $row[$label] = self::cell($data[$key] ?? null);
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
