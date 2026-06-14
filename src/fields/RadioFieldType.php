<?php

namespace fabianhaef\simpleform\fields;

class RadioFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'radio';
    }

    public static function getLabel(): string
    {
        return 'Radio';
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
        $options = $this->getOptions();
        $html = '<div class="radio-group">';

        foreach ($options as $optValue => $optLabel) {
            $checked = $value === $optValue ? ' checked' : '';
            $html .= sprintf(
                '<label><input type="radio" name="%s" value="%s"%s> %s</label><br>',
                htmlspecialchars($name),
                htmlspecialchars($optValue),
                $checked,
                htmlspecialchars($optLabel)
            );
        }

        $html .= '</div>';
        return $html;
    }
}
