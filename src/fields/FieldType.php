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
     * Whether this type renders its own `<label>` inside {@see self::renderInput()}
     * (so the surrounding field group must not emit a duplicate one). The Consent
     * field does this — its rich, linked consent text *is* the input's label.
     */
    public function rendersOwnLabel(): bool
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
