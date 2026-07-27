<?php

namespace anvildev\simpleform\fields;

/**
 * A presentational paragraph of static copy (the "Text" element). Collects no
 * submission value: it renders the per-site translatable body text authored in
 * the CP — HTML-escaped with line breaks preserved (`nl2br`-equivalent) — and is
 * skipped by validation, storage, and export like every layout block.
 *
 * Unlike {@see HtmlFieldType}, the body is treated as plain text, never as HTML
 * or Twig: there is no sandbox, no HTMLPurifier pass and no permission gate. It
 * is the deliberately "safe by construction" sibling to the HTML block — as
 * low-friction to add as a Heading, just multi-line body copy instead of a title.
 *
 * The handle is `paragraph` rather than `text` because `text` is already taken by
 * the single-line text input ({@see TextFieldType}); the editor-facing label is
 * still "Text".
 */
class ParagraphFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'paragraph';
    }

    public static function getLabel(): string
    {
        return 'Text';
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
        $text = trim((string)($this->config['text'] ?? ''));
        if ($text === '') {
            return '';
        }

        // Escape first, then preserve line breaks: the body is plain text, so any
        // markup it contains renders as literal escaped characters, never as
        // executed HTML/Twig (the security line that separates this from `html`).
        return '<div class="simple-form-text">' . nl2br(htmlspecialchars($text, ENT_QUOTES)) . '</div>';
    }
}
