<?php

namespace fabianhaef\simpleform\fields;

use Craft;

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

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value)) {
            $options = $this->getOptions();
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                // O(1) key lookup; getOptions() is keyed by option value.
                if (!is_scalar($v) || !isset($options[$v])) {
                    $errors[] = Craft::t('simple-form', 'Please select valid options.');
                    break;
                }
            }
        }

        return $errors;
    }

    public function renderInput(string $name, mixed $value = null): string
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
