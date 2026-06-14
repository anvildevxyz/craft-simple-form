<?php

namespace fabianhaef\simpleform\fields;

class EmailFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'email';
    }

    public static function getLabel(): string
    {
        return 'Email';
    }

    public function validate($value): array
    {
        $errors = parent::validate($value);

        if ($value !== null && $value !== '') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
        }

        return $errors;
    }

    public function renderInput(string $name, $value = null): string
    {
        return sprintf(
            '<input type="email" %s class="text fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
