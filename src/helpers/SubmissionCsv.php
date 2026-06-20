<?php

namespace fabianhaef\simpleform\helpers;

use Craft;
use craft\elements\Asset;
use fabianhaef\simpleform\elements\Submission;

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
                $line[] = self::scalar(self::valueForExport($data[$key] ?? null));
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
                $row[$label] = self::scalar(self::valueForExport($data[$key] ?? null));
            }

            $rows[] = $row;
        }

        return $rows;
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
