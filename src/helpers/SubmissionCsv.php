<?php

namespace anvildev\simpleform\helpers;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\fields\CompositeFieldType;
use anvildev\simpleform\fields\ElementRelationFieldType;
use anvildev\simpleform\fields\FieldType;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\FieldTypeRegistry;
use Craft;
use craft\elements\Asset;

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
 *
 * @phpstan-type CsvColumn array{key: string, sub: ?string, label: string}
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
    private const ASSET_TYPES = FieldTypeRegistry::ASSET_TYPES;

    /**
     * Per-export id => resolved asset reference (public URL, or `Asset #id` when
     * the volume has no public URL / the asset is gone). Seeded once per export by
     * {@see self::warmAssetCache()} so asset cells resolve from a single batched
     * query instead of one lookup each. Null when not yet warmed.
     *
     * @var array<int, string>|null
     */
    private static ?array $assetUrlCache = null;

    /**
     * Process-lifetime memo of config-less field-type instances keyed by type.
     * The export always resolves field types without config, and the instances
     * are only used for stateless `exportValue()`/`instanceof` checks, so one
     * instance per type is reused across every cell rather than re-instantiated.
     *
     * @var array<string, FieldType|null>
     */
    private static array $fieldTypeMemo = [];

    /**
     * Render the result set to a CSV string.
     *
     * When $onlyColumns is a non-empty list of header labels, only those columns
     * are emitted (metadata + field columns alike), preserving natural order;
     * null or an empty list keeps the default behaviour of every column (#317).
     * Formula neutralization still applies to every emitted cell.
     *
     * @param array<int, Submission> $submissions
     * @param list<string>|null $onlyColumns header labels to keep, or null for all
     */
    public static function fromSubmissions(array $submissions, ?array $onlyColumns = null): string
    {
        self::warmAssetCache($submissions);
        $columns = self::discoverColumns($submissions);
        $includeQuiz = self::includesQuiz($submissions);
        $includeAttribution = self::includesAttribution($submissions);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        $header = ['ID', 'Form', 'Status', 'Submitted'];
        if ($includeQuiz) {
            $header = [...$header, ...self::quizHeaders()];
        }
        if ($includeAttribution) {
            $header = [...$header, ...self::attributionHeaders()];
        }
        $fullHeader = [...$header, ...array_column($columns, 'label')];
        $mask = self::columnMask($fullHeader, $onlyColumns);
        fputcsv($handle, self::applyMask($fullHeader, $mask));

        foreach ($submissions as $submission) {
            $form = $submission->getForm();
            $meta = [
                (string) $submission->id,
                (string) ($form?->title ?? $form?->name ?? $submission->formId),
                (string) $submission->readStatus,
                $submission->dateCreated?->format('Y-m-d H:i:s') ?? '',
            ];
            if ($includeQuiz) {
                $meta = [...$meta, ...self::quizValues($submission)];
            }
            if ($includeAttribution) {
                $meta = [...$meta, ...self::attributionValues($submission)];
            }
            fputcsv($handle, self::applyMask([...$meta, ...self::rowValues($submission, $columns)], $mask));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The ordered list of every column header the export would emit for the
     * result set (metadata + field/sub-field labels), used to populate the
     * export UI's column picker so the offered columns exactly match what the
     * emitter can produce (#317).
     *
     * @param array<int, Submission> $submissions
     * @return list<string>
     */
    public static function availableColumns(array $submissions): array
    {
        $header = ['ID', 'Form', 'Status', 'Submitted'];
        if (self::includesQuiz($submissions)) {
            $header = [...$header, ...self::quizHeaders()];
        }
        if (self::includesAttribution($submissions)) {
            $header = [...$header, ...self::attributionHeaders()];
        }

        return [...$header, ...array_column(self::discoverColumns($submissions), 'label')];
    }

    /**
     * The same data as {@see fromSubmissions()} but as associative rows (metadata
     * keys + one key per field/sub-field label), for Craft's element-exporter
     * framework which formats the array to CSV/JSON/XML. Every row carries the
     * union of columns so they align.
     *
     * When $onlyColumns is a non-empty list of header labels, each row is reduced
     * to those keys (natural order preserved); null or an empty list keeps every
     * column (#317). Formula neutralization still applies to every cell.
     *
     * @param array<int, Submission> $submissions
     * @param list<string>|null $onlyColumns header labels to keep, or null for all
     * @return list<array<string, string>>
     */
    public static function toRows(array $submissions, ?array $onlyColumns = null): array
    {
        self::warmAssetCache($submissions);
        $columns = self::discoverColumns($submissions);
        $includeQuiz = self::includesQuiz($submissions);
        $includeAttribution = self::includesAttribution($submissions);
        $keep = ($onlyColumns === null || $onlyColumns === []) ? null : array_flip($onlyColumns);

        $rows = [];
        foreach ($submissions as $submission) {
            $form = $submission->getForm();
            $row = [
                'ID' => (string) $submission->id,
                'Form' => (string) ($form->title ?? $form->name ?? $submission->formId),
                'Status' => (string) $submission->readStatus,
                'Submitted' => $submission->dateCreated?->format('Y-m-d H:i:s') ?? '',
            ];

            if ($includeQuiz) {
                $row += array_combine(self::quizHeaders(), self::quizValues($submission));
            }

            if ($includeAttribution) {
                $row += array_combine(self::attributionHeaders(), self::attributionValues($submission));
            }

            $values = self::rowValues($submission, $columns);
            foreach ($columns as $i => $col) {
                $row[$col['label']] = $values[$i];
            }

            if ($keep !== null) {
                $row = array_intersect_key($row, $keep);
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
     * shape via {@see \anvildev\simpleform\fields\FieldType::exportValue()};
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
        if (in_array($type, self::ASSET_TYPES, true)) {
            return self::scalar(self::valueForExport($entry));
        }

        // Element-relation fields resolve their id list to element titles.
        if (in_array($type, FieldTypeRegistry::RELATION_TYPES, true)) {
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
            $fieldType = self::fieldTypeFor($type);
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

        return array_values(array_map(
            static fn($id): string => self::assetReference((int) $id),
            array_filter((array) $value, 'is_numeric'),
        ));
    }

    /**
     * Pre-resolve every asset referenced by the result set in one query, so the
     * per-cell {@see self::assetReference()} reads a map instead of issuing a
     * lookup per id (was an N+1 across the whole export). Re-seeded each call so a
     * batched element-exporter run scopes the cache to its current chunk.
     *
     * @param array<int, Submission> $submissions
     */
    private static function warmAssetCache(array $submissions): void
    {
        /** @var array<int, true> $ids */
        $ids = [];
        foreach ($submissions as $submission) {
            foreach (($submission->data ?? []) as $entry) {
                self::collectAssetIds($entry, $ids);
            }
        }

        /** @var array<int, string> $cache */
        $cache = [];
        if ($ids !== []) {
            /** @var array<int, Asset> $assets */
            $assets = Asset::find()->id(array_keys($ids))->indexBy('id')->all();
            foreach (array_keys($ids) as $id) {
                $id = (int) $id;
                $url = ($asset = $assets[$id] ?? null) instanceof Asset ? $asset->getUrl() : null;
                $cache[$id] = is_string($url) && $url !== '' ? $url : 'Asset #' . $id;
            }
        }

        self::$assetUrlCache = $cache;
    }

    /**
     * Collect the numeric asset ids from one stored entry into $ids (used as a
     * set: id => true). Typed `mixed` and tolerant of legacy/partial entries that
     * lack a `type` key — mirrors the per-cell resolution in {@see self::cell()}.
     *
     * @param array<int, true> $ids
     */
    private static function collectAssetIds(mixed $entry, array &$ids): void
    {
        if (!is_array($entry) || !in_array($entry['type'] ?? null, self::ASSET_TYPES, true)) {
            return;
        }
        foreach ((array) ($entry['value'] ?? []) as $id) {
            if (is_numeric($id)) {
                $ids[(int) $id] = true;
            }
        }
    }

    /**
     * A config-less field-type instance for $type, memoised for the process. The
     * export only needs stateless `exportValue()`/`instanceof` behaviour, so the
     * same instance is reused across cells rather than re-instantiated per cell.
     */
    private static function fieldTypeFor(string $type): ?FieldType
    {
        if (!array_key_exists($type, self::$fieldTypeMemo)) {
            self::$fieldTypeMemo[$type] = Plugin::getInstance()->getFieldTypeRegistry()->getFieldType($type);
        }

        return self::$fieldTypeMemo[$type];
    }

    /** The public URL for an asset, or an `Asset #id` reference as a fallback. */
    private static function assetReference(int $assetId): string
    {
        // Served from the per-export batch when warmed; any uncached id (cold
        // cache, or a path that bypassed warming) falls back to a single lookup.
        if (isset(self::$assetUrlCache[$assetId])) {
            return self::$assetUrlCache[$assetId];
        }

        $asset = Asset::find()->id($assetId)->one();
        $url = $asset instanceof Asset ? $asset->getUrl() : null;
        return is_string($url) && $url !== '' ? $url : 'Asset #' . $assetId;
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

        $field = self::fieldTypeFor($type);
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
            return implode('|', array_map(self::scalar(...), $value));
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
        // array_is_list is the allocation-free stdlib form of the 0..n-1 key
        // check (was array_keys() !== range()); empty is excluded explicitly.
        if ($value === [] || !array_is_list($value)) {
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
        // First-char membership via a needle string — no per-cell array literal.
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'" . $value;
        }
        return $value;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The ordered list of column indices to keep for the flat-CSV path, given the
     * full header row and an optional selection of header labels. Returns null
     * (keep every column) when the selection is null or empty, so the default
     * export is byte-for-byte unchanged (#317).
     *
     * @param list<string> $fullHeader
     * @param list<string>|null $onlyColumns
     * @return list<int>|null
     */
    private static function columnMask(array $fullHeader, ?array $onlyColumns): ?array
    {
        if ($onlyColumns === null || $onlyColumns === []) {
            return null;
        }

        $keep = array_flip($onlyColumns);
        $mask = [];
        foreach ($fullHeader as $i => $label) {
            if (isset($keep[$label])) {
                $mask[] = $i;
            }
        }

        return $mask;
    }

    /**
     * Reduce a flat row to the masked column indices (natural order), or return it
     * unchanged when the mask is null (no selection).
     *
     * @param list<string> $row
     * @param list<int>|null $mask
     * @return list<string>
     */
    private static function applyMask(array $row, ?array $mask): array
    {
        if ($mask === null) {
            return $row;
        }

        $out = [];
        foreach ($mask as $i) {
            $out[] = $row[$i] ?? '';
        }

        return $out;
    }

    /**
     * Whether any submission in the set carries a quiz score, gating the quiz
     * columns so a plain (non-quiz) export stays byte-for-byte as before (#241).
     *
     * @param array<int, Submission> $submissions
     */
    private static function includesQuiz(array $submissions): bool
    {
        foreach ($submissions as $submission) {
            if ($submission->quizScore !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * The quiz metadata column headers, in order. Kept as raw English to match
     * the other metadata headers (ID/Form/Status/Submitted).
     *
     * @return list<string>
     */
    private static function quizHeaders(): array
    {
        return ['Score', 'Max score', 'Percentage', 'Grade'];
    }

    /**
     * One submission's quiz cell values, aligned with {@see self::quizHeaders()}.
     * A non-quiz submission (null score) yields blanks so columns stay aligned.
     *
     * @return list<string>
     */
    private static function quizValues(Submission $submission): array
    {
        if ($submission->quizScore === null) {
            return ['', '', '', ''];
        }

        return [
            (string) $submission->quizScore,
            $submission->quizMaxScore !== null ? (string) $submission->quizMaxScore : '',
            $submission->quizPercentage !== null ? $submission->quizPercentage . '%' : '',
            (string) ($submission->quizGrade ?? ''),
        ];
    }

    /**
     * The ordered attribution map keys (= column order), shared by the header
     * and value builders (#249).
     *
     * @return list<string>
     */
    private static function attributionKeys(): array
    {
        return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer', 'landing_page'];
    }

    /**
     * Whether any submission carries captured attribution, gating the columns so
     * a result set without it stays byte-for-byte as before (#249).
     *
     * @param array<int, Submission> $submissions
     */
    private static function includesAttribution(array $submissions): bool
    {
        foreach ($submissions as $submission) {
            if ($submission->attribution !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * The attribution column headers, aligned with {@see self::attributionKeys()}.
     * Raw English to match the other metadata headers.
     *
     * @return list<string>
     */
    private static function attributionHeaders(): array
    {
        return ['UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'UTM Content', 'Referrer', 'Landing Page'];
    }

    /**
     * One submission's attribution cell values, aligned with
     * {@see self::attributionHeaders()}; missing keys yield blank cells.
     *
     * @return list<string>
     */
    private static function attributionValues(Submission $submission): array
    {
        $attribution = $submission->attribution ?? [];

        $values = [];
        foreach (self::attributionKeys() as $key) {
            $values[] = self::neutralizeFormula((string) ($attribution[$key] ?? ''));
        }
        return $values;
    }

    /**
     * Build the first-seen-ordered list of export columns across the result set.
     * A plain field contributes one column (`['key' => 'field_<id>', 'label' =>
     * …]`); a composite field contributes one column per stored sub-field
     * (`['key' => 'field_<id>', 'sub' => '<subKey>', 'label' => '<field> —
     * <sub>']`). The union across submissions keeps mixed forms aligned.
     *
     * @param array<int, Submission> $submissions
     * @return list<CsvColumn>
     */
    private static function discoverColumns(array $submissions): array
    {
        $columns = [];
        $seen = [];

        foreach ($submissions as $submission) {
            foreach (($submission->data ?? []) as $key => $entry) {
                $key = (string) $key;

                if (self::isComposite($entry)) {
                    $fieldLabel = self::entryLabel($entry, $key);
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
                    'label' => self::entryLabel($entry, $key),
                ];
            }
        }

        return $columns;
    }

    /**
     * The ordered scalar cell values for one submission against the discovered
     * column list (a missing field/sub-field yields an empty cell).
     *
     * @param list<CsvColumn> $columns
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
     * The column label for a stored data entry: its `label` from the
     * `{label, type, value}` shape, or the raw `field_<id>` key for a legacy
     * bare-scalar entry (older submissions stored values without the wrapper).
     *
     * @param mixed $entry a stored data entry, or a bare value for legacy rows
     */
    private static function entryLabel(mixed $entry, string $fallback): string
    {
        return is_array($entry) && isset($entry['label']) && is_string($entry['label'])
            ? $entry['label']
            : $fallback;
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

        return self::fieldTypeFor((string) $entry['type']) instanceof CompositeFieldType;
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

        $fieldType = self::fieldTypeFor((string) ($entry['type'] ?? ''));

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
