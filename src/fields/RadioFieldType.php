<?php

namespace anvildev\simpleform\fields;

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

    public function isChoiceGroup(): bool
    {
        return true;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $options = $this->getOptions();
        $html = '<div class="radio-group">';

        $escapedName = htmlspecialchars($name);
        $i = 0;
        foreach ($options as $optValue => $optLabel) {
            // Unique id per option + explicit <label for> (a11y, #105). The
            // group itself is labelled via the field group's aria-labelledby.
            $id = $escapedName . '-' . $i;
            $checked = $value === $optValue ? ' checked' : '';
            $html .= sprintf(
                '<input type="radio" id="%s" name="%s" value="%s"%s> <label for="%s">%s</label><br>',
                $id,
                $escapedName,
                htmlspecialchars($optValue),
                $checked,
                $id,
                htmlspecialchars($optLabel)
            );
            $i++;
        }

        $html .= '</div>';
        return $html;
    }
}
