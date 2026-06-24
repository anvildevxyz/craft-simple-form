<?php

namespace fabianhaef\simpleform\helpers;

/**
 * Pure resolver for a Hidden field's render-time / submit-time value (#124).
 *
 * Every method here is side-effect free and takes its inputs explicitly (the
 * request params, the authenticated user attributes, the cookies) so the
 * resolution logic is unit-testable without a Craft bootstrap. The thin Craft
 * accessors live in {@see \fabianhaef\simpleform\fields\HiddenFieldType}.
 *
 * Resolved values are always plain text: trimmed and bounded by the optional
 * `maxLength`, never interpreted as markup. The caller escapes for output.
 *
 * @phpstan-type HiddenUserAttrs array{email?: ?string, id?: int|string|null, username?: ?string}
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class HiddenValueResolver
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /** The configurable value sources. */
    public const SOURCE_STATIC = 'static';
    public const SOURCE_QUERY = 'query';
    public const SOURCE_USER = 'user';
    public const SOURCE_COOKIE = 'cookie';

    /** The user attributes a `user` source may expose (the three safe ones). */
    public const USER_ATTR_EMAIL = 'email';
    public const USER_ATTR_ID = 'id';
    public const USER_ATTR_USERNAME = 'username';

    /** Default `maxLength` bound applied when none is configured. */
    public const DEFAULT_MAX_LENGTH = 255;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Resolve the value for a `static`/`query`/`cookie` source from the supplied
     * client-influenced inputs, falling back to the configured default.
     *
     * `user` sources are NOT handled here — they must be resolved from the
     * authenticated identity via {@see self::resolveUser()} so a forged value
     * cannot impersonate.
     *
     * @param array<string, mixed> $config the field config
     * @param array<string, mixed> $queryParams the request query params
     * @param array<string, mixed> $cookies cookieName => value
     */
    public static function resolveClientSource(array $config, array $queryParams, array $cookies): string
    {
        $source = (string) ($config['source'] ?? self::SOURCE_STATIC);
        $default = (string) ($config['default'] ?? '');

        $raw = match ($source) {
            self::SOURCE_QUERY => $queryParams[(string) ($config['queryParam'] ?? '')] ?? $default,
            self::SOURCE_COOKIE => $cookies[(string) ($config['cookieName'] ?? '')] ?? $default,
            default => $default,
        };

        return self::sanitize($raw, $config);
    }

    /**
     * Resolve a `user` source from the authenticated user's attributes.
     *
     * The server is the only authority here: pass the real attributes of the
     * currently authenticated user (or null for a guest). A guest yields the
     * configured default. This is the load-bearing anti-spoofing path — the
     * posted value is never consulted.
     *
     * @param array<string, mixed> $config the field config
     * @param HiddenUserAttrs|null $userAttributes
     *        the authenticated user's attributes, or null for a guest
     */
    public static function resolveUser(array $config, ?array $userAttributes): string
    {
        $default = (string) ($config['default'] ?? '');

        if ($userAttributes === null) {
            return self::sanitize($default, $config);
        }

        $attr = (string) ($config['userAttribute'] ?? self::USER_ATTR_EMAIL);
        $value = match ($attr) {
            self::USER_ATTR_ID => $userAttributes['id'] ?? null,
            self::USER_ATTR_USERNAME => $userAttributes['username'] ?? null,
            default => $userAttributes['email'] ?? null,
        };

        return self::sanitize($value !== null && $value !== '' ? $value : $default, $config);
    }

    /**
     * Is this source resolved from the authenticated identity (and therefore
     * must ignore the client-posted value at submit time)?
     */
    public static function isTrustedSource(string $source): bool
    {
        return $source === self::SOURCE_USER;
    }

    /**
     * Coerce an arbitrary resolved value to bounded plain text: cast to string,
     * trim, and clamp to the effective `maxLength`.
     *
     * @param array<string, mixed> $config the field config
     */
    public static function sanitize(mixed $value, array $config): string
    {
        $text = trim(is_scalar($value) ? (string) $value : '');

        return mb_substr($text, 0, self::maxLength($config));
    }

    /**
     * Whether a value is within the effective `maxLength` bound. An empty value
     * is always within bounds. Used by validation to reject an oversized forged
     * POST as a tamper signal (the resolve path already clamps legitimate input).
     *
     * @param array<string, mixed> $config the field config
     */
    public static function withinMaxLength(mixed $value, array $config): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return mb_strlen((string) $value) <= self::maxLength($config);
    }

    /**
     * The effective `maxLength` bound: a positive configured value, else the
     * default. Caps runaway/forged input regardless of config.
     *
     * @param array<string, mixed> $config the field config
     */
    public static function maxLength(array $config): int
    {
        $max = (int) ($config['maxLength'] ?? 0);

        return $max > 0 ? $max : self::DEFAULT_MAX_LENGTH;
    }
}
