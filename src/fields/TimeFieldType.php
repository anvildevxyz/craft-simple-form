<?php

namespace anvildev\simpleform\fields;

use anvildev\simpleform\helpers\TimeValue;
use Craft;

class TimeFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'time';
    }

    public static function getLabel(): string
    {
        return 'Time';
    }

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value) && !TimeValue::isValid($value)) {
            $errors[] = Craft::t('simple-form', 'Please enter a valid time.');
        }

        return $errors;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        // Store the canonical HH:MM form; leave an invalid entry untouched so
        // validate() surfaces it rather than silently blanking the field.
        return TimeValue::normalize($value) ?? $value;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        return sprintf(
            '<input type="time" %s class="text fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
