<?php

namespace fabianhaef\simpleform\fields;

class SelectFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'select';
    }

    public static function getLabel(): string
    {
        return 'Select';
    }

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($value !== null && $value !== '') {
            $options = $this->getOptions();
            if (!in_array($value, array_keys($options))) {
                $errors[] = 'Please select a valid option.';
            }
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    protected function getOptions(): array
    {
        $options = $this->config['options'] ?? [];
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        // Convert array of {label, value} objects to keyed array
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

    public function renderInput(string $name, mixed $value = null): string
    {
        $attrs = sprintf('name="%s"', htmlspecialchars($name));
        if ($this->config['required'] ?? false) {
            $attrs .= ' required';
        }

        $options = $this->getOptions();
        $html = sprintf('<select %s class="fullwidth">', $attrs);
        if (!($this->config['required'] ?? false)) {
            $html .= '<option value="">-- Select an option --</option>';
        }

        foreach ($options as $optValue => $optLabel) {
            $selected = $value === $optValue ? ' selected' : '';
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                htmlspecialchars($optValue),
                $selected,
                htmlspecialchars($optLabel)
            );
        }

        $html .= '</select>';
        return $html;
    }
}
