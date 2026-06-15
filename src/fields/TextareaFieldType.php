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

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value)) {
            $errors = array_merge($errors, $this->validateLength((string) $value));
        }

        return $errors;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $attrs = sprintf('name="%s"', htmlspecialchars($name));
        if ($this->config['required'] ?? false) {
            $attrs .= ' required';
        }
        if ($placeholder = $this->config['placeholder'] ?? null) {
            $attrs .= sprintf(' placeholder="%s"', htmlspecialchars((string) $placeholder));
        }
        $value = $value ? htmlspecialchars((string) $value) : '';
        return sprintf(
            '<textarea %s class="fullwidth" rows="6">%s</textarea>',
            $attrs,
            $value
        );
    }
}
