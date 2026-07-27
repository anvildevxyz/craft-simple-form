<?php

namespace anvildev\simpleform\fields;

use anvildev\simpleform\helpers\ConsentText;
use Craft;
use craft\helpers\DateTimeHelper;

/**
 * Agree / Consent field (#125, GDPR). A single, normally-required checkbox with
 * a translatable rich label that may carry one safe inline link
 * ("I agree to the [privacy policy](…)"). The visitor must actively tick it to
 * submit — enforced server-side in the shared submission path, so a forged or
 * omitted value is rejected on every channel (AJAX and GraphQL alike).
 *
 * On a passing submission the stored value is not a bare boolean but an auditable
 * consent record — `{consented, consentedAt, textVersion, textHash}` — with the
 * timestamp stamped server-side and the consent text snapshotted, giving the
 * "what did they agree to, and when" property GDPR expects, without a new table.
 *
 * Config keys:
 *  - required:        normally true (the builder defaults it on); may be scoped
 *                     by conditional logic like any other field.
 *  - consentText:     the translatable rich label, with an optional single
 *                     `[label](url)` token (rendered safely via {@see ConsentText}).
 *  - requiredMessage: overrides the default translatable "must agree" error.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class ConsentFieldType extends FieldType
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public static function getType(): string
    {
        return 'consent';
    }

    public static function getLabel(): string
    {
        return 'Agree / Consent';
    }

    /**
     * A consent box is a single control, not a choice group, so it keeps the
     * `<label for>` association rather than the role="group" wrapper.
     */
    public function isChoiceGroup(): bool
    {
        return false;
    }

    /**
     * The rich, linked consent text rendered inside {@see self::renderInput()} is
     * the input's `<label>`, so the surrounding field group must not add another.
     */
    public function rendersOwnLabel(): bool
    {
        return true;
    }

    /**
     * Server-side gate: when the box is required (statically or by a matched
     * conditional rule) the posted value must be truthy. A missing/falsey value
     * yields the localized "must agree" message — this is the authoritative
     * check shared by the browser and headless/GraphQL paths.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        if (($this->config['required'] ?? false) && !$this->isChecked($value)) {
            return [$this->requiredMessage()];
        }

        return [];
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $checked = $this->isChecked($value) ? ' checked' : '';
        $required = ($this->config['required'] ?? false) ? ' required' : '';
        $label = ConsentText::render((string) ($this->config['consentText'] ?? ''));

        return sprintf(
            '<div class="sf-consent"><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s%3$s> <label for="%1$s">%4$s</label></div>',
            htmlspecialchars($name, ENT_QUOTES),
            $checked,
            $required,
            $label,
        );
    }

    /**
     * Replace the raw posted `"1"` with the auditable consent record. The
     * timestamp is stamped here (server-side, never trusted from the client) and
     * the consent text shown is snapshotted + hashed so a later policy edit is
     * detectable.
     *
     * @param array<string, mixed> $context
     * @return array{consented: bool, consentedAt: string, textVersion: string, textHash: string}
     */
    public function persistValue(mixed $value, array $context = []): array
    {
        $text = (string) ($this->config['consentText'] ?? '');
        $consentedAt = DateTimeHelper::toIso8601(DateTimeHelper::now());

        return [
            'consented' => $this->isChecked($value),
            'consentedAt' => $consentedAt !== false ? $consentedAt : '',
            'textVersion' => ConsentText::plain($text),
            'textHash' => ConsentText::hash($text),
        ];
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Whether the posted value represents a ticked box. A checkbox posts `"1"`
     * when checked and is absent (null) otherwise; `true`/`1` are accepted for
     * the GraphQL/programmatic paths.
     */
    private function isChecked(mixed $value): bool
    {
        return $value === '1' || $value === 1 || $value === true;
    }

    private function requiredMessage(): string
    {
        $message = trim((string) ($this->config['requiredMessage'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return Craft::t('simple-form', 'You must agree before submitting.');
    }
}
