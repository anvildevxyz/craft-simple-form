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
