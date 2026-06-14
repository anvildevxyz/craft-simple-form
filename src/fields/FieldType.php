<?php

namespace fabianhaef\simpleform\fields;

abstract class FieldType
{
    protected array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    abstract public static function getType(): string;

    abstract public static function getLabel(): string;

    public function getConfig(): array
    {
        return $this->config;
    }

    public function validate($value): array
    {
        $errors = [];

        if ($this->config['required'] ?? false) {
            if (empty($value)) {
                $errors[] = 'This field is required.';
            }
        }

        return $errors;
    }

    abstract public function renderInput(string $name, $value = null): string;

    protected function getInputAttributes(string $name, $value = null): string
    {
        $attrs = sprintf('name="%s"', htmlspecialchars($name));
        if ($value !== null) {
            $attrs .= sprintf(' value="%s"', htmlspecialchars($value));
        }
        if ($this->config['required'] ?? false) {
            $attrs .= ' required';
        }
        if ($placeholder = $this->config['placeholder'] ?? null) {
            $attrs .= sprintf(' placeholder="%s"', htmlspecialchars($placeholder));
        }
        return $attrs;
    }
}
