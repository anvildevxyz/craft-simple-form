<?php

namespace fabianhaef\simpleform\fields;

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
                $errors[] = 'This field is required.';
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
                $errors[] = "Must be at least $minLength characters.";
            }
        }
        if ($maxLength = $this->config['maxLength'] ?? null) {
            if (strlen($value) > $maxLength) {
                $errors[] = "Must be no more than $maxLength characters.";
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
        if (!in_array($value, array_keys($this->getOptions()))) {
            return ['Please select a valid option.'];
        }
        return [];
    }

    abstract public function renderInput(string $name, mixed $value = null): string;

    protected function getInputAttributes(string $name, mixed $value = null): string
    {
        $attrs = sprintf('name="%s"', htmlspecialchars($name));
        if ($value !== null) {
            $attrs .= sprintf(' value="%s"', htmlspecialchars((string) $value));
        }
        if ($this->config['required'] ?? false) {
            $attrs .= ' required';
        }
        if ($placeholder = $this->config['placeholder'] ?? null) {
            $attrs .= sprintf(' placeholder="%s"', htmlspecialchars($placeholder));
        }
        return $attrs;
    }
}
