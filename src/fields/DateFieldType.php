<?php

namespace fabianhaef\simpleform\fields;

class DateFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'date';
    }

    public static function getLabel(): string
    {
        return 'Date';
    }

    public function validate($value): array
    {
        $errors = parent::validate($value);

        if ($value !== null && $value !== '') {
            $timestamp = strtotime($value);
            if (!$timestamp) {
                $errors[] = 'Please enter a valid date.';
            }
        }

        return $errors;
    }

    public function renderInput(string $name, $value = null): string
    {
        return sprintf(
            '<input type="date" %s class="fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
