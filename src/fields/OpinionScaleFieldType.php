<?php

namespace anvildev\simpleform\fields;

/**
 * An opinion / NPS scale over a configurable integer range (default 0–10), with
 * translatable left/right anchor labels ("Not likely" … "Very likely").
 *
 * Renders as an accessible native radio group — one radio per scale point —
 * styled into a horizontal strip by the front-end asset bundle, so it is
 * keyboard-navigable and works without JavaScript. The chosen value is stored as
 * an integer so analytics and the exporter treat it numerically.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class OpinionScaleFieldType extends FieldType
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /** The default lower bound (an NPS scale starts at 0). */
    public const DEFAULT_MIN = 0;

    /** The default upper bound (an NPS scale ends at 10). */
    public const DEFAULT_MAX = 10;

    /**
     * The largest span (max − min) rendered as discrete radios. Wider ranges are
     * clamped so the strip stays usable; see the PRD open question on sliders.
     */
    public const MAX_SPAN = 10;

    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'opinion';
    }

    public static function getLabel(): string
    {
        return 'Opinion Scale';
    }

    public function isChoiceGroup(): bool
    {
        return true;
    }

    public function aggregation(): AggregationKind
    {
        return AggregationKind::Scale;
    }

    public function aggregationScalePoints(): array
    {
        return $this->allowedValues();
    }

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value)) {
            $errors = array_merge($errors, $this->validateRangeMembership($value, $this->allowedValues()));
        }

        return $errors;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (!$this->hasValue($value)) {
            return $value;
        }
        return (int) $value;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $selected = $this->hasValue($value) ? (int) $value : null;
        $required = !empty($this->config['required']);

        $html = '<div class="sf-opinion" data-sf-opinion>';

        $left = $this->leftLabel();
        if ($left !== '') {
            $html .= sprintf(
                '<span class="sf-opinion-anchor sf-opinion-anchor--left">%s</span>',
                htmlspecialchars($left)
            );
        }

        $html .= '<div class="sf-opinion-scale">';
        foreach ($this->allowedValues() as $i => $optValue) {
            $id = htmlspecialchars($name) . '-' . $i;
            $checked = $selected === $optValue ? ' checked' : '';
            $req = $required ? ' required' : '';
            $html .= sprintf(
                '<input type="radio" class="sf-opinion-input" id="%s" name="%s" value="%d"%s%s>'
                . '<label class="sf-opinion-label" for="%s">%d</label>',
                $id,
                htmlspecialchars($name),
                $optValue,
                $checked,
                $req,
                $id,
                $optValue
            );
        }
        $html .= '</div>';

        $right = $this->rightLabel();
        if ($right !== '') {
            $html .= sprintf(
                '<span class="sf-opinion-anchor sf-opinion-anchor--right">%s</span>',
                htmlspecialchars($right)
            );
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * The configured lower bound.
     */
    public function min(): int
    {
        return (int) ($this->config['min'] ?? self::DEFAULT_MIN);
    }

    /**
     * The configured upper bound, clamped so the span never exceeds
     * {@see self::MAX_SPAN} discrete points and is always ≥ min.
     */
    public function max(): int
    {
        $min = $this->min();
        $max = (int) ($this->config['max'] ?? self::DEFAULT_MAX);
        if ($max < $min) {
            $max = $min;
        }
        return min($max, $min + self::MAX_SPAN);
    }

    /**
     * The translatable left anchor label (e.g. "Not likely"), or '' when unset.
     */
    public function leftLabel(): string
    {
        return trim((string) ($this->config['leftLabel'] ?? ''));
    }

    /**
     * The translatable right anchor label (e.g. "Very likely"), or '' when unset.
     */
    public function rightLabel(): string
    {
        return trim((string) ($this->config['rightLabel'] ?? ''));
    }

    /**
     * The inclusive set of selectable integers: `min..max`.
     *
     * @return list<int>
     */
    public function allowedValues(): array
    {
        return range($this->min(), $this->max());
    }
}
