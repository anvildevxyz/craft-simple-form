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

    public function validate($value): array
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

    protected function getOptions(): array
    {
        $optionsConfig = $this->config['options'] ?? '[]';
        if (is_string($optionsConfig)) {
            return json_decode($optionsConfig, true) ?? [];
        }
        return (array) $optionsConfig;
    }

    public function renderInput(string $name, $value = null): string
    {
        $attrs = sprintf('name="%s"', htmlspecialchars($name));
        if ($this->config['required'] ?? false) {
            $attrs .= ' required';
        }

        $options = $this->getOptions();
        $html = sprintf('<select %s class="fullwidth">', $attrs);
        $html .= '<option value="">Select an option</option>';

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
