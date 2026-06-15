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

        if ($this->hasValue($value)) {
            $errors = array_merge($errors, $this->validateOptionMembership($value));
        }

        return $errors;
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
