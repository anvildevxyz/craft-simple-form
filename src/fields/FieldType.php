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
     * The value-less control attributes (name, required, placeholder) shared by
     * every field control. Inputs add a value via {@see self::getInputAttributes()};
     * <textarea>/<select> carry the value in their markup, so they use this directly.
     */
    /**
     * Whether this type renders a group of choice inputs (radio/checkbox) that
     * each need their own id + <label for>, rather than a single control the
     * group's <label for> can point at. Overridden by the choice types.
     */
    public function isChoiceGroup(): bool
    {
        return false;
    }

    protected function controlAttributes(string $name): string
    {
        // id mirrors the field name so the group's <label for> associates with
        // the control (a11y, #105).
        $attrs = sprintf('id="%s" name="%s"', htmlspecialchars($name), htmlspecialchars($name));
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
