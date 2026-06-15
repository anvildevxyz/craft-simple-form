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
        return sprintf(
            '<input type="text" %s class="text fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }
}
