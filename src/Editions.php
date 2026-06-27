<?php

declare(strict_types=1);

namespace fabianhaef\simpleform;

/**
 * Edition + capability gate — the single source of truth for what each edition
 * may *author*.
 *
 * This is deliberately NOT used to gate rendering, validation, or storage of
 * already-saved data: that path stays edition-blind (see
 * {@see \fabianhaef\simpleform\services\FieldTypeRegistry}) so a Pro form keeps
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
    public const CAP_MULTI_SITE = 'multiSite';
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
        'opinionScale',
        'calculation',
        'repeater',
        'entryRelation',
        'categoryRelation',
        'tagRelation',
        'userRelation',
        'assetRelation',
    ];

    /**
     * Integration-type handles available to Solo. Everything else is Pro.
     *
     * @var list<string>
     */
    public const SOLO_INTEGRATIONS = [
        'webhook',
        'craftElement',
    ];

    /**
     * The active edition handle (what the site is *running as*).
     */
    public static function current(): string
    {
        return Plugin::getInstance()->edition;
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
}
