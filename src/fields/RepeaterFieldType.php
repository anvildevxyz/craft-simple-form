<?php

namespace fabianhaef\simpleform\fields;

use Craft;
use fabianhaef\simpleform\Plugin;

/**
 * A container field holding a small set of inner sub-fields the visitor can
 * repeat ("Add another"). The inner-field definitions live in the container's
 * own `config` JSON (no new tables), and the submitted value is an ordered
 * array of row objects keyed by inner handle.
 *
 * v1 scope is deliberately constrained: inner types are limited to
 * {@see self::ALLOWED_INNER_TYPES}, there are no
 * nested repeaters, no in-row conditional logic, and no file/payment inner
 * types.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class RepeaterFieldType extends FieldType
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * The inner field types a repeater may contain in v1 — the simple,
     * single-value types only. Any inner def with a type outside this list is
     * rejected at form-save time.
     *
     * @var list<string>
     */
    public const ALLOWED_INNER_TYPES = ['text', 'email', 'number', 'select'];

    /** The placeholder substituted for the row index in the JS row template. */
    public const INDEX_PLACEHOLDER = '__INDEX__';

    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'repeater';
    }

    public static function getLabel(): string
    {
        return 'Repeater';
    }

    /**
     * The configured minimum number of rows (never below 0; a required
     * repeater implies at least 1).
     */
    public function minRows(): int
    {
        $min = (int) ($this->config['minRows'] ?? 0);
        if ($min < 0) {
            $min = 0;
        }
        if (($this->config['required'] ?? false) && $min < 1) {
            $min = 1;
        }
        return $min;
    }

    /**
     * The configured maximum number of rows. 0 (the default) means unbounded.
     */
    public function maxRows(): int
    {
        $max = (int) ($this->config['maxRows'] ?? 0);
        return $max < 0 ? 0 : $max;
    }

    /**
     * The ordered list of inner sub-field definitions, each normalized to
     * `{handle, type, label, config}`. Defs with an empty or non-allowed type,
     * or an empty handle, are dropped — the form-save validator is what
     * surfaces those as errors; here they're simply ignored so a malformed
     * config can never crash rendering or validation.
     *
     * @return list<array{handle: string, type: string, label: string, config: array<string, mixed>}>
     */
    public function innerFields(): array
    {
        $defs = $this->config['fields'] ?? [];
        if (!is_array($defs)) {
            return [];
        }

        $result = [];
        $seen = [];
        foreach ($defs as $def) {
            if (!is_array($def)) {
                continue;
            }
            $handle = trim((string) ($def['handle'] ?? ''));
            $type = (string) ($def['type'] ?? '');
            if ($handle === '' || !in_array($type, self::ALLOWED_INNER_TYPES, true)) {
                continue;
            }
            // Inner handles are unique within the repeater; a duplicate is
            // dropped so a posted cell can never map to two defs.
            $key = strtolower($handle);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $result[] = [
                'handle' => $handle,
                'type' => $type,
                'label' => (string) ($def['label'] ?? $handle),
                'config' => $this->innerConfig($def),
            ];
        }

        return $result;
    }

    /**
     * Coerce a posted/stored repeater value into an ordered, 0-indexed list of
     * row objects keyed by the repeater's known inner handles. Trailing empty
     * rows are dropped, removed-index gaps are re-keyed, and unknown inner keys
     * are stripped — so a crafted POST can never inject data outside the schema.
     *
     * Pure and side-effect free (no Craft, no DB) for straightforward unit
     * testing of the serialization contract.
     *
     * @param list<array{handle: string, type: string, label: string, config: array<string, mixed>}> $innerDefs the result of {@see self::innerFields()}
     * @return list<array<string, string>>
     */
    public static function normalizeRows(mixed $value, array $innerDefs): array
    {
        $value = self::decodeValue($value);
        if (!is_array($value)) {
            return [];
        }

        $handles = [];
        foreach ($innerDefs as $def) {
            $handles[] = (string) $def['handle'];
        }

        // Preserve submission order (the posted array may be keyed by row index
        // with gaps); ksort puts numeric row keys back in ascending order.
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $clean = [];
            $hasValue = false;
            foreach ($handles as $handle) {
                $cell = $row[$handle] ?? '';
                $cell = is_scalar($cell) ? (string) $cell : '';
                $clean[$handle] = $cell;
                if ($cell !== '') {
                    $hasValue = true;
                }
            }

            // Drop wholly-empty rows so a visitor's leftover blank "Add another"
            // row never counts toward the min/max bounds or persists noise.
            if ($hasValue) {
                $rows[] = $clean;
            }
        }

        return $rows;
    }

    /**
     * Validate the whole posted value: row-count bounds plus each cell via its
     * inner field type's own {@see FieldType::validate()}.
     *
     * Returns a flat list of human-readable messages (the shared error contract
     * every field type uses); per-cell errors are prefixed with the row number
     * and inner label so the editor and visitor can locate the exact cell.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $innerDefs = $this->innerFields();
        $rows = self::normalizeRows($value, $innerDefs);
        $errors = [];

        // (1) Row-count bounds.
        $count = count($rows);
        $min = $this->minRows();
        $max = $this->maxRows();

        if ($count < $min) {
            $errors[] = Craft::t('simple-form', 'Add at least {min} row(s).', ['min' => $min]);
        }
        if ($max > 0 && $count > $max) {
            $errors[] = Craft::t('simple-form', 'Add no more than {max} row(s).', ['max' => $max]);
        }

        // (2) Per-cell validation via the inner field type's own rules.
        $registry = Plugin::getInstance()->getFieldTypeRegistry();
        foreach ($rows as $i => $row) {
            $rowNum = $i + 1;
            foreach ($innerDefs as $def) {
                $innerType = $registry->getFieldType($def['type'], $def['config']);
                if ($innerType === null) {
                    continue;
                }

                $cellErrors = $innerType->validate($row[$def['handle']] ?? '');
                foreach ($cellErrors as $cellError) {
                    $errors[] = Craft::t('simple-form', 'Row {row}, {label}: {error}', [
                        'row' => $rowNum,
                        'label' => $def['label'],
                        'error' => $cellError,
                    ]);
                }
            }
        }

        return $errors;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $innerDefs = $this->innerFields();
        $min = $this->minRows();
        $max = $this->maxRows();
        $registry = Plugin::getInstance()->getFieldTypeRegistry();

        $rows = self::normalizeRows($value, $innerDefs);

        // Pre-render at least max(1, minRows) rows so the no-JS path still
        // submits a usable, bounds-satisfying set on first load.
        $initialCount = max(count($rows), max(1, $min));

        $addLabel = trim((string) ($this->config['addButtonLabel'] ?? ''));
        if ($addLabel === '') {
            $addLabel = Craft::t('simple-form', 'Add another');
        }
        $removeLabel = Craft::t('simple-form', 'Remove');

        $html = sprintf(
            '<div class="simple-form-repeater" data-sf-repeater data-sf-min="%d" data-sf-max="%d">',
            $min,
            $max
        );

        // The prototype row the JS clones for "Add another". Hidden from the
        // no-JS path and excluded from the POST via inert names.
        $html .= sprintf(
            '<template data-sf-repeater-template>%s</template>',
            $this->renderRow($name, self::INDEX_PLACEHOLDER, [], $innerDefs, $registry, $removeLabel)
        );

        $html .= '<div class="simple-form-repeater-rows" data-sf-repeater-rows>';
        for ($i = 0; $i < $initialCount; $i++) {
            $rowValue = $rows[$i] ?? [];
            $html .= $this->renderRow($name, (string) $i, $rowValue, $innerDefs, $registry, $removeLabel);
        }
        $html .= '</div>';

        $html .= sprintf(
            '<button type="button" class="simple-form-repeater-add" data-sf-repeater-add>%s</button>',
            htmlspecialchars($addLabel)
        );

        $html .= '</div>';

        return $html;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Render one row's <fieldset> of inner cells, named
     * `field_<id>[<index>][<handle>]`, delegating each cell to its inner field
     * type's own {@see FieldType::renderInput()} so markup matches the flat
     * fields exactly.
     *
     * @param array<string, string> $rowValue
     * @param list<array{handle: string, type: string, label: string, config: array<string, mixed>}> $innerDefs
     */
    private function renderRow(
        string $name,
        string $index,
        array $rowValue,
        array $innerDefs,
        \fabianhaef\simpleform\services\FieldTypeRegistry $registry,
        string $removeLabel,
    ): string {
        $html = '<fieldset class="simple-form-repeater-row" data-sf-repeater-row>';

        foreach ($innerDefs as $def) {
            $innerType = $registry->getFieldType($def['type'], $def['config']);
            if ($innerType === null) {
                continue;
            }

            // field_<id>[<index>][<handle>] — inner handles live in their own
            // namespace and never collide with top-level field handles.
            $cellName = sprintf('%s[%s][%s]', $name, $index, $def['handle']);
            $cellValue = $rowValue[$def['handle']] ?? null;

            $html .= '<div class="simple-form-repeater-cell">';
            $html .= sprintf(
                '<label for="%s">%s%s</label>',
                htmlspecialchars($cellName),
                htmlspecialchars($def['label']),
                ($def['config']['required'] ?? false) ? ' <span class="required" aria-hidden="true">*</span>' : ''
            );
            $html .= $innerType->renderInput($cellName, $cellValue);
            $html .= '</div>';
        }

        $html .= sprintf(
            '<button type="button" class="simple-form-repeater-remove" data-sf-repeater-remove>%s</button>',
            htmlspecialchars($removeLabel)
        );
        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Build the per-type config for an inner field def, merging its `required`
     * flag into the same shape the flat field types already understand so each
     * inner cell can be validated and rendered by its own {@see FieldType}.
     *
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    private function innerConfig(array $def): array
    {
        $config = $def;
        // Structural keys are not per-type config; drop them so the inner field
        // type sees only its own settings (plus required).
        unset($config['handle'], $config['type'], $config['label']);
        $config['required'] = !empty($def['required']);
        return $config;
    }

    /**
     * Tolerate a JSON-encoded string value from a non-browser client (e.g. a
     * GraphQL submit that passes the repeater value as a JSON string), decoding
     * it to an array; otherwise pass the value through untouched.
     */
    private static function decodeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $value;
        }
        return $value;
    }
}
