# Editions Implementation Spec — Solo ($19) / Standard ($79)

Status: planned — must ship before public listing.

## Goal

Split the single `pro` edition into two:

| Edition | Price | Renewal | Positioning |
|---|---|---|---|
| **Solo** | $19 | ~$9/yr | "A better contact form" — unlimited forms, stored submissions, spam protection, core fields |
| **Standard** | $79 | ~$39/yr | Everything |

Solo wins against the only free options (Craft Contact Form = no storage; Freeform Express = 1 form / 20 fields) on the wall people hit first: **unlimited forms**. Standard undercuts Formie ($99) and Freeform Standard ($149).

## Capability matrix

The **Constant / gate** column names what actually enforces each split in the
shipped `Editions.php` — a `CAP_*` handle where a service/save-gate checks it, or
the list/predicate that does the work. There are **no dead `CAP_*` constants**: a
handle exists only where something enforces or reports it.

| Capability | Solo | Standard | Constant / gate |
|---|---|---|---|
| Unlimited forms | ✅ | ✅ | — |
| Submission storage, CSV export | ✅ | ✅ | — |
| Core fields | ✅ | ✅ | — |
| Email notification + autoresponder | ✅ | ✅ | — |
| Honeypot, rate-limit, 1 captcha provider | ✅ | ✅ | — |
| Webhook + Craft entry/user integration | ✅ | ✅ | `SOLO_INTEGRATIONS` |
| Twig/PHP render, GraphQL read | ✅ | ✅ | — |
| Multi-site / per-site translation | ✅ | ✅ | _ungated (2026-06-27: keeps the "translatable" brand in Solo)_ |
| **Attribution / UTM capture, address autocomplete, analytics** | ✅ | ✅ | _ungated (Solo-free, 2026-07-12 #283)_ |
| **Standard fields** | ❌ | ✅ | `PRO_FIELDS` → `fieldTypeAllowed()` |
| **Conditional logic** | ❌ | ✅ | `CAP_CONDITIONAL_LOGIC` → `blockedNewFormCapabilities()` |
| **Multi-page** | ❌ | ✅ | `CAP_MULTI_PAGE` → `blockedNewFormCapabilities()` |
| **Save & continue later** | ❌ | ✅ | `CAP_SAVE_CONTINUE` → `blockedNewFormCapabilities()` |
| **Logic jumps** | ✅ | ✅ | Solo-free (#283 split decision) — not gated |
| **Conversational render mode** | ❌ | ✅ | `CAP_CONVERSATIONAL` → `blockedNewFormModes()` (#283) |
| **Quiz scoring** (per-option scores / grade bands) | ❌ | ✅ | `CAP_QUIZ` → `blockedNewFormModes()` (#283) |
| **Partial capture** | ❌ | ✅ | `CAP_PARTIAL_CAPTURE` → `blockedNewFormModes()` (#283) |
| **Submission approval workflow** | ✅ | ✅ | Solo-free (#283 split decision) — `enableWorkflow` not gated |
| **Payment coupons** | ❌ | ✅ | `CouponsController::actionSave` create gate (#283) |
| **3rd-party integrations** (Slack/Discord/CRM/Sheets) | ❌ | ✅ | `SOLO_INTEGRATIONS` → `integrationAllowed()` |
| **Payments** (Commerce) | ❌ | ✅ | `payment` ∈ `PRO_FIELDS` → `fieldTypeAllowed()` |
| **Akismet, denylists** | ❌ | ✅ | `PRO_ENABLE_SETTINGS` / `PRO_MODE_SETTINGS` / `PRO_CONFIG_SETTINGS` → `blocksProSettingChange()` |
| **PDF attachments** | ❌ | ✅ | `CAP_PDF` → `PdfService::isAvailable()` |
| **Audit log, retention automation** | ❌ | ✅ | `CAP_GOVERNANCE` → `AuditService::log()` |
| **MCP server, forms-as-code** | ❌ | ✅ | `CAP_DEV_TOOLS` → `McpController` |

No-new-escalation applies to every gated row: a form/site already using a Standard
feature keeps it after a downgrade; Solo only refuses *adding new* Standard usage. The
gates that need a posted-vs-stored diff (`blockedNewProFields`,
`blockedNewFormCapabilities`, `blockedNewFormModes`, `blocksProSettingChange`, the
coupon create-only check) all compare against the previously-saved state.

### Field split

- **Solo:** text, email, textarea, number, phone, date, select, checkbox, radio, hidden, consent, file, name, address, heading, divider, html
- **Standard (`PRO_FIELDS`):** `signature`, `payment`, `rating`, `opinion`, `calculation`, `repeater`, `entry`, `category`, `tag`, `user`, `asset`

### Integration split

- **Solo (`SOLO_INTEGRATIONS`):** `webhook`, `craft-element`
- **Standard:** slack, discord, mailchimp, activeCampaign, hubspot, pipedrive, googleSheets

## Core principle: gate authoring, never runtime

The registry (`FieldTypeRegistry`, `IntegrationTypeRegistry`) **stays complete in every edition.** `getFieldType()` / `typeHandles()` feed validation, storage, and front-end render of *already-saved* data. If Solo dropped a type from the registry, existing forms would fail validation and lose data on downgrade.

Two layers:
1. **Registry (complete, edition-blind):** can always render/validate/store any field or integration.
2. **Capability layer (edition-aware):** governs what you can *add or newly enable*. Consulted by the CP palette, save validation, GraphQL/MCP create paths, and settings UI.

### Downgrade semantics (Standard → Solo)

A form already using Standard features when the license drops to Solo:
- **Front-end render + submissions keep working.** Never break a live form or drop data.
- **CP shows a non-blocking "Standard feature in use" banner**, listing what requires Standard.
- **Save preserves existing Standard config** (does not strip it) but **rejects *new* Standard escalation.** Rule: a save is allowed if it introduces **no Standard capability beyond what the previously-saved revision already had.**

This "no new escalation" rule (vs "must remove Standard") avoids both data loss and a support flood.

Three deliberate exceptions run edition checks at **execution time** (not authoring): conditional submit-message resolution (`SubmissionService`, falls back to the default message), PDF generation (`PdfService::isAvailable()`, notifications send without the attachment), and audit logging (`AuditService::log()` no-ops). These pause on Solo and resume on Standard — they are back-office services, so pausing them never breaks a visitor-facing form. By contrast Akismet, denylists, and retention deliberately keep running after a downgrade so data hygiene never regresses. The published copy (README/CHANGELOG) states this scoping explicitly (#286).

## The keystone: `src/Editions.php`

The single source of truth for what each edition may *author* — never used to
gate rendering/validation of already-saved data (that path is edition-blind; see
`FieldTypeRegistry`). It is **default-open**: only an *explicit* Solo license is
treated as non-Standard (`isStandard()` returns `true` unless the active edition is exactly
`solo`), so an unresolvable plugin instance (teardown paths) never fatals.

Read `src/Editions.php` for the authoritative shape. The load-bearing surface:

- Handles: `SOLO` / `PRO`; the `CAP_*` capability handles (every one is checked
  somewhere — conditional-logic, multi-page, save-continue, conversational,
  quiz, partial-capture, PDF, governance, dev-tools).
- Lists: `PRO_FIELDS` (Standard field-type handles), `SOLO_INTEGRATIONS`,
  `PRO_ENABLE_SETTINGS` (off-switch settings — Akismet/denylists/retention;
  `enableWorkflow` is Solo-free), `PRO_MODE_SETTINGS`, `PRO_CONFIG_SETTINGS`,
  `PRO_FORM_MODES` (the scalar form toggles: conversational / quiz / partial
  capture).
- Predicates: `isStandard()`, `fieldTypeAllowed()`, `integrationAllowed()`.
- No-new-escalation diffs (posted-vs-stored):
  - `blockedNewProFields($types, $existing)` — count-based per Standard field type.
  - `blockedNewFormCapabilities($items, $saveResume, $existing, $existingSaveResume)`
    — field-set-derived caps: conditional logic, multi-page, save-continue.
  - `blockedNewFormModes($posted, $stored)` — scalar form modes keyed by
    `CAP_*` handle: only a mode switched *on* anew is blocked.
  - `blocksProSettingChange($field, $stored, $posted)` — settings off-switches /
    verdict modes / frozen config.
- `can($capability)` is a thin `isStandard()` alias (kept for the Twig
  `craft.simpleForm.can(...)` surface); the real gating is the diff methods above.

## Enforcement checklist (call-sites)

Each is "filter the palette / reject *new* escalation," never "remove from registry."

1. **`Plugin::editions()`** → `return [Editions::SOLO, Editions::PRO];` and add `EDITION_SOLO = 'solo'`. Order matters — Solo first = lower tier. (`src/Plugin.php:166`)
2. **CP field palette** — locate the builder's field-type data source (controller endpoint or CP asset-bundle JSON; not in a Twig template — likely `web/assets/cp` JS fed by a controller). Filter with `Editions::fieldTypeAllowed()`; mark Standard entries with an upsell badge rather than hiding, so they advertise Standard.
3. **Integrations "New integration" list** — `IntegrationsController` (`getAllTypes()` at lines 35/58/94/132/240). Filter with `Editions::integrationAllowed()`.
4. **Form save validation** — `FormStructureService` / `FormsController` save path. Compute the form's required capabilities (Standard field present? conditional rule present? >1 page? enabled on >1 site? save-continue? payment?) and reject any that exceed both the edition **and** the previously-saved revision's capability set.
5. **GraphQL** — `gql/mutations/FormMutations` create/update → same capability validation as #4.
6. **MCP tools** — `CreateFormTool`, `UpdateFormTool`, `AddFieldTool` → same validation; also gate the whole MCP server behind `CAP_DEV_TOOLS` (it already keys off `enableMcp` setting — add an edition check).
7. **Settings screen** — grey out Standard-only sections (Akismet, denylists, payments, PDF, MCP, retention/governance) with an inline "Standard" upsell; treat the underlying feature services as inert on Solo even if a stale setting is enabled (defence in depth — check `Editions::can()` in `AkismetService`, `DenylistService`, `PaymentsService`, `PdfService`, `AuditService`, `RetentionService`, MCP).
8. **Twig** — expose `craft.simpleForm.edition` and `craft.simpleForm.can('...')` for theme authors.

### Traps

- **Do NOT gate `FieldTypeRegistry::typeHandles()`** — it is the canonical valid-type set for `FieldsService`/`FieldSyncService` validation. Gating it makes existing Standard fields invalid. Gate only the *palette* and *save-escalation*.
- **Multi-site is ungated** (decision 2026-06-27) — there is no `CAP_MULTI_SITE`; per-site translation stays in Solo. (Earlier drafts of this spec proposed a site-count limit; it was dropped.)
- **Conditional logic detection:** scan field `conditional` config across all fields; a single active rule trips `CAP_CONDITIONAL_LOGIC` (see `Editions::usesConditionalLogic()`).

## Phasing

- **P0 — scaffolding:** `Editions.php`, `editions()` + `EDITION_SOLO`, Twig var, unit tests for the capability matrix. No behaviour change yet (all installs run Standard). *Low risk, mergeable alone.*
- **P1 — fields:** palette filter + save-escalation guard for Standard fields. Integration test: existing Standard field still renders/submits on Solo.
- **P2 — integrations:** palette filter + save guard.
- **P3 — form caps:** conditional logic, multi-page, multi-site, save-continue, payments.
- **P4 — settings + service inertness:** grey-out UI + runtime `can()` guards in the 6 Standard services.
- **P5 — programmatic-path parity:** DONE. Findings: GraphQL mutations only handle *submissions* (no form authoring), and their submit path inherits the P4 service gating (payments/spam). MCP is entirely Standard-gated (P4 → 404 on Solo), so its authoring tools are unreachable on Solo. The real remaining authoring bypass was **form import / forms-as-code apply** → a shared `FormPortabilityService::assertEditionAllows()` now enforces the field + form-capability escalation rules on `import()` and `applyToExistingForm()`, covering CP import, console import, and `forms/apply`.
- **P6 — downgrade UX:** DONE. `FormsController::proFeaturesInUse()` computes the Standard features an existing form already uses while on Solo (Standard fields, conditional logic, multi-page, save & continue); the form editor shows a non-blocking "Standard features in use" warning banner listing them. The no-new-escalation save rule (P1/P3) means those features are preserved on save but can't be extended.
- **P7 — tests, docs, store config:** DONE (code/tests/docs). Both-edition completeness smoke test (`tests/smoke/EditionMatrixCest.php`) green; the test harness pins Standard via `Helper\Integration` so both suites cover Standard while the dedicated edition tests flip to Solo; CHANGELOG + README edition table added. **Remaining manual step (not code):** configure the Solo ($19) and Standard ($79) editions + prices in the Plugin Store developer console before listing, and set the DDEV dev site's edition explicitly for CP Playwright smoke work.

## Test plan

- Unit: `Editions::can()` / `fieldTypeAllowed()` / `integrationAllowed()` matrix for both editions.
- Unit: form capability computation (a form → its required caps).
- Integration: Solo install **renders + accepts a submission** for a form containing a Standard field/conditional/2nd page (downgrade safety).
- Integration: Solo **rejects** adding a Standard field / conditional rule / 2nd site via save, GraphQL, and MCP.
- Existing locale-parity + settings tests stay green (new strings added to all 8 catalogs).

## Addendum — post-1.0 feature batch (#283, 2026-07-12)

Assigns + enforces the features shipped after the original two-edition split.
Conservative default ("advanced features = Standard"); the price split is a
recommendation to revisit at listing time.

**Standard (gated, no-new-escalation):**

| Feature | Choke point | Handle |
|---|---|---|
| Conversational render mode (`Form::$renderMode = 'conversational'`) | `FormsController::actionSave` (+ import, banner) via `blockedNewFormModes()` | `CAP_CONVERSATIONAL` |
| Quiz scoring (per-option scores / grade bands) | same | `CAP_QUIZ` |
| Partial capture (`capturePartials`) | same | `CAP_PARTIAL_CAPTURE` |
| Payment coupons | `CouponsController::actionSave` — create-only gate (new `CouponModel` blocked on Solo) | — |

**Solo (free, ungated):** attribution / UTM capture, address autocomplete,
submission analytics, **logic jumps**, and the **submission approval workflow**
(#283 split decision — the latter two were considered for Standard but assigned to
Solo).

**No-new-escalation** is enforced by snapshotting the *stored* form/field/setting
state before the posted values overwrite it, then diffing:

- Scalar form modes: `FormsController::actionSave` snapshots
  `$priorModes = [CAP_CONVERSATIONAL => renderMode==='conversational', CAP_QUIZ => quizMode, CAP_PARTIAL_CAPTURE => capturePartials]`
  before reading the POST, then `blockedNewFormModes(postedModes, $priorModes)`
  blocks only a mode switched *on* anew. Same diff in
  `FormPortabilityService::assertEditionAllows()` (against the target form's
  stored modes) and `proFeaturesInUse()` (against empty = "report all in use").
- Coupons: only a brand-new coupon (`CouponModel::$id === null`) is blocked on
  Solo; edits/toggles of existing coupons and `CouponsService::evaluate()` at
  checkout stay edition-blind.

**Defense-in-depth (MCP):** `AddFieldTool` and `UpdateFieldTool` now also reject
Standard capabilities introduced via a field's `config` (conditional logic, logic
jumps, 2nd-page placement), diffed against the form's / field's existing config —
mirroring the CP save. The whole MCP server is already Standard-gated at
`McpController` (`CAP_DEV_TOOLS`), so these are belt-and-suspenders; the other
mutating tools (`CreateFormTool`, `UpdateFormTool`, delete/reorder,
`CategorizeSubmissionsTool`) carry no Standard-authoring surface.

**Constant cleanup:** removed the never-checked `CAP_PRO_FIELDS`,
`CAP_INTEGRATIONS`, `CAP_PAYMENTS`, `CAP_SPAM_ADVANCED` (those splits are enforced
by `PRO_FIELDS`/`SOLO_INTEGRATIONS`/`PRO_ENABLE_SETTINGS`, not a `CAP_*` handle).
