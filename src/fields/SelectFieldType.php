<?php

namespace anvildev\simpleform\fields;

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

    public function aggregation(): AggregationKind
    {
        return AggregationKind::Choice;
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
        // A <select> carries its value via the selected <option>, so reuse the
        // shared value-less control attributes (name/required/placeholder).
        $attrs = $this->controlAttributes($name);

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
