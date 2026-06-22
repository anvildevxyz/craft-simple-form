<?php

namespace fabianhaef\simpleform\helpers;

/**
 * Decodes and validates the PNG `data:` URL a Signature field posts (#129).
 *
 * The captured value is a base64 `image/png` data URL produced client-side by
 * `canvas.toDataURL('image/png')`. Nothing about that string can be trusted, so
 * this helper parses it, hard-checks the declared media type, decodes the
 * payload, verifies it is genuinely a PNG by its magic bytes, and enforces a
 * byte ceiling — all *before* any temp file is written or asset created.
 *
 * @since 1.0.0
 */
final class SignaturePng
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /** The eight-byte PNG file signature every valid PNG starts with. */
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /** Default maximum decoded payload size: 5 MB. */
    public const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Whether the given value is a non-empty signature payload (the visitor
     * actually drew something). An empty string, null, or a non-string is "no
     * value" — required validation keys off this.
     */
    public static function hasDrawing(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Decode a PNG `data:` URL to its raw bytes, or return null when the input
     * is not a syntactically valid, correctly-typed, magic-byte-verified PNG
     * within the size limit. Pure and side-effect free.
     *
     * @param int $maxBytes Maximum allowed decoded size in bytes.
     */
    public static function decode(mixed $dataUrl, int $maxBytes = self::DEFAULT_MAX_BYTES): ?string
    {
        if (!is_string($dataUrl) || $dataUrl === '') {
            return null;
        }

        // Strict shape: data:image/png;base64,<payload>. The media type must be
        // image/png — a data:image/svg+xml or data:text/html payload is rejected
        // here, never sniffed after decoding.
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
            return null;
        }

        $payload = preg_replace('/\s+/', '', $m[1]) ?? '';

        // strict mode → reject any character outside the base64 alphabet.
        $bytes = $payload === '' ? '' : (base64_decode($payload, true) ?: '');

        // Enforce the size ceiling and confirm the decoded bytes really are a
        // PNG by magic bytes, independent of the declared media type above.
        return $bytes !== '' && strlen($bytes) <= $maxBytes && str_starts_with($bytes, self::PNG_MAGIC)
            ? $bytes
            : null;
    }

    /**
     * Whether the given data URL decodes to a valid PNG within the size limit.
     *
     * @param int $maxBytes Maximum allowed decoded size in bytes.
     */
    public static function isValid(mixed $dataUrl, int $maxBytes = self::DEFAULT_MAX_BYTES): bool
    {
        return self::decode($dataUrl, $maxBytes) !== null;
    }
}
