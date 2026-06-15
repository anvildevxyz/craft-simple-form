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

        if ($this->hasValue($value)) {
            $errors = array_merge($errors, $this->validateOptionMembership($value));
        }

        return $errors;
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
