<?php

namespace fabianhaef\simpleform\fields;

class CheckboxFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'checkbox';
    }

    public static function getLabel(): string
    {
        return 'Checkbox';
    }

    public function validate($value): array
    {
        $errors = parent::validate($value);

        if ($value !== null && $value !== '') {
            $options = $this->getOptions();
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                if (!in_array($v, array_keys($options))) {
                    $errors[] = 'Please select valid options.';
                    break;
                }
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
        $values = is_array($value) ? $value : ($value ? [$value] : []);

        $html = '<div class="checkbox-group">';
        foreach ($options as $optValue => $optLabel) {
            $checked = in_array($optValue, $values) ? ' checked' : '';
            $html .= sprintf(
                '<label><input type="checkbox" name="%s[]" value="%s"%s> %s</label><br>',
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
