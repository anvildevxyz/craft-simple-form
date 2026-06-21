<?php

namespace fabianhaef\simpleform\fields;

use Craft;
use fabianhaef\simpleform\helpers\SignaturePng;

/**
 * A drawn-signature field (#129). The visitor signs on an HTML `<canvas>` pad;
 * the front-end script serializes the drawing to a PNG `data:` URL into a hidden
 * input, which posts like any other `field_<id>` value. The submission path
 * decodes that data URL into a Craft Asset (via {@see AssetUploadService}) and
 * stores the asset id list as the field's value — the same shape as the File
 * field — so the signature gets the full asset toolset (thumbnails, permissions,
 * deletion, retention) for free.
 *
 * Config: `required`, optional `volume` (asset-volume handle), optional
 * presentational `penColor` / `background`.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class SignatureFieldType extends FieldType
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /** Default pen color when the field has no `penColor` config. */
    private const DEFAULT_PEN_COLOR = '#1a1a1a';

    /** Default pad background when the field has no `background` config. */
    private const DEFAULT_BACKGROUND = '#ffffff';

    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'signature';
    }

    public static function getLabel(): string
    {
        return 'Signature';
    }

    /**
     * Validate the field value. Two shapes reach this method:
     *  - the posted PNG `data:` URL string (front-of-pipeline validation);
     *  - the resolved asset-id list (`[123]`) after the submit path has already
     *    decoded and stored the signature — re-validated in {@see submit()}.
     *
     * An empty value is "no signature": required ⇒ the standard required error,
     * optional ⇒ valid. A non-empty data URL that is not a decodable PNG within
     * the size limit is rejected as invalid, so a junk POST can never reach the
     * asset pipeline. A non-empty asset-id list is already-validated and passes.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        // Already-resolved asset ids (post-decode): the data-URL guard ran
        // earlier, so a non-empty list is valid.
        if (is_array($value)) {
            if ($value === [] && ($this->config['required'] ?? false)) {
                return [Craft::t('simple-form', 'This field is required.')];
            }
            return [];
        }

        if (!SignaturePng::hasDrawing($value)) {
            if ($this->config['required'] ?? false) {
                return [Craft::t('simple-form', 'This field is required.')];
            }
            return [];
        }

        if (!SignaturePng::isValid($value)) {
            return [Craft::t('simple-form', 'The signature is invalid.')];
        }

        return [];
    }

    /**
     * Render the canvas pad: a hidden input that carries the PNG data URL (this
     * is what posts), the `<canvas>` the asset bundle's pad attaches to, and a
     * Clear control. The wrapper's data attributes drive the pad's appearance.
     */
    public function renderInput(string $name, mixed $value = null): string
    {
        $id = htmlspecialchars($name, ENT_QUOTES);
        $penColor = htmlspecialchars($this->presentational('penColor', self::DEFAULT_PEN_COLOR), ENT_QUOTES);
        $background = htmlspecialchars($this->presentational('background', self::DEFAULT_BACKGROUND), ENT_QUOTES);
        $required = ($this->config['required'] ?? false) ? ' data-sf-required="1"' : '';
        $clearLabel = htmlspecialchars(Craft::t('simple-form', 'Clear'), ENT_QUOTES);
        $hint = htmlspecialchars(Craft::t('simple-form', 'Sign above using your mouse, finger, or stylus.'), ENT_QUOTES);
        $noJs = htmlspecialchars(Craft::t('simple-form', 'A signature requires JavaScript to be enabled.'), ENT_QUOTES);
        $hidden = $value !== null ? htmlspecialchars((string) $value, ENT_QUOTES) : '';

        return <<<HTML
<div class="simple-form-signature" data-sf-signature data-sf-pen="{$penColor}" data-sf-bg="{$background}"{$required}>
    <canvas class="simple-form-signature__canvas" data-sf-signature-canvas aria-label="{$hint}" role="img"></canvas>
    <input type="hidden" id="{$id}" name="{$id}" data-sf-signature-input value="{$hidden}">
    <div class="simple-form-signature__controls">
        <button type="button" class="simple-form-signature__clear" data-sf-signature-clear>{$clearLabel}</button>
        <small class="simple-form-signature__hint">{$hint}</small>
    </div>
    <noscript><p class="simple-form-signature__nojs">{$noJs}</p></noscript>
</div>
HTML;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /** A presentational config string, falling back to a default when blank. */
    private function presentational(string $key, string $default): string
    {
        $value = $this->config[$key] ?? null;
        return (is_string($value) && trim($value) !== '') ? trim($value) : $default;
    }
}
