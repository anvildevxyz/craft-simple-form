# Editions Implementation Spec — Solo ($19) / Pro ($79)

Status: planned — must ship before public listing.

## Goal

Split the single `pro` edition into two:

| Edition | Price | Renewal | Positioning |
|---|---|---|---|
| **Solo** | $19 | ~$9/yr | "A better contact form" — unlimited forms, stored submissions, spam protection, core fields |
| **Pro** | $79 | ~$39/yr | Everything |

Solo wins against the only free options (Craft Contact Form = no storage; Freeform Express = 1 form / 20 fields) on the wall people hit first: **unlimited forms**. Pro undercuts Formie ($99) and Freeform Pro ($149).

## Capability matrix

| Capability | Solo | Pro | Constant |
|---|---|---|---|
| Unlimited forms | ✅ | ✅ | — |
| Submission storage, CSV export | ✅ | ✅ | — |
| Core fields (16) | ✅ | ✅ | — |
| Email notification + autoresponder | ✅ | ✅ | — |
| Honeypot, rate-limit, 1 captcha provider | ✅ | ✅ | — |
| Webhook + Craft entry/user integration | ✅ | ✅ | — |
| Twig/PHP render, GraphQL read | ✅ | ✅ | — |
| **Pro fields (12)** | ❌ | ✅ | `CAP_PRO_FIELDS` |
| **Conditional logic** | ❌ | ✅ | `CAP_CONDITIONAL_LOGIC` |
| **Multi-page** | ❌ | ✅ | `CAP_MULTI_PAGE` |
| **Save & continue later** | ❌ | ✅ | `CAP_SAVE_CONTINUE` |
| **Multi-site / per-site translation** | ✅ | ✅ | _(ungated — decision 2026-06-27: keeps the "translatable" brand in Solo)_ |
| **3rd-party integrations** (Slack/Discord/CRM/Sheets) | ❌ | ✅ | `CAP_INTEGRATIONS` |
| **Payments** (Commerce) | ❌ | ✅ | `CAP_PAYMENTS` |
| **Akismet, denylists, spam review queue** | ❌ | ✅ | `CAP_SPAM_ADVANCED` |
| **PDF attachments** | ❌ | ✅ | `CAP_PDF` |
| **Audit log, retention automation, analytics** | ❌ | ✅ | `CAP_GOVERNANCE` |
| **MCP server, forms-as-code** | ❌ | ✅ | `CAP_DEV_TOOLS` |

### Field split

- **Solo (16):** text, email, textarea, number, phone, date, select, checkbox, radio, hidden, consent, file, name, address, heading, divider, html
- **Pro (12):** signature, payment, rating, opinionScale, calculation, repeater, entry/category/tag/user/asset relation

### Integration split

- **Solo:** `webhook`, `craftElement`
- **Pro:** slack, discord, mailchimp, activeCampaign, hubspot, pipedrive, googleSheets

## Core principle: gate authoring, never runtime

The registry (`FieldTypeRegistry`, `IntegrationTypeRegistry`) **stays complete in every edition.** `getFieldType()` / `typeHandles()` feed validation, storage, and front-end render of *already-saved* data. If Solo dropped a type from the registry, existing forms would fail validation and lose data on downgrade.

Two layers:
1. **Registry (complete, edition-blind):** can always render/validate/store any field or integration.
2. **Capability layer (edition-aware):** governs what you can *add or newly enable*. Consulted by the CP palette, save validation, GraphQL/MCP create paths, and settings UI.

### Downgrade semantics (Pro → Solo)

A form already using Pro features when the license drops to Solo:
- **Front-end render + submissions keep working.** Never break a live form or drop data.
- **CP shows a non-blocking "Pro feature in use" banner**, listing what requires Pro.
- **Save preserves existing Pro config** (does not strip it) but **rejects *new* Pro escalation.** Rule: a save is allowed if it introduces **no Pro capability beyond what the previously-saved revision already had.**

This "no new escalation" rule (vs "must remove Pro") avoids both data loss and a support flood.

## The keystone: `src/Editions.php`

```php
<?php

declare(strict_types=1);

namespace anvildev\simpleform;

/**
 * Edition + capability gate. The single source of truth for what each edition
 * may *author*. Never used to gate rendering/validation of already-saved data —
 * that path is edition-blind (see FieldTypeRegistry).
 */
final class Editions
{
    public const SOLO = 'solo';
    public const PRO = 'pro';

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

    /** Field-type handles reserved for Pro. */
    public const PRO_FIELDS = [
        'signature', 'payment', 'rating', 'opinionScale', 'calculation', 'repeater',
        'entryRelation', 'categoryRelation', 'tagRelation', 'userRelation', 'assetRelation',
    ];

    /** Integration handles available to Solo; everything else is Pro. */
    public const SOLO_INTEGRATIONS = ['webhook', 'craftElement'];

    /** Capabilities Solo gets. Pro gets everything. */
    private const SOLO_CAPS = [];

    public static function current(): string
    {
        // Active edition (what the site is *running as*). Craft sets ->edition
        // from the license, selectable in dev/trial.
        return Plugin::getInstance()?->edition ?? self::PRO;
    }

    public static function isPro(): bool
    {
        return self::current() === self::PRO;
    }

    public static function can(string $capability): bool
    {
        return self::isPro() || in_array($capability, self::SOLO_CAPS, true);
    }

    public static function fieldTypeAllowed(string $handle): bool
    {
        return self::isPro() || !in_array($handle, self::PRO_FIELDS, true);
    }

    public static function integrationAllowed(string $handle): bool
    {
        return self::isPro() || in_array($handle, self::SOLO_INTEGRATIONS, true);
    }
}
```

## Enforcement checklist (call-sites)

Each is "filter the palette / reject *new* escalation," never "remove from registry."

1. **`Plugin::editions()`** → `return [Editions::SOLO, Editions::PRO];` and add `EDITION_SOLO = 'solo'`. Order matters — Solo first = lower tier. (`src/Plugin.php:166`)
2. **CP field palette** — locate the builder's field-type data source (controller endpoint or CP asset-bundle JSON; not in a Twig template — likely `web/assets/cp` JS fed by a controller). Filter with `Editions::fieldTypeAllowed()`; mark Pro entries with an upsell badge rather than hiding, so they advertise Pro.
3. **Integrations "New integration" list** — `IntegrationsController` (`getAllTypes()` at lines 35/58/94/132/240). Filter with `Editions::integrationAllowed()`.
4. **Form save validation** — `FormStructureService` / `FormsController` save path. Compute the form's required capabilities (Pro field present? conditional rule present? >1 page? enabled on >1 site? save-continue? payment?) and reject any that exceed both the edition **and** the previously-saved revision's capability set.
5. **GraphQL** — `gql/mutations/FormMutations` create/update → same capability validation as #4.
6. **MCP tools** — `CreateFormTool`, `UpdateFormTool`, `AddFieldTool` → same validation; also gate the whole MCP server behind `CAP_DEV_TOOLS` (it already keys off `enableMcp` setting — add an edition check).
7. **Settings screen** — grey out Pro-only sections (Akismet, denylists, payments, PDF, MCP, retention/governance) with an inline "Pro" upsell; treat the underlying feature services as inert on Solo even if a stale setting is enabled (defence in depth — check `Editions::can()` in `AkismetService`, `DenylistService`, `PaymentsService`, `PdfService`, `AuditService`, `RetentionService`, MCP).
8. **Twig** — expose `craft.simpleForm.edition` and `craft.simpleForm.can('...')` for theme authors.

### Traps

- **Do NOT gate `FieldTypeRegistry::typeHandles()`** — it is the canonical valid-type set for `FieldsService`/`FieldSyncService` validation. Gating it makes existing Pro fields invalid. Gate only the *palette* and *save-escalation*.
- **Multi-site detection:** a form "uses" multi-site if enabled on >1 site OR has any per-site translated content. Base the Solo limit on *site-enablement count*, not on whether translations happen to differ.
- **Conditional logic detection:** scan field `conditional` config across all fields; a single rule trips `CAP_CONDITIONAL_LOGIC`.

## Phasing

- **P0 — scaffolding:** `Editions.php`, `editions()` + `EDITION_SOLO`, Twig var, unit tests for the capability matrix. No behaviour change yet (all installs run Pro). *Low risk, mergeable alone.*
- **P1 — fields:** palette filter + save-escalation guard for Pro fields. Integration test: existing Pro field still renders/submits on Solo.
- **P2 — integrations:** palette filter + save guard.
- **P3 — form caps:** conditional logic, multi-page, multi-site, save-continue, payments.
- **P4 — settings + service inertness:** grey-out UI + runtime `can()` guards in the 6 Pro services.
- **P5 — GraphQL + MCP parity:** same guards on programmatic create/update paths.
- **P6 — downgrade UX:** "Pro feature in use" banner + the no-new-escalation save rule.
- **P7 — tests, docs, store config:** edition matrix tests green; docs/upgrading note; configure $19/$79 editions + prices in the Plugin Store console.

## Test plan

- Unit: `Editions::can()` / `fieldTypeAllowed()` / `integrationAllowed()` matrix for both editions.
- Unit: form capability computation (a form → its required caps).
- Integration: Solo install **renders + accepts a submission** for a form containing a Pro field/conditional/2nd page (downgrade safety).
- Integration: Solo **rejects** adding a Pro field / conditional rule / 2nd site via save, GraphQL, and MCP.
- Existing locale-parity + settings tests stay green (new strings added to all 8 catalogs).
