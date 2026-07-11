<?php

namespace anvildev\simpleform\fields;

/**
 * A star / heart / number rating over a configurable maximum (1–10, default 5).
 *
 * Renders as an accessible native radio group (one radio per allowed value)
 * styled by the front-end asset bundle into stars/hearts/number pills, so it is
 * keyboard-navigable and works without JavaScript. The chosen value is stored as
 * an integer so analytics and the exporter treat it numerically.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class RatingFieldType extends FieldType
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * The supported icon presets. `number` falls back to numbered pills; the
     * others render a filled glyph.
     *
     * @var list<string>
     */
    public const ICON_STYLES = ['star', 'heart', 'number'];

    /** The smallest allowed `max` (a single-point rating is degenerate but legal). */
    public const MIN_MAX = 1;

    /** The largest allowed `max` — beyond this discrete radios become unwieldy. */
    public const MAX_MAX = 10;

    /** The default maximum when none is configured (a 1–5 star rating). */
    public const DEFAULT_MAX = 5;

    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'rating';
    }

    public static function getLabel(): string
    {
        return 'Rating';
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
        $iconStyle = $this->iconStyle();
        $selected = $this->hasValue($value) ? (int) $value : null;
        $required = !empty($this->config['required']);

        $html = sprintf(
            '<div class="sf-rating" data-sf-rating data-icon-style="%s">',
            htmlspecialchars($iconStyle)
        );

        $escapedName = htmlspecialchars($name);
        $maxValue = $this->max();
        foreach ($this->allowedValues() as $i => $optValue) {
            $id = $escapedName . '-' . $i;
            $checked = $selected === $optValue ? ' checked' : '';
            $req = $required ? ' required' : '';
            // The accessible name describes the rating (e.g. "4 stars"); the
            // visual glyph is decorative and supplied by the asset bundle.
            $accessibleName = $iconStyle === 'number'
                ? (string) $optValue
                : $this->t('{n} of {max}', ['n' => $optValue, 'max' => $maxValue]);

            $html .= sprintf(
                '<input type="radio" class="sf-rating-input" id="%s" name="%s" value="%d"%s%s>'
                . '<label class="sf-rating-label" for="%s" data-value="%d">'
                . '<span class="sf-rating-icon" aria-hidden="true"></span>'
                . '<span class="sf-rating-text">%s</span></label>',
                $id,
                $escapedName,
                $optValue,
                $checked,
                $req,
                $id,
                $optValue,
                htmlspecialchars($accessibleName)
            );
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * The configured maximum, clamped to the supported 1–10 span.
     */
    public function max(): int
    {
        $max = (int) ($this->config['max'] ?? self::DEFAULT_MAX);
        return max(self::MIN_MAX, min(self::MAX_MAX, $max));
    }

    /**
     * The configured icon preset, falling back to `star` for anything unknown.
     */
    public function iconStyle(): string
    {
        $style = (string) ($this->config['iconStyle'] ?? 'star');
        return in_array($style, self::ICON_STYLES, true) ? $style : 'star';
    }

    /**
     * The inclusive set of selectable integers: `1..max`.
     *
     * @return list<int>
     */
    public function allowedValues(): array
    {
        return range(1, $this->max());
    }
}
