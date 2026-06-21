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
                $errors[] = Craft::t('simple-form', 'This field is required.');
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

    /**
     * Whether this type renders a visitor-facing input that belongs inside the
     * standard labelled field group (label + help text + wrapper).
     *
     * Value-less / non-visible types (e.g. the Hidden field, #124) return false
     * so the front-end template emits their bare markup with no label or
     * wrapper.
     */
    public function isInput(): bool
    {
        return true;
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
