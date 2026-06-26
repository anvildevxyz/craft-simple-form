<?php

namespace fabianhaef\simpleform\fields;

use Craft;

abstract class FieldType
{
    /** @var array<string, mixed> */
    protected array $config = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    abstract public static function getType(): string;

    abstract public static function getLabel(): string;

    /**
     * Whether this field collects a submission value.
     *
     * Presentational/layout blocks (heading, divider, html) return false: they
     * render on the public form but are never validated, stored, or exported.
     * The rest of the pipeline keys off this one seam, so a non-input field
     * never lands in {@see \fabianhaef\simpleform\elements\Submission::$data},
     * never produces a column, and can never block submission.
     */
    public function isInput(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = [];

        if ($this->config['required'] ?? false) {
            if (empty($value)) {
                $errors[] = $this->t('This field is required.');
            }
        }

        return $errors;
    }

    protected function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * Transform a posted value into the shape that is persisted in the
     * submission `data` payload. Most field types store the value verbatim, so
     * the base is a passthrough; types that normalize (e.g. Phone, which stores
     * a `{raw, e164, country}` map) override this. Runs in
     * {@see \fabianhaef\simpleform\services\SubmissionService::submit()} after
     * validation passes, so both the AJAX and GraphQL paths persist the same
     * normalized shape.
     */
    public function normalizeStoredValue(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Render a stored value to a single scalar for CSV/element exports. The base
     * passes scalars through and pipe-joins lists; types with a structured
     * stored value (e.g. Phone) override this to pick the export-friendly form.
     */
    public function exportValue(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return '1';
        }
        if (is_array($value)) {
            return implode('|', array_map(fn(mixed $v): string => $this->exportValue($v), $value));
        }

        return (string) $value;
    }

    /**
     * Translate a `simple-form` message, falling back to a local placeholder
     * interpolation when no Craft application is booted (e.g. the pure-source
     * unit tests). Production always has the app, so strings stay translatable;
     * the fallback only keeps render testable without a full boot.
     *
     * @param array<string, int|string> $params
     */
    protected function t(string $message, array $params = []): string
    {
        if (class_exists(Craft::class) && Craft::$app !== null) {
            return Craft::t('simple-form', $message, $params);
        }

        $replace = [];
        foreach ($params as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }
        return strtr($message, $replace);
    }

    /**
     * Coerce a submitted value into the canonical form stored in
     * `submission.data`. The default is a pass-through; numeric scale types
     * (rating/opinion) override this to cast to an int so analytics and the
     * exporter treat the column numerically rather than as a string.
     */
    public function normalizeValue(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Integer-range membership check shared by the numeric scale types
     * (rating/opinion). The analogue of {@see self::validateOptionMembership()}:
     * a forged out-of-range or non-integer POST is rejected server-side
     * regardless of client JS.
     *
     * @param list<int> $allowed the inclusive set of permitted integers
     * @return string[]
     */
    protected function validateRangeMembership(mixed $value, array $allowed): array
    {
        // Accept only an exact integer (or its integer-string form) — a
        // fractional or non-numeric value never matches the discrete options.
        if (is_int($value) || (is_string($value) && $value !== '' && (string) (int) $value === $value)) {
            if (in_array((int) $value, $allowed, true)) {
                return [];
            }
        }
        return [$this->t('Please select a valid option.')];
    }

    /**
     * Minimum/maximum length check shared by the text-based field types.
     *
     * @return string[]
     */
    protected function validateLength(string $value): array
    {
        $errors = [];
        if ($minLength = $this->config['minLength'] ?? null) {
            if (strlen($value) < $minLength) {
                $errors[] = Craft::t('simple-form', 'Must be at least {min} characters.', ['min' => $minLength]);
            }
        }
        if ($maxLength = $this->config['maxLength'] ?? null) {
            if (strlen($value) > $maxLength) {
                $errors[] = Craft::t('simple-form', 'Must be no more than {max} characters.', ['max' => $maxLength]);
            }
        }
        return $errors;
    }

    /**
     * Decode the stored options config (a JSON string or an array of
     * {value, label}) into a value => label map. Shared by the choice
     * field types (select, radio, checkbox).
     *
     * @return array<string, string>
     */
    protected function getOptions(): array
    {
        $options = $this->config['options'] ?? [];
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        $result = [];
        if (is_array($options)) {
            foreach ($options as $opt) {
                if (is_array($opt) && isset($opt['value'], $opt['label'])) {
                    $result[$opt['value']] = $opt['label'];
                } elseif (is_object($opt) && isset($opt->value, $opt->label)) {
                    $result[$opt->value] = $opt->label;
                }
            }
        }
        return $result;
    }

    /**
     * Single-value option-membership check shared by select and radio.
     *
     * @return string[]
     */
    protected function validateOptionMembership(mixed $value): array
    {
        // O(1) key lookup — getOptions() is keyed by option value (label always
        // set), so an isset() check is equivalent to membership without the
        // array_keys() allocation. is_scalar guards against a non-scalar
        // (array) posted value reaching the array offset.
        $options = $this->getOptions();
        if (is_scalar($value) && isset($options[$value])) {
            return [];
        }
        return [Craft::t('simple-form', 'Please select a valid option.')];
    }

    abstract public function renderInput(string $name, mixed $value = null): string;

    /**
     * Transform a validated posted value into the shape persisted in the
     * submission's `data` payload. The default is an identity pass-through — the
     * stored value is exactly what was posted.
     *
     * The Consent field overrides this to replace the raw `"1"` with an auditable
     * consent record (boolean + server-stamped timestamp + text snapshot/hash),
     * so the proof of what was agreed to lives in the existing submission-data
     * model with no new table.
     *
     * @param array<string, mixed> $context per-submission context (e.g. `siteId`)
     */
    public function persistValue(mixed $value, array $context = []): mixed
    {
        return $value;
    }

    /**
     * Whether this type renders a group of choice inputs (radio/checkbox) that
     * each need their own id + <label for>, rather than a single control the
     * group's <label for> can point at. Overridden by the choice types.
     */
    public function isChoiceGroup(): bool
    {
        return false;
    }

    /**
     * Whether this type renders a visitor-facing control that belongs inside the
     * standard labelled field group (label + help text + wrapper).
     *
     * Distinct from {@see self::isInput()} (which is about whether the field
     * collects a stored value): the Hidden field (#124) collects a value yet
     * returns false here, so the front-end template emits its bare markup with
     * no label or wrapper. Presentational layout blocks also return false but
     * are additionally non-input.
     */
    public function rendersInGroup(): bool
    {
        return true;
    }

    /**
     * Whether this type renders its own `<label>` inside {@see self::renderInput()}
     * (so the surrounding field group must not emit a duplicate one). The Consent
     * field does this — its rich, linked consent text *is* the input's label.
     */
    public function rendersOwnLabel(): bool
    {
        return false;
    }

    /**
     * How this field's submitted values roll up in the survey report (#240).
     *
     * The default is {@see AggregationKind::None} — the report lists the field
     * with a response count but no chart. Choice types override to
     * {@see AggregationKind::Choice} (per-option counts) and the numeric scale
     * types to {@see AggregationKind::Scale} (distribution + average), so the
     * report derives each field's treatment from the type itself rather than
     * from a hardcoded list.
     */
    public function aggregation(): AggregationKind
    {
        return AggregationKind::None;
    }

    /**
     * The closed option set (value => label) a {@see AggregationKind::Choice}
     * field reports over, in their authored order, so the report can list every
     * option — including ones nobody picked. Empty for non-choice types.
     *
     * @return array<string, string>
     */
    public function aggregationOptions(): array
    {
        return $this->getOptions();
    }

    /**
     * The full ordered set of scale points a {@see AggregationKind::Scale} field
     * spans, so the report's distribution shows every point (zero-filled).
     * Empty for non-scale types.
     *
     * @return list<int>
     */
    public function aggregationScalePoints(): array
    {
        return [];
    }

    /**
     * The value-less control attributes (name, required, placeholder) shared by
     * every field control. Inputs add a value via {@see self::getInputAttributes()};
     * <textarea>/<select> carry the value in their markup, so they use this directly.
     */
    protected function controlAttributes(string $name): string
    {
        // id mirrors the field name so the group's <label for> associates with
        // the control (a11y, #105).
        $escaped = htmlspecialchars($name);
        $attrs = sprintf('id="%s" name="%s"', $escaped, $escaped);
        if ($this->config['required'] ?? false) {
            $attrs .= ' required';
        }
        if ($placeholder = $this->config['placeholder'] ?? null) {
            $attrs .= sprintf(' placeholder="%s"', htmlspecialchars((string) $placeholder));
        }
        return $attrs;
    }

    protected function getInputAttributes(string $name, mixed $value = null): string
    {
        $attrs = $this->controlAttributes($name);
        if ($value !== null) {
            $attrs .= sprintf(' value="%s"', htmlspecialchars((string) $value));
        }
        return $attrs;
    }
}
