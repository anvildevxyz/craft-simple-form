<?php

namespace anvildev\simpleform\fields;

use Craft;

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

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value)) {
            $timestamp = strtotime((string) $value);
            if (!$timestamp) {
                $errors[] = Craft::t('simple-form', 'Please enter a valid date.');
            }
        }

        return $errors;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        return sprintf(
            '<input type="date" %s class="fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
