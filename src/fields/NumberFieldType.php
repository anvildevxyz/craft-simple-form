<?php

namespace anvildev\simpleform\fields;

use Craft;

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

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value)) {
            if (!is_numeric($value)) {
                $errors[] = Craft::t('simple-form', 'Please enter a valid number.');
            } else {
                $numValue = (float) $value;
                if ($min = $this->config['min'] ?? null) {
                    if ($numValue < $min) {
                        $errors[] = Craft::t('simple-form', 'Must be at least {min}.', ['min' => $min]);
                    }
                }
                if ($max = $this->config['max'] ?? null) {
                    if ($numValue > $max) {
                        $errors[] = Craft::t('simple-form', 'Must be no more than {max}.', ['max' => $max]);
                    }
                }
            }
        }

        return $errors;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        return sprintf(
            '<input type="number" %s class="fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
