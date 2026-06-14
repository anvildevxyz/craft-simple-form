<?php

namespace fabianhaef\simpleform\fields;

class NumberFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'number';
    }

    public static function getLabel(): string
    {
        return 'Number';
    }

    public function validate($value): array
    {
        $errors = parent::validate($value);

        if ($value !== null && $value !== '') {
            if (!is_numeric($value)) {
                $errors[] = 'Please enter a valid number.';
            } else {
                $numValue = (float) $value;
                if ($min = $this->config['min'] ?? null) {
                    if ($numValue < $min) {
                        $errors[] = "Must be at least $min.";
                    }
                }
                if ($max = $this->config['max'] ?? null) {
                    if ($numValue > $max) {
                        $errors[] = "Must be no more than $max.";
                    }
                }
            }
        }

        return $errors;
    }

    public function renderInput(string $name, $value = null): string
    {
        return sprintf(
            '<input type="number" %s class="fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
