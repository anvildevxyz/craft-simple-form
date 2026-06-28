<?php

namespace anvildev\simpleform\fields;

/**
 * A presentational section divider. Collects no submission value: it renders an
 * `<hr>` plus an optional, per-site translatable label, and is skipped by
 * validation, storage, and export.
 */
class DividerFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'divider';
    }

    public static function getLabel(): string
    {
        return 'Section Divider';
    }

    public function isInput(): bool
    {
        return false;
    }

    /**
     * Non-input blocks never validate — defensive `[]` even if a `required`
     * flag is forged into the posted config.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        return [];
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $label = trim((string)($this->config['label'] ?? ''));
        if ($label === '') {
            return '<hr class="simple-form-divider">';
        }

        return sprintf(
            '<div class="simple-form-divider simple-form-divider--labelled">'
            . '<hr><span class="simple-form-divider__label">%s</span></div>',
            htmlspecialchars($label, ENT_QUOTES)
        );
    }
}
