<?php

namespace anvildev\simpleform\helpers;

/**
 * Groups a step's ordered fields into rows (each a list of 1..N columns) by each
 * field's `config.row` index. Walks the ordered fields: consecutive fields that
 * share the same numeric `config.row` join the current row; a field without a
 * `row`, or with a different `row` value, starts a new single-column row.
 *
 * This mirrors {@see FormSteps} as a pure, order-driven grouping helper — no
 * schema change. Fields with no `config.row` form lone single-column rows, so a
 * form with no layout hints renders exactly as before. Rows cap at
 * {@see self::MAX_COLUMNS} columns; a field that would overflow spills into a
 * new row sharing the same `row` value.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class FormRows
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    /**
     * @var int the maximum number of columns a single visual row may hold
     */
    public const MAX_COLUMNS = 4;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Groups one step's ordered fields into rows.
     *
     * @param array<int, array<string, mixed>> $stepFields fields of one page, in order
     * @return list<list<array<string, mixed>>> rows, each a list of 1..MAX_COLUMNS fields (columns)
     */
    public static function group(array $stepFields): array
    {
        $rows = [];
        $currentRow = [];
        $currentKey = null;

        foreach ($stepFields as $field) {
            $key = self::rowOf($field);

            // A field with no row hint, a different row value than the row being
            // built, or one that would overflow the cap, starts a fresh row.
            if ($key === null || $key !== $currentKey || count($currentRow) >= self::MAX_COLUMNS) {
                if ($currentRow !== []) {
                    $rows[] = $currentRow;
                }
                $currentRow = [];
                $currentKey = $key;
            }

            $currentRow[] = $field;
        }

        if ($currentRow !== []) {
            $rows[] = $currentRow;
        }

        return $rows;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Returns the field's `config.row` as a positive integer, or null when the
     * field carries no usable row hint (the back-compat default).
     *
     * @param array<string, mixed> $field
     */
    private static function rowOf(array $field): ?int
    {
        $config = $field['config'] ?? null;
        $row = is_array($config) ? ($config['row'] ?? null) : null;

        return is_numeric($row) && (int) $row >= 1 ? (int) $row : null;
    }
}
