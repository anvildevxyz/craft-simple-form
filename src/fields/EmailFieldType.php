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

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value)) {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
        }

        return $errors;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        return sprintf(
            '<input type="email" %s class="text fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
