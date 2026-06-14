<?php

namespace fabianhaef\simpleform\fields;

class TextareaFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'textarea';
    }

    public static function getLabel(): string
    {
        return 'Textarea';
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
        $attrs = sprintf('name="%s"', htmlspecialchars($name));
        if ($this->config['required'] ?? false) {
            $attrs .= ' required';
        }
        if ($placeholder = $this->config['placeholder'] ?? null) {
            $attrs .= sprintf(' placeholder="%s"', htmlspecialchars($placeholder));
        }
        $value = $value ? htmlspecialchars($value) : '';
        return sprintf(
            '<textarea %s class="fullwidth" rows="6">%s</textarea>',
            $attrs,
            $value
        );
    }
}
