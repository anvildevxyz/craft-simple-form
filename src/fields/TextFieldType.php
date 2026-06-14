<?php

namespace fabianhaef\simpleform\fields;

class TextFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'text';
    }

    public static function getLabel(): string
    {
        return 'Text';
    }

    public function validate($value): array
    {
        $errors = parent::validate($value);

        if ($value !== null && $value !== '') {
            if ($minLength = $this->config['minLength'] ?? null) {
                if (strlen($value) < $minLength) {
                    $errors[] = "Must be at least $minLength characters.";
                }
            }
            if ($maxLength = $this->config['maxLength'] ?? null) {
                if (strlen($value) > $maxLength) {
                    $errors[] = "Must be no more than $maxLength characters.";
                }
            }
        }

        return $errors;
    }

    public function renderInput(string $name, $value = null): string
    {
        return sprintf(
            '<input type="text" %s class="text fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
