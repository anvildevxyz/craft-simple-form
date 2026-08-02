<?php

namespace anvildev\simpleform;

/**
 * Edition + capability gate — the single source of truth for what each edition
 * may *author*.
 *
 * This is deliberately NOT used to gate rendering, validation, or storage of
 * already-saved data: that path stays edition-blind (see
 * {@see \anvildev\simpleform\services\FieldTypeRegistry}) so a Standard form keeps
 * working after a downgrade to Solo. The gate only governs adding/enabling new
 * Standard capabilities. See docs/editions.md.
 *
 * Default-open: anything other than an explicit Solo license is treated as Standard,
 * so the active edition has to be *explicitly* Solo before anything is
 * restricted.
 *
 * @since 2.14.0
 */
final class Editions
{
    public const SOLO = 'solo';

    /**
     * The paid edition.
     *
     * Note the handle collides with Craft's own fallback literal: `Plugins`
     * reads `$info['edition'] ?? 'standard'` and then coerces anything not in
     * {@see \anvildev\simpleform\Plugin::editions()} to the first entry (solo).
     * Those two steps fail in *opposite* directions, and the second one is the
     * dangerous one:
     *
     *  - stored edition **missing** (hand-edited project config, a partial
     *    deploy) → the 'standard' literal, which is in our list, so it survives
     *    and the install runs as the paid edition. `PluginTrait::$edition`
     *    defaults to the same literal.
     *  - stored edition **present but unrecognized** (a typo, or a handle from
     *    an older build) → coerced to solo, so a *paid* install silently drops
     *    to the free edition with no error anywhere. Nothing below ever sees
     *    the original value, so the default-open behaviour of
     *    {@see self::current()} cannot compensate; `simple-form/doctor` reports
     *    the coercion instead.
     *
     * This is a deliberate, accepted trade for the edition name; don't "fix" it
     * by renaming without checking the Store, whose issued licenses carry the
     * handle.
     */
    public const STANDARD = 'standard';

    // Capability handles ------------------------------------------------------
    // Every listed capability is Standard-only. A handle exists only where something
    // actually enforces or reports it — a save gate, a service guard, or the
    // downgrade banner — so there are no dead constants to drift out of sync.
    public const CAP_CONDITIONAL_LOGIC = 'conditionalLogic';
    public const CAP_MULTI_PAGE = 'multiPage';
    public const CAP_SAVE_CONTINUE = 'saveAndContinue';
    public const CAP_CONVERSATIONAL = 'conversational';
    public const CAP_QUIZ = 'quiz';
    public const CAP_PARTIAL_CAPTURE = 'partialCapture';
    public const CAP_PDF = 'pdf';
    public const CAP_GOVERNANCE = 'governance';
    public const CAP_DEV_TOOLS = 'devTools';

    /**
     * Field-type handles reserved for Standard. Everything else is available to Solo.
     *
     * @var list<string>
     */
    public const STANDARD_FIELDS = [
        'signature',
        'payment',
        'rating',
        'opinion',
        'calculation',
        'repeater',
        'entry',
        'category',
        'tag',
        'user',
        'asset',
    ];

    /**
     * Integration-type handles available to Solo. Everything else is Standard.
     *
     * @var list<string>
     */
    public const SOLO_INTEGRATIONS = [
        'webhook',
        'craft-element',
    ];

    /**
     * The Standard features' "off switches": on Solo these stay operable so a site
     * downgraded from Standard can still turn a running Standard feature off, but the only
     * change accepted is toward off ({@see self::blocksStandardSettingChange()}) — never
     * enabling, and never changing a still-on value (e.g. shrinking the retention
     * window to be more destructive). A numeric setting counts as "on" when > 0.
     *
     * @var list<string>
     */
    public const STANDARD_ENABLE_SETTINGS = [
        'enableAkismet',
        'enableDenylists',
        'retainSubmissionsDays',
        'retainAuditLogDays',
    ];

    /**
     * Standard spam "verdict mode" settings (flag vs block). On Solo these stay editable
     * but may only move toward the non-destructive {@see self::SAFE_MODE} value:
     * a downgraded site can de-escalate a still-running filter from silently
     * dropping ('block') to flagging for review ('flag') — so it can stop losing
     * legitimate submissions — but can't escalate to 'block'.
     *
     * @var list<string>
     */
    public const STANDARD_MODE_SETTINGS = [
        'akismetMode',
        'denylistMode',
    ];

    /** The non-destructive verdict mode a Solo site may always select. */
    public const SAFE_MODE = 'flag';

    /**
     * Companion configuration for the Standard features above (API key, denylist
     * contents, the anonymize mode). On Solo these are frozen entirely — the
     * settings save keeps their stored value and the CP renders them read-only — so
     * a downgraded site can't *reconfigure* a still-running Standard feature (e.g.
     * broaden a denylist). The single source of truth the settings templates read
     * to decide which inputs to disable.
     *
     * @var list<string>
     */
    public const STANDARD_CONFIG_SETTINGS = [
        'akismetApiKey',
        'blockedKeywords',
        'blockedEmails',
        'blockedIps',
        'anonymizeInsteadOfDelete',
    ];

    /**
     * The active edition handle (what the site is *running as*).
     */
    public static function current(): string
    {
        // Default-open: if the plugin instance isn't resolvable yet (e.g. a gated
        // static reached from a teardown/uninstall path, where Yii's getInstance()
        // can return null), treat the edition as Standard rather than fatalling on a
        // null dereference.
        /** @var Plugin|null $plugin */
        $plugin = Plugin::getInstance();
        return $plugin?->edition ?? self::STANDARD;
    }

    /**
     * Default-open: only an explicit Solo edition is treated as non-Standard.
     */
    public static function isStandard(?string $edition = null): bool
    {
        return ($edition ?? self::current()) !== self::SOLO;
    }

    /**
     * Whether the given (or active) edition may use a capability. Every gated
     * capability ({@see self::CAP_*}) is Standard-only; Solo's allowances are the
     * always-on baseline plus the field/integration predicates below.
     */
    public static function can(string $capability, ?string $edition = null): bool
    {
        return self::isStandard($edition);
    }

    /**
     * Whether the given (or active) edition may *add* a field of this type.
     * Existing fields of any type always render — this gates the builder palette
     * and save-time escalation only.
     */
    public static function fieldTypeAllowed(string $handle, ?string $edition = null): bool
    {
        return self::isStandard($edition) || !in_array($handle, self::STANDARD_FIELDS, true);
    }

    /**
     * Whether the given (or active) edition may *add* an integration of this type.
     */
    public static function integrationAllowed(string $handle, ?string $edition = null): bool
    {
        return self::isStandard($edition) || in_array($handle, self::SOLO_INTEGRATIONS, true);
    }

    /**
     * Whether a settings save must reject this change to a Standard setting on a non-Standard
     * edition, allowing only changes that can't escalate a Standard feature:
     *
     *  - {@see self::STANDARD_ENABLE_SETTINGS} (off switches / thresholds): only turning
     *    off (posted <= 0) or leaving it exactly as stored is allowed; enabling, or
     *    changing a still-on threshold (e.g. shrinking the retention window to
     *    delete more aggressively), is blocked. "On" means > 0.
     *  - {@see self::STANDARD_MODE_SETTINGS} (spam verdict modes): only the safe
     *    {@see self::SAFE_MODE} value, or no change, is allowed; escalating to a
     *    destructive value (e.g. 'block') is blocked.
     */
    public static function blocksStandardSettingChange(string $field, mixed $stored, mixed $posted, ?string $edition = null): bool
    {
        if (self::isStandard($edition)) {
            return false;
        }

        if (in_array($field, self::STANDARD_ENABLE_SETTINGS, true)) {
            return (float) $posted > 0 && (float) $posted !== (float) $stored;
        }

        if (in_array($field, self::STANDARD_MODE_SETTINGS, true)) {
            return $posted !== self::SAFE_MODE && $posted !== $stored;
        }

        return false;
    }

    /**
     * The Standard field-type handles in $types that are *newly introduced* relative
     * to $existing — i.e. the escalations a save must reject on Solo. The
     * comparison is by *count* per type: a downgraded form keeps the Standard fields it
     * already has (no-new-escalation rule), but adding another field of an
     * already-present Standard type is still an escalation and is blocked.
     *
     * @param list<string> $types the field-type handles being saved
     * @param list<string> $existing the field-type handles already on the form
     * @return list<string> distinct blocked handles ([] when allowed)
     */
    public static function blockedNewStandardFields(array $types, array $existing, ?string $edition = null): array
    {
        if (self::isStandard($edition)) {
            return [];
        }

        $existingCounts = array_count_values($existing);
        $blocked = [];
        foreach (array_count_values($types) as $type => $count) {
            if (!in_array((string) $type, self::STANDARD_FIELDS, true)) {
                continue;
            }
            if ($count > ($existingCounts[$type] ?? 0)) {
                $blocked[] = (string) $type;
            }
        }

        return array_values(array_unique($blocked));
    }

    /**
     * Whether any field in a builder/DB field set *actively* uses conditional
     * logic — an enabled visibility or conditional-required block carrying a
     * non-empty rule set. Mirrors the gating in
     * {@see \anvildev\simpleform\helpers\ConditionalEvaluator} (the single source
     * of truth): a block with rules but `enabled: false` is inert at render time,
     * so it must not count as Standard usage here either.
     *
     * @param iterable<array<string, mixed>> $items
     */
    public static function usesConditionalLogic(iterable $items): bool
    {
        foreach ($items as $item) {
            $conditional = (is_array($item['config'] ?? null) ? $item['config'] : [])['conditional'] ?? null;
            if (!is_array($conditional)) {
                continue;
            }
            // Visibility block: active only when enabled with a non-empty rule set.
            if (
                !empty($conditional['enabled'])
                && isset($conditional['rules']) && is_array($conditional['rules']) && $conditional['rules'] !== []
            ) {
                return true;
            }
            // Conditional-required block: independent, with its own enabled flag.
            $required = $conditional['required'] ?? null;
            if (
                is_array($required) && !empty($required['enabled'])
                && isset($required['rules']) && is_array($required['rules']) && $required['rules'] !== []
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a field set spans more than one page (any field on `config.page`
     * >= 2). Mirrors {@see \anvildev\simpleform\helpers\FormSteps}.
     *
     * @param iterable<array<string, mixed>> $items
     */
    public static function usesMultiPage(iterable $items): bool
    {
        foreach ($items as $item) {
            $config = is_array($item['config'] ?? null) ? $item['config'] : [];
            $page = $config['page'] ?? 1;
            if (is_numeric($page) && (int) $page >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Standard form-level capabilities newly introduced by the posted state
     * relative to the already-saved state — the escalations Solo must reject.
     * Returns capability handles ({@see self::CAP_*}); [] on Standard or when nothing
     * new is enabled. A capability already present on the saved form is allowed
     * through so a downgraded form stays editable.
     *
     * @param iterable<array<string, mixed>> $items posted field set
     * @param iterable<array<string, mixed>> $existing saved field set
     * @return list<string>
     */
    public static function blockedNewFormCapabilities(
        iterable $items,
        bool $saveResume,
        iterable $existing,
        bool $existingSaveResume,
        ?string $edition = null,
    ): array {
        if (self::isStandard($edition)) {
            return [];
        }

        $blocked = [];
        if (self::usesConditionalLogic($items) && !self::usesConditionalLogic($existing)) {
            $blocked[] = self::CAP_CONDITIONAL_LOGIC;
        }
        if (self::usesMultiPage($items) && !self::usesMultiPage($existing)) {
            $blocked[] = self::CAP_MULTI_PAGE;
        }
        if ($saveResume && !$existingSaveResume) {
            $blocked[] = self::CAP_SAVE_CONTINUE;
        }

        return $blocked;
    }

    /**
     * The Standard form-level "modes" — scalar form toggles that aren't derivable from
     * the field set, so they can't ride along in {@see self::blockedNewFormCapabilities()}.
     *
     * @var list<string>
     */
    public const STANDARD_FORM_MODES = [
        self::CAP_CONVERSATIONAL,
        self::CAP_QUIZ,
        self::CAP_PARTIAL_CAPTURE,
    ];

    /**
     * The Standard form-level modes newly switched on by the posted form state relative
     * to the stored one — the escalations Solo must reject. Each argument maps a
     * mode handle ({@see self::STANDARD_FORM_MODES}) to whether that state has it on. A
     * mode already on in the stored state is allowed through (no-new-escalation),
     * so a downgraded form keeps rendering/scoring; only turning one on anew is
     * blocked.
     *
     * @param array<string, bool> $posted mode handle => on in the posted state
     * @param array<string, bool> $stored mode handle => on in the stored state
     * @return list<string> the blocked mode handles ([] when allowed)
     */
    public static function blockedNewFormModes(array $posted, array $stored, ?string $edition = null): array
    {
        if (self::isStandard($edition)) {
            return [];
        }

        $blocked = [];
        foreach (self::STANDARD_FORM_MODES as $mode) {
            if (!empty($posted[$mode]) && empty($stored[$mode])) {
                $blocked[] = $mode;
            }
        }

        return $blocked;
    }
}
