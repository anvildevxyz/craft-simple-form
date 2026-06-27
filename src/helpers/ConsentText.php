<?php

namespace anvildev\simpleform\helpers;

/**
 * Safe, allowlist rendering and hashing of a Consent field's rich label (#125).
 *
 * The consent text is author-controlled CP content, but is never interpreted as
 * arbitrary HTML or Twig. It is rendered through a fixed, audited transform: a
 * single markdown-style `[label](url)` token becomes an `<a>` (http/https only),
 * and every other character is HTML-escaped. This keeps XSS off the table while
 * still letting non-technical creators link the policy.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
final class ConsentText
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    /**
     * Matches a single markdown-style link token `[label](url)`. The label and
     * url are captured non-greedily; only the link's own delimiters are special,
     * so the surrounding text is escaped verbatim.
     */
    private const LINK_PATTERN = '/\[([^\]]+)\]\(([^)\s]+)\)/';

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Render the consent text as safe HTML: the `[label](url)` token (if any,
     * and only when its URL is http/https) becomes a new-tab `<a>`, and all other
     * text is HTML-escaped. Returns an empty string for empty input.
     */
    public static function render(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $out = '';
        $offset = 0;

        if (preg_match_all(self::LINK_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $i => [$whole, $start]) {
                // Escape the literal text preceding this token.
                $out .= self::escape(substr($text, $offset, $start - $offset));

                $label = self::escape($matches[1][$i][0]);
                $url = $matches[2][$i][0];

                // A non-http(s) URL is neutralised: emit the visible label as
                // plain escaped text, never a clickable javascript:/data: link.
                $out .= self::isSafeUrl($url)
                    ? sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', self::escape($url), $label)
                    : $label;

                $offset = $start + strlen($whole);
            }
        }

        // Escape any trailing literal text after the last token.
        $out .= self::escape(substr($text, $offset));

        return $out;
    }

    /**
     * The plain-text snapshot of the consent shown, used for the audit record's
     * `textVersion`. The link is flattened to `label (url)` so the agreed-to
     * destination is preserved without any markup.
     */
    public static function plain(string $text): string
    {
        $flattened = preg_replace_callback(
            self::LINK_PATTERN,
            static fn(array $m): string => self::isSafeUrl($m[2]) ? sprintf('%s (%s)', $m[1], $m[2]) : $m[1],
            $text,
        );

        return trim((string) ($flattened ?? $text));
    }

    /**
     * A stable, prefixed SHA-256 of the plain consent text, so two submissions
     * can be compared and a later policy edit is detectable.
     */
    public static function hash(string $text): string
    {
        return 'sha256:' . hash('sha256', self::plain($text));
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Whether a URL is a safe, absolute http/https link. Schemeless and
     * non-http(s) URLs (e.g. `javascript:`, `data:`) are rejected.
     */
    private static function isSafeUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
