<?php

namespace anvildev\simpleform\helpers;

/**
 * Pure resolver for query-string prefill of visible fields (#316).
 *
 * Unlike the Hidden field (which reads a single configured query param as its
 * whole value source, see {@see HiddenValueResolver}), visible fields are only
 * *pre-filled* from the query string when they opt in — per field, with a
 * form-level default. The resolved values are ordinary render-time defaults:
 * they flow through the same `field_<id> => value` prefill map the resume/edit
 * paths use, so they compose with conditional logic and multi-step forms, and
 * are still validated as untrusted input on submit.
 *
 * Security: only opted-in fields are consulted, so an arbitrary query param can
 * never inject a value into a field the editor did not allow-list. Every method
 * here is side-effect free and takes its inputs explicitly, so the resolution
 * logic is unit-testable without a Craft bootstrap; the thin Craft accessors
 * (the field registry, the request query params) live in
 * {@see \anvildev\simpleform\services\FormRenderService}.
 *
 * @phpstan-type PrefillField array{key: string, handle: string, config: array<string, mixed>, acceptsList: bool}
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class QueryPrefillResolver
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /** Field-config key opting a single field into (true) or out of (false) query prefill. */
    public const CONFIG_ENABLED = 'prefillFromQuery';

    /** Field-config key overriding the query param name (defaults to the field handle). */
    public const CONFIG_PARAM = 'prefillParam';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Build the `field_<id> => value` prefill map for the opted-in fields from
     * the request's query params. Only fields whose resolved opt-in is on and
     * whose param is actually present in the query string contribute an entry, so
     * the map is empty for a plain page load and never carries an unopted field.
     *
     * @param list<PrefillField> $fields the prefillable input fields (never hidden/layout)
     * @param array<string, mixed> $queryParams the request query params
     * @param bool $formDefault the form-level opt-in default
     * @return array<string, string|list<string>> the prefill map (field_<id> => value)
     */
    public static function resolve(array $fields, array $queryParams, bool $formDefault): array
    {
        $prefill = [];

        foreach ($fields as $field) {
            if (!self::isEnabled($field['config'], $formDefault)) {
                continue;
            }

            $param = self::paramName($field['config'], $field['handle']);
            if (!array_key_exists($param, $queryParams)) {
                continue;
            }

            $value = self::sanitizeValue($queryParams[$param], $field['acceptsList']);
            if ($value !== null) {
                $prefill[$field['key']] = $value;
            }
        }

        return $prefill;
    }

    /**
     * Whether a field opts into query-string prefill: an explicit per-field flag
     * wins, otherwise the form-level default applies. Key-presence is the tri-state
     * — an absent flag inherits the default, a present flag (true/false) overrides.
     *
     * @param array<string, mixed> $config the field config
     */
    public static function isEnabled(array $config, bool $formDefault): bool
    {
        if (array_key_exists(self::CONFIG_ENABLED, $config)) {
            return (bool) $config[self::CONFIG_ENABLED];
        }

        return $formDefault;
    }

    /**
     * The query param a field reads from: the configured override when non-empty,
     * else the field's own handle.
     *
     * @param array<string, mixed> $config the field config
     */
    public static function paramName(array $config, string $handle): string
    {
        $param = trim((string) ($config[self::CONFIG_PARAM] ?? ''));

        return $param !== '' ? $param : $handle;
    }

    /**
     * Coerce a raw query value to a prefill-safe scalar or list of scalars. A
     * scalar becomes a string. An array is only ever coerced for a field that
     * genuinely stores a list (`$acceptsList`, e.g. multi-checkbox) — anything
     * else (a scalar field handed an array, an empty list, or a non-scalar item)
     * yields null so no entry is written.
     *
     * An array reaching a scalar field's `renderInput()` is not merely wrong
     * prefill data — every scalar field type casts its value straight to a
     * string (`(string) $value`), which throws "Array to string conversion" for
     * an array and 500s the public form. Rejecting the array here, before it
     * ever reaches a renderer, is the only gate against a visitor-supplied
     * `?<handle>[]=x` taking a prefill-enabled form offline.
     *
     * The value is a render-time default only — the input HTML escapes it and
     * the submit path validates it as untrusted input.
     *
     * @return string|list<string>|null
     */
    public static function sanitizeValue(mixed $value, bool $acceptsList = false): string|array|null
    {
        if (is_array($value)) {
            if (!$acceptsList) {
                return null;
            }

            $clean = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $clean[] = (string) $item;
                }
            }

            return $clean === [] ? null : $clean;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
