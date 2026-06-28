<?php

declare(strict_types=1);

namespace anvildev\simpleform;

/**
 * Edition + capability gate — the single source of truth for what each edition
 * may *author*.
 *
 * This is deliberately NOT used to gate rendering, validation, or storage of
 * already-saved data: that path stays edition-blind (see
 * {@see \anvildev\simpleform\services\FieldTypeRegistry}) so a Pro form keeps
 * working after a downgrade to Solo. The gate only governs adding/enabling new
 * Pro capabilities. See docs/launch/editions-implementation.md.
 *
 * Default-open: anything other than an explicit Solo license is treated as Pro,
 * so the active edition has to be *explicitly* Solo before anything is
 * restricted.
 *
 * @since 2.14.0
 */
final class Editions
{
    public const SOLO = 'solo';
    public const PRO = 'pro';

    // Capability handles ------------------------------------------------------
    public const CAP_PRO_FIELDS = 'proFields';
    public const CAP_CONDITIONAL_LOGIC = 'conditionalLogic';
    public const CAP_MULTI_PAGE = 'multiPage';
    public const CAP_SAVE_CONTINUE = 'saveAndContinue';
    public const CAP_INTEGRATIONS = 'integrations';
    public const CAP_PAYMENTS = 'payments';
    public const CAP_SPAM_ADVANCED = 'spamAdvanced';
    public const CAP_PDF = 'pdf';
    public const CAP_GOVERNANCE = 'governance';
    public const CAP_DEV_TOOLS = 'devTools';

    /**
     * Field-type handles reserved for Pro. Everything else is available to Solo.
     *
     * @var list<string>
     */
    public const PRO_FIELDS = [
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
     * Integration-type handles available to Solo. Everything else is Pro.
     *
     * @var list<string>
     */
    public const SOLO_INTEGRATIONS = [
        'webhook',
        'craft-element',
    ];

    /**
     * The Pro features' "off switches": on Solo these stay operable so a site
     * downgraded from Pro can still turn a running Pro feature off, but the only
     * change accepted is toward off ({@see self::blocksProSettingChange()}) — never
     * enabling, and never changing a still-on value (e.g. shrinking the retention
     * window to be more destructive). A numeric setting counts as "on" when > 0.
     *
     * @var list<string>
     */
    public const PRO_ENABLE_SETTINGS = [
        'enableAkismet',
        'enableDenylists',
        'retainSubmissionsDays',
    ];

    /**
     * Companion configuration for the Pro features above (API keys, modes, denylist
     * contents, the anonymize mode). On Solo these are frozen entirely — the
     * settings save keeps their stored value and the CP renders them read-only — so
     * a downgraded site can't *reconfigure* a still-running Pro feature (e.g. switch
     * it to silently block, or broaden a denylist). The single source of truth the
     * settings templates read to decide which inputs to disable.
     *
     * @var list<string>
     */
    public const PRO_CONFIG_SETTINGS = [
        'akismetApiKey',
        'akismetMode',
        'denylistMode',
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
        // can return null), treat the edition as Pro rather than fatalling on a
        // null dereference.
        /** @var Plugin|null $plugin */
        $plugin = Plugin::getInstance();
        return $plugin?->edition ?? self::PRO;
    }

    /**
     * Default-open: only an explicit Solo edition is treated as non-Pro.
     */
    public static function isPro(?string $edition = null): bool
    {
        return ($edition ?? self::current()) !== self::SOLO;
    }

    /**
     * Whether the given (or active) edition may use a capability. Every gated
     * capability ({@see self::CAP_*}) is Pro-only; Solo's allowances are the
     * always-on baseline plus the field/integration predicates below.
     */
    public static function can(string $capability, ?string $edition = null): bool
    {
        return self::isPro($edition);
    }

    /**
     * Whether the given (or active) edition may *add* a field of this type.
     * Existing fields of any type always render — this gates the builder palette
     * and save-time escalation only.
     */
    public static function fieldTypeAllowed(string $handle, ?string $edition = null): bool
    {
        return self::isPro($edition) || !in_array($handle, self::PRO_FIELDS, true);
    }

    /**
     * Whether the given (or active) edition may *add* an integration of this type.
     */
    public static function integrationAllowed(string $handle, ?string $edition = null): bool
    {
        return self::isPro($edition) || in_array($handle, self::SOLO_INTEGRATIONS, true);
    }

    /**
     * Whether a settings save must reject this change to a Pro "off switch"
     * ({@see self::PRO_ENABLE_SETTINGS}) on a non-Pro edition. The only changes
     * allowed on Solo are turning the feature *off* (posted not "on") or leaving it
     * exactly as stored; everything else — enabling it, or changing a still-on
     * value such as shrinking the retention window to delete more aggressively — is
     * blocked. A value counts as "on" when > 0 (covers both booleans and numeric
     * thresholds).
     */
    public static function blocksProSettingChange(string $field, mixed $stored, mixed $posted, ?string $edition = null): bool
    {
        if (self::isPro($edition) || !in_array($field, self::PRO_ENABLE_SETTINGS, true)) {
            return false;
        }

        // Allowed: posted is off (<= 0), or unchanged. Blocked: any on-and-different
        // value (newly enabling, or reconfiguring a still-on threshold).
        return (float) $posted > 0 && (float) $posted !== (float) $stored;
    }

    /**
     * The Pro field-type handles in $types that are *newly introduced* relative
     * to $existing — i.e. the escalations a save must reject on Solo. The
     * comparison is by *count* per type: a downgraded form keeps the Pro fields it
     * already has (no-new-escalation rule), but adding another field of an
     * already-present Pro type is still an escalation and is blocked.
     *
     * @param list<string> $types the field-type handles being saved
     * @param list<string> $existing the field-type handles already on the form
     * @return list<string> distinct blocked handles ([] when allowed)
     */
    public static function blockedNewProFields(array $types, array $existing, ?string $edition = null): array
    {
        if (self::isPro($edition)) {
            return [];
        }

        $existingCounts = array_count_values($existing);
        $blocked = [];
        foreach (array_count_values($types) as $type => $count) {
            if (!in_array((string) $type, self::PRO_FIELDS, true)) {
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
     * so it must not count as Pro usage here either.
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
     * The Pro form-level capabilities newly introduced by the posted state
     * relative to the already-saved state — the escalations Solo must reject.
     * Returns capability handles ({@see self::CAP_*}); [] on Pro or when nothing
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
        if (self::isPro($edition)) {
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
}
