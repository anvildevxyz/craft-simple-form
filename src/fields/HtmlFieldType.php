<?php

namespace fabianhaef\simpleform\fields;

use Craft;
use craft\helpers\HtmlPurifier;
use fabianhaef\simpleform\Plugin;

/**
 * A presentational, CP-authored HTML/Twig block. Collects no submission value:
 * the author content is rendered through the plugin's forced-sandbox Twig path
 * ({@see \fabianhaef\simpleform\services\SafeRenderService}) and then passed
 * through an allowlist HTML purifier, so `<script>`, inline event handlers and
 * `javascript:` URLs are stripped and `craft.app`/queries stay out of reach.
 *
 * Authoring the body requires the `editHtmlBlocks` permission
 * ({@see \fabianhaef\simpleform\helpers\SimpleFormPermissions::EDIT_HTML_BLOCKS});
 * the block is skipped by validation, storage, and export like every layout block.
 */
class HtmlFieldType extends FieldType
{
    /**
     * Tags the purifier keeps. Deliberately permissive-but-safe: common copy,
     * lists, links and images, but no `<script>`, `<style>`, `<iframe>`, or
     * form controls.
     */
    public const ALLOWED_TAGS = 'p,br,hr,h2,h3,h4,h5,h6,strong,b,em,i,u,s,'
        . 'a[href|title|rel|target],ul,ol,li,blockquote,code,pre,span[class],'
        . 'div[class],img[src|alt|width|height],small';

    /**
     * URI schemes links/images may use. Excludes `javascript:` and `data:` so
     * neither script execution nor data-URI payloads survive.
     */
    public const ALLOWED_URI_SCHEMES = ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true];

    public static function getType(): string
    {
        return 'html';
    }

    public static function getLabel(): string
    {
        return 'HTML Block';
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
        $html = trim((string)($this->config['html'] ?? ''));
        if ($html === '') {
            return '';
        }

        try {
            $rendered = Plugin::getInstance()->getSafeRender()->render($html);
        } catch (\Throwable $e) {
            // A sandbox rejection (e.g. a disallowed function/tag) must never
            // leak raw author Twig to the page or blow up the form render.
            Craft::warning('HTML block render failed: ' . $e->getMessage(), 'simple-form');
            return '';
        }

        return '<div class="simple-form-html">' . self::purify($rendered) . '</div>';
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Strip everything outside the {@see self::ALLOWED_TAGS} allowlist —
     * `<script>`, inline event handlers (`onclick=…`), `javascript:`/`data:`
     * URLs and `<style>` are removed.
     */
    public static function purify(string $html): string
    {
        return HtmlPurifier::process($html, static function($config): void {
            $config->set('HTML.Allowed', self::ALLOWED_TAGS);
            $config->set('URI.AllowedSchemes', self::ALLOWED_URI_SCHEMES);
            // Allow target="_blank" on links (the allowlist names the attribute);
            // authors control rel themselves, so nofollow stays off.
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
        });
    }
}
