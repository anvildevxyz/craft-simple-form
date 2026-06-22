<?php

namespace fabianhaef\simpleform\helpers;

use Craft;

/**
 * Curated ISO-3166-1 alpha-2 → {dial, label} map backing the Phone field's
 * country selector (#123).
 *
 * This is deliberately a small, static, config-grade list — not a
 * libphonenumber dependency. It supplies the dial-code prefix used to turn a
 * national number into an E.164-ish value, plus an English label that is run
 * through `Craft::t('simple-form', …)` at render time so the dropdown localizes
 * per site. The labels are not added to the shipped translation catalogs (they
 * are proper nouns and degrade to English at runtime, which is acceptable).
 *
 * @author Fabian Haefliger
 * @since 5.x
 */
final class DialCodes
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    /**
     * The curated country list, keyed by ISO-3166-1 alpha-2 code.
     *
     * @var array<string, array{dial: string, label: string}>
     */
    private const COUNTRIES = [
        'CH' => ['dial' => '+41', 'label' => 'Switzerland'],
        'DE' => ['dial' => '+49', 'label' => 'Germany'],
        'AT' => ['dial' => '+43', 'label' => 'Austria'],
        'FR' => ['dial' => '+33', 'label' => 'France'],
        'IT' => ['dial' => '+39', 'label' => 'Italy'],
        'ES' => ['dial' => '+34', 'label' => 'Spain'],
        'PT' => ['dial' => '+351', 'label' => 'Portugal'],
        'NL' => ['dial' => '+31', 'label' => 'Netherlands'],
        'BE' => ['dial' => '+32', 'label' => 'Belgium'],
        'LU' => ['dial' => '+352', 'label' => 'Luxembourg'],
        'GB' => ['dial' => '+44', 'label' => 'United Kingdom'],
        'IE' => ['dial' => '+353', 'label' => 'Ireland'],
        'US' => ['dial' => '+1', 'label' => 'United States'],
        'CA' => ['dial' => '+1', 'label' => 'Canada'],
        'AU' => ['dial' => '+61', 'label' => 'Australia'],
        'NZ' => ['dial' => '+64', 'label' => 'New Zealand'],
        'DK' => ['dial' => '+45', 'label' => 'Denmark'],
        'SE' => ['dial' => '+46', 'label' => 'Sweden'],
        'NO' => ['dial' => '+47', 'label' => 'Norway'],
        'FI' => ['dial' => '+358', 'label' => 'Finland'],
        'PL' => ['dial' => '+48', 'label' => 'Poland'],
        'CZ' => ['dial' => '+420', 'label' => 'Czechia'],
        'JP' => ['dial' => '+81', 'label' => 'Japan'],
        'IN' => ['dial' => '+91', 'label' => 'India'],
        'BR' => ['dial' => '+55', 'label' => 'Brazil'],
        'MX' => ['dial' => '+52', 'label' => 'Mexico'],
        'ZA' => ['dial' => '+27', 'label' => 'South Africa'],
    ];

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * The full curated country map, keyed by ISO code.
     *
     * @return array<string, array{dial: string, label: string}>
     */
    public static function all(): array
    {
        return self::COUNTRIES;
    }

    /**
     * Whether the given ISO code is part of the curated list.
     */
    public static function isKnown(string $iso): bool
    {
        return isset(self::COUNTRIES[strtoupper($iso)]);
    }

    /**
     * The dial code (e.g. `+41`) for an ISO code, or null when unknown.
     */
    public static function dial(string $iso): ?string
    {
        return self::COUNTRIES[strtoupper($iso)]['dial'] ?? null;
    }

    /**
     * The translatable label for an ISO code, or the ISO code itself when
     * unknown. The label is run through the plugin translation category so the
     * selector localizes per site.
     */
    public static function label(string $iso): string
    {
        $iso = strtoupper($iso);

        return Craft::t('simple-form', self::COUNTRIES[$iso]['label'] ?? $iso);
    }

    /**
     * The curated map narrowed to the given ISO allowlist (in the allowlist's
     * order). An empty/blank allowlist returns the full list. Unknown codes are
     * skipped. Keys are upper-cased.
     *
     * @param list<string> $allowed
     * @return array<string, array{dial: string, label: string}>
     */
    public static function allowed(array $allowed): array
    {
        $allowed = array_filter(array_map(
            static fn(mixed $iso): string => strtoupper(trim((string) $iso)),
            $allowed,
        ));

        if ($allowed === []) {
            return self::COUNTRIES;
        }

        $out = [];
        foreach ($allowed as $iso) {
            if (isset(self::COUNTRIES[$iso])) {
                $out[$iso] = self::COUNTRIES[$iso];
            }
        }

        return $out;
    }
}
