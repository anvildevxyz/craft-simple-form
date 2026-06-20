<?php

namespace fabianhaef\simpleform\fields;

/**
 * A presentational section heading. Collects no submission value: it renders an
 * `<h2>`/`<h3>`/`<h4>` (constrained to that set for document-outline safety —
 * the form sits under the page `<h1>`) with the configured, per-site
 * translatable text, and is skipped by validation, storage, and export.
 */
class HeadingFieldType extends FieldType
{
    /**
     * Allowed heading levels. Constrained to h2–h4 so a heading block cannot
     * break the page's document outline.
     *
     * @var list<string>
     */
    public const LEVELS = ['h2', 'h3', 'h4'];

    public const DEFAULT_LEVEL = 'h3';

    public static function getType(): string
    {
        return 'heading';
    }

    public static function getLabel(): string
    {
        return 'Heading';
    }

    public function isInput(): bool
    {
        return false;
    }

    /**
     * The configured level, clamped to the allowed set ({@see self::LEVELS}); an
     * invalid or missing level falls back to {@see self::DEFAULT_LEVEL}.
     */
    public function level(): string
    {
        $level = is_string($this->config['level'] ?? null) ? $this->config['level'] : '';
        return in_array($level, self::LEVELS, true) ? $level : self::DEFAULT_LEVEL;
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
        $text = trim((string)($this->config['text'] ?? ''));
        if ($text === '') {
            return '';
        }

        $level = $this->level();
        return sprintf(
            '<%1$s class="simple-form-heading">%2$s</%1$s>',
            $level,
            htmlspecialchars($text, ENT_QUOTES)
        );
    }
}
