<?php

namespace anvildev\simpleform\fields;

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

    public function isChoiceGroup(): bool
    {
        return true;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $options = $this->getOptions();
        $values = is_array($value) ? $value : ($value ? [$value] : []);

        $html = '<div class="checkbox-group">';
        $i = 0;
        foreach ($options as $optValue => $optLabel) {
            // Unique id per option + explicit <label for> (a11y, #105). The
            // group itself is labelled via the field group's aria-labelledby.
            $id = htmlspecialchars($name) . '-' . $i;
            $checked = in_array($optValue, $values) ? ' checked' : '';
            $html .= sprintf(
                '<input type="checkbox" id="%s" name="%s[]" value="%s"%s> <label for="%s">%s</label><br>',
                $id,
                htmlspecialchars($name),
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
