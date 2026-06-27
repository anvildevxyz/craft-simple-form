<?php

namespace fabianhaef\simpleform\fields;

use Craft;
use fabianhaef\simpleform\helpers\DialCodes;

/**
 * A telephone field (#123). Renders an `<input type="tel">`, optionally preceded
 * by a config-driven dial-code `<select>` limited to `allowedCountries`. The
 * posted value (an array `{country, number}` when the selector shows, or a flat
 * string when it doesn't) is normalized server-side into a `{raw, e164, country}`
 * map so exports and outbound integrations get a clean `+<digits>` number.
 *
 * Config keys (all in the field `config` JSON, none new to the schema):
 *  - `required` (bool), `placeholder` (string), `default` (string)
 *  - `showCountrySelector` (bool): render the dial-code prefix select.
 *  - `defaultCountry` (ISO alpha-2): preselected entry + assumed country for a
 *     national (non-`+`) number.
 *  - `allowedCountries` (list<string>): restrict the selector; empty = full list.
 *  - `pattern` (string): creator regex over the normalized digits; empty = the
 *     built-in default.
 *  - `minDigits` / `maxDigits`: bounds on the digit count (defaults 7 / 15).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class PhoneFieldType extends FieldType
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    /** The default ISO country assumed when none is configured/posted. */
    public const DEFAULT_COUNTRY = 'CH';

    /** Lower digit-count bound when `minDigits` is not configured. */
    public const DEFAULT_MIN_DIGITS = 7;

    /** Upper digit-count bound when `maxDigits` is not configured (E.164 max). */
    public const DEFAULT_MAX_DIGITS = 15;

    /** Hard cap on digits passed to creator regex patterns (ReDoS guard). */
    private const MAX_PATTERN_DIGITS = 32;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public static function getType(): string
    {
        return 'phone';
    }

    public static function getLabel(): string
    {
        return 'Phone';
    }

    /**
     * The configured default country (upper-cased), falling back to a known
     * curated entry so normalization always has a dial code to apply.
     */
    public function defaultCountry(): string
    {
        $iso = strtoupper(trim((string) ($this->config['defaultCountry'] ?? '')));
        if ($iso !== '' && DialCodes::isKnown($iso)) {
            return $iso;
        }

        return self::DEFAULT_COUNTRY;
    }

    /**
     * The allowed ISO countries (upper-cased), or [] for "no restriction".
     *
     * @return list<string>
     */
    public function allowedCountries(): array
    {
        $raw = $this->config['allowedCountries'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $iso) {
            $iso = strtoupper(trim((string) $iso));
            if ($iso !== '' && DialCodes::isKnown($iso)) {
                $out[] = $iso;
            }
        }

        return array_values(array_unique($out));
    }

    public function showCountrySelector(): bool
    {
        return (bool) ($this->config['showCountrySelector'] ?? false);
    }

    public function minDigits(): int
    {
        $min = $this->config['minDigits'] ?? null;
        return (is_numeric($min) && (int) $min > 0) ? (int) $min : self::DEFAULT_MIN_DIGITS;
    }

    public function maxDigits(): int
    {
        $max = $this->config['maxDigits'] ?? null;
        return (is_numeric($max) && (int) $max > 0) ? (int) $max : self::DEFAULT_MAX_DIGITS;
    }

    /**
     * Normalize a posted value into the persisted `{raw, e164, country}` map, or
     * null when nothing was entered. Handles both the selector array shape and a
     * flat string, strips formatting, and prefixes the country dial code for
     * national numbers.
     *
     * @return array{raw: string, e164: string, country: string}|null
     */
    public function normalize(mixed $value): ?array
    {
        [$country, $raw] = $this->readParts($value);

        if (trim($raw) === '') {
            return null;
        }

        $cleaned = $this->stripFormatting($raw);

        if (str_starts_with($cleaned, '+')) {
            // Already international — keep its own country code, ignore the
            // selector. Country is reported as posted for the audit trail.
            $e164 = '+' . preg_replace('/\D/', '', $cleaned);
        } else {
            $dial = DialCodes::dial($country) ?? DialCodes::dial(self::DEFAULT_COUNTRY) ?? '+';
            $national = preg_replace('/\D/', '', $cleaned) ?? '';
            // Drop a single national trunk-prefix zero (079… → 79…).
            $national = preg_replace('/^0/', '', $national) ?? '';
            $e164 = $dial . $national;
        }

        return [
            'raw' => $raw,
            'e164' => $e164,
            'country' => strtoupper($country),
        ];
    }

    public function normalizeStoredValue(mixed $value): mixed
    {
        return $this->normalize($value);
    }

    public function exportValue(mixed $value): string
    {
        if (is_array($value) && isset($value['e164'])) {
            return (string) $value['e164'];
        }

        return parent::exportValue($value);
    }

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = [];
        [$country, $raw] = $this->readParts($value);
        $hasValue = trim($raw) !== '';

        if (($this->config['required'] ?? false) && !$hasValue) {
            $errors[] = Craft::t('simple-form', 'This field is required.');
        }

        if (!$hasValue) {
            return $errors;
        }

        // Defense against a crafted POST selecting a disallowed country.
        $allowed = $this->allowedCountries();
        $isInternational = str_starts_with($this->stripFormatting($raw), '+');
        if ($allowed !== [] && !$isInternational && !in_array(strtoupper($country), $allowed, true)) {
            $errors[] = Craft::t('simple-form', 'Please select a valid country.');
        }

        $normalized = $this->normalize($value);
        $digits = $normalized !== null ? preg_replace('/\D/', '', $normalized['e164']) ?? '' : '';
        $digitCount = strlen($digits);

        if ($digitCount < $this->minDigits() || $digitCount > $this->maxDigits()) {
            $errors[] = Craft::t('simple-form', 'Enter a valid phone number.');
            return $errors;
        }

        if (!$this->matchesPattern($digits)) {
            $errors[] = Craft::t('simple-form', 'Enter a valid phone number.');
        }

        return $errors;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        [$country, $raw] = $this->readResumeValue($value);
        if ($country === '') {
            $country = $this->defaultCountry();
        }

        if (!$this->showCountrySelector()) {
            // Flat-string shape: the posted value is the field name directly,
            // matching the existing single-control convention.
            $attrs = $this->controlAttributes($name);
            if ($raw !== '') {
                $attrs .= sprintf(' value="%s"', htmlspecialchars($raw));
            }

            return sprintf(
                '<input type="tel" %s inputmode="tel" autocomplete="tel" class="text fullwidth">',
                $attrs,
            );
        }

        $options = '';
        foreach (DialCodes::allowed($this->allowedCountries()) as $iso => $meta) {
            $options .= sprintf(
                '<option value="%s" data-dial="%s"%s>%s (%s)</option>',
                htmlspecialchars($iso),
                htmlspecialchars($meta['dial']),
                $iso === $country ? ' selected' : '',
                htmlspecialchars(DialCodes::label($iso)),
                htmlspecialchars($meta['dial']),
            );
        }

        // The selector posts <name>[country]; the input posts <name>[number].
        $numberAttrs = $this->controlAttributes($name . '[number]');
        if ($raw !== '') {
            $numberAttrs .= sprintf(' value="%s"', htmlspecialchars($raw));
        }

        return sprintf(
            '<div class="sf-phone">'
            . '<select name="%s" id="%s" class="sf-phone-country" aria-label="%s">%s</select>'
            . '<input type="tel" %s inputmode="tel" autocomplete="tel-national" class="text fullwidth">'
            . '</div>',
            htmlspecialchars($name . '[country]'),
            htmlspecialchars($name . '-country'),
            htmlspecialchars(Craft::t('simple-form', 'Country calling code')),
            $options,
            $numberAttrs,
        );
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Read the posted country + number from either the selector array shape or
     * a flat string, defaulting the country to the configured default.
     *
     * @return array{0: string, 1: string} [country ISO, raw number]
     */
    private function readParts(mixed $value): array
    {
        if (is_array($value)) {
            $country = strtoupper(trim((string) ($value['country'] ?? '')));
            $number = (string) ($value['number'] ?? '');
        } else {
            $country = '';
            $number = $value === null ? '' : (string) $value;
        }

        if ($country === '' || !DialCodes::isKnown($country)) {
            $country = $this->defaultCountry();
        }

        return [$country, $number];
    }

    /**
     * Read a stored/resume value back into [country, raw] for re-rendering. A
     * persisted submission stores the `{raw, e164, country}` map; a fresh render
     * gets null or the posted shape.
     *
     * @return array{0: string, 1: string}
     */
    private function readResumeValue(mixed $value): array
    {
        if (is_array($value) && isset($value['raw'])) {
            return [strtoupper(trim((string) ($value['country'] ?? ''))), (string) $value['raw']];
        }

        return $this->readParts($value);
    }

    /**
     * Strip human formatting (spaces, dashes, parens, dots) and a leading
     * `tel:` URI scheme, preserving a leading `+`.
     */
    private function stripFormatting(string $raw): string
    {
        $raw = trim($raw);
        if (stripos($raw, 'tel:') === 0) {
            $raw = substr($raw, 4);
        }

        return preg_replace('/[\s\-().]/', '', $raw) ?? '';
    }

    /**
     * Apply the creator's custom pattern (over the digits) when set, else the
     * built-in default of 7–15 digits with an optional leading `+`.
     */
    private function matchesPattern(string $digits): bool
    {
        if (strlen($digits) > self::MAX_PATTERN_DIGITS) {
            return false;
        }

        $pattern = trim((string) ($this->config['pattern'] ?? ''));
        if ($pattern !== '') {
            $delimited = '/' . str_replace('/', '\/', $pattern) . '/';

            // A malformed creator regex must never fatal a submission; treat an
            // unusable pattern as "no extra constraint".
            $result = @preg_match($delimited, $digits);
            return $result === false ? true : $result === 1;
        }

        return preg_match('/^\d{7,15}$/', $digits) === 1;
    }
}
