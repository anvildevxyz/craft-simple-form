<?php

namespace anvildev\simpleform\fields;

/**
 * A presentational callout block — a toned, optionally-iconed panel of guidance
 * copy shown between fields. Collects no submission value: it renders the
 * per-site translatable body (HTML-escaped with line breaks preserved, like
 * {@see ParagraphFieldType}) inside a tone-classed wrapper, and is skipped by
 * validation, storage, and export like every layout block.
 *
 * The tone is clamped to {@see self::TONES} so a forged class can never reach
 * the markup, and the optional icon is a short escaped glyph/label. Like the
 * paragraph block the body is plain text — never HTML or Twig — so it is "safe
 * by construction" with no sandbox or purifier pass.
 *
 * @author Anvil Dev
 * @since 2.15.0
 */
class CalloutFieldType extends FieldType
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    /**
     * Allowed callout tones. Constrained to this set so a forged tone can never
     * inject an arbitrary class into the rendered markup.
     *
     * @var list<string>
     */
    public const TONES = ['info', 'success', 'warning', 'error'];

    public const DEFAULT_TONE = 'info';

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public static function getType(): string
    {
        return 'callout';
    }

    public static function getLabel(): string
    {
        return 'Callout';
    }

    public function isInput(): bool
    {
        return false;
    }

    /**
     * The configured tone, clamped to the allowed set ({@see self::TONES}); an
     * invalid or missing tone falls back to {@see self::DEFAULT_TONE}.
     */
    public function tone(): string
    {
        $tone = is_string($this->config['tone'] ?? null) ? $this->config['tone'] : '';
        return in_array($tone, self::TONES, true) ? $tone : self::DEFAULT_TONE;
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
        $body = trim((string)($this->config['body'] ?? ''));
        $icon = trim((string)($this->config['icon'] ?? ''));

        // A callout with neither body nor icon has nothing to say — render nothing.
        if ($body === '' && $icon === '') {
            return '';
        }

        $tone = $this->tone();
        $iconMarkup = $icon === ''
            ? ''
            : sprintf(
                '<span class="simple-form-callout__icon" aria-hidden="true">%s</span>',
                htmlspecialchars($icon, ENT_QUOTES)
            );

        // Escape first, then preserve line breaks: the body is plain text, so any
        // markup it contains renders as literal escaped characters, never as
        // executed HTML/Twig (the security line shared with the paragraph block).
        $bodyMarkup = $body === ''
            ? ''
            : '<div class="simple-form-callout__body">' . nl2br(htmlspecialchars($body, ENT_QUOTES)) . '</div>';

        return sprintf(
            '<div class="simple-form-callout simple-form-callout--%s" role="note">%s%s</div>',
            $tone,
            $iconMarkup,
            $bodyMarkup
        );
    }
}
