<?php

namespace anvildev\simpleform\fields;

use anvildev\simpleform\exceptions\FormulaException;
use anvildev\simpleform\helpers\Formula;

/**
 * Calculation field (#131): a read-only/computed field whose value is derived
 * from a formula referencing other fields of the same form by handle, e.g.
 * `{quantity} * {unitPrice}`. The value is recomputed authoritatively on the
 * server in {@see \anvildev\simpleform\services\SubmissionService::submit()} —
 * the client-posted value is never trusted.
 *
 * Config keys:
 *  - formula (string):   the allow-listed expression (required)
 *  - decimals (int):     display precision 0–6 (default 2)
 *  - thousandsSeparator (bool): group the integer part (default false)
 *  - prefix (string):    display prefix, e.g. `CHF ` (optional, translatable)
 *  - suffix (string):    display suffix, e.g. ` kg` (optional, translatable)
 *  - missingAsZero (bool): missing/non-numeric reference → 0 (default true)
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class CalculationFieldType extends FieldType
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    /** Maximum supported display precision. */
    public const MAX_DECIMALS = 6;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public static function getType(): string
    {
        return 'calculation';
    }

    public static function getLabel(): string
    {
        return 'Calculation';
    }

    /**
     * Read-only display field — nothing the visitor posts is validated, and the
     * posted value is discarded in favour of the server computation.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        return [];
    }

    /**
     * Compute the value server-side from an already-resolved sibling-value map
     * (keyed by field handle). Total: a malformed formula that somehow slipped
     * past save-time validation, or any evaluation error, yields 0.0 rather than
     * breaking a submission.
     *
     * @param array<string, mixed> $valuesByHandle
     */
    public function compute(array $valuesByHandle): float
    {
        $formula = (string) ($this->config['formula'] ?? '');
        if (trim($formula) === '') {
            return 0.0;
        }

        try {
            return Formula::evaluate($formula, $valuesByHandle, $this->missingAsZero());
        } catch (FormulaException) {
            return 0.0;
        }
    }

    /**
     * Format a computed result for display (CP detail view, exports, live
     * preview) per the precision/separator/prefix/suffix config.
     */
    public function format(float $result): string
    {
        $decimals = $this->decimals();
        $formatted = number_format(
            $result,
            $decimals,
            '.',
            $this->thousandsSeparator() ? ',' : '',
        );

        return $this->prefix() . $formatted . $this->suffix();
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $formula = (string) ($this->config['formula'] ?? '');
        $refs = $this->references();

        // The displayed value is cosmetic and server-authoritative; on first
        // paint show a formatted zero (the JS recomputes once inputs are read).
        $numericValue = is_numeric($value) ? (float) $value : 0.0;
        $display = $this->format($numericValue);

        return sprintf(
            '<output class="simple-form-calculation" name="%1$s-display"'
            . ' data-sf-formula="%2$s" data-sf-refs="%3$s" data-sf-decimals="%4$d"'
            . ' data-sf-separator="%5$s" data-sf-prefix="%6$s" data-sf-suffix="%7$s"'
            . ' data-sf-missing-zero="%8$s">%9$s</output>'
            . '<input type="hidden" name="%1$s" value="%10$s">',
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($formula, ENT_QUOTES),
            htmlspecialchars((string) json_encode($refs), ENT_QUOTES),
            $this->decimals(),
            $this->thousandsSeparator() ? '1' : '0',
            htmlspecialchars($this->prefix(), ENT_QUOTES),
            htmlspecialchars($this->suffix(), ENT_QUOTES),
            $this->missingAsZero() ? '1' : '0',
            htmlspecialchars($display, ENT_QUOTES),
            htmlspecialchars((string) $numericValue, ENT_QUOTES),
        );
    }

    /**
     * The distinct `{handle}` references in the configured formula, for the
     * front-end wiring and save-time validation. Returns an empty list when the
     * formula is malformed (save-time validation reports the real error).
     *
     * @return list<string>
     */
    public function references(): array
    {
        try {
            return Formula::references((string) ($this->config['formula'] ?? ''));
        } catch (FormulaException) {
            return [];
        }
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function decimals(): int
    {
        $decimals = (int) ($this->config['decimals'] ?? 2);
        return max(0, min(self::MAX_DECIMALS, $decimals));
    }

    private function thousandsSeparator(): bool
    {
        return (bool) ($this->config['thousandsSeparator'] ?? false);
    }

    private function missingAsZero(): bool
    {
        return (bool) ($this->config['missingAsZero'] ?? true);
    }

    private function prefix(): string
    {
        return (string) ($this->config['prefix'] ?? '');
    }

    private function suffix(): string
    {
        return (string) ($this->config['suffix'] ?? '');
    }
}
