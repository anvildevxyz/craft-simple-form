# 02 — Type Consolidation (Round 3 full re-audit, 2026-06-24)

Scope: full re-audit of all 210 PHP files under `src/` (Craft 5, PHP 8.3,
PHPStan L7). Builds on `docs/cleanup/02-type-consolidation.md` and
`docs/cleanup/delta/02-type-consolidation.md`. Covers the ~39 commits since
2026-06-21 (payments, forms-as-code, DX events/JS hooks/GraphQL SDL, tabbed
editor, Install-migration collapse). Research-only — **no source modified**.

Three read-only agents swept services/controllers/console/jobs/web,
fields/models/helpers/elements/events, and integrations/mcp/gql/captcha/pdf/
widgets respectively; every claim below was independently re-grepped and the
exact lines confirmed by the author.

---

## (a) Critical assessment of type-sharing health

**Type discipline remains excellent and has, if anything, improved since the
prior passes.** The two structural findings from the original audit are still
fully resolved: field-type handles reference `::getType()` everywhere (no bare
`=== 'email'` discriminators remain), and audit actions/targets go through
`AuditService::ACTION_*` / `TARGET_*`. All 5 delta `@phpstan-type` patches
(`Token`, `EmailAttachment`, `DialCode`, `RepeaterInnerField`, `CsvColumn`)
landed and are in use.

The plugin consistently uses the right idioms:

- **Status/key holder classes**: `SubmissionStatus`, `DispatchStatus`,
  `NotificationModel::RECIPIENT_*`, and a rich set of `Form` consts
  (`POST_SUBMIT_ACTIONS`, `GUEST_LIMIT_*`/`GUEST_LIMIT_KEYS`,
  `DUPLICATE_KEY_*`/`DUPLICATE_KEYS`, `CLOSED_*`). The `CLOSED_*`,
  `GUEST_LIMIT_*`, `DUPLICATE_KEY_*`, conditional-evaluator
  `OPERATORS`/`ACTION_*`/`MATCH_*`, `HeadingFieldType::LEVELS`,
  `RatingFieldType::ICON_STYLES`, `HubSpotIntegration::OBJECT_TYPES`,
  `RepeaterFieldType::ALLOWED_INNER_TYPES` const sets are all genuinely used at
  their comparison sites — these were checked and need no action.
- **Registry + interface + `getType()`/`handle()`** for field types, integration
  types, and captcha providers, so type handles are owned by classes, not
  scattered consts.
- **Shared `@phpstan-type` aliases** imported via `@phpstan-import-type`:
  `SubmissionData`, `ResolvedFieldRow`, `PaymentResult`, `EmailAttachment`,
  the MCP shapes, GQL shapes, plus the delta additions.

What's left is **incremental**, not structural. The single most valuable item is
the cross-file `{label, value}` option shape (Finding 1), which the *delta* pass
explicitly deferred only because its consumer files were out of the delta's diff
scope — this full re-audit puts every one of them in scope, so it is now
actionable. Everything else is a handful of 2-site magic-string discriminators
and one within-file `array{...}` repetition with cross-file drift.

A few agent suggestions were **rejected on verification** and are listed in §(d):
single-occurrence shapes that aliasing would only add ceremony to
(`CompositeFieldType` subfield shape, dispatch-health shape, phone tuples), and
PHP↔JS const mirrors that are intentional and comment-flagged.

No concurrent-branch hazard this round: the prior `FieldType.php`/migration merge
seams were resolved (migrations collapsed into a single `Install`), so the
field/registry files are safe to touch.

---

## (b) Findings

| # | File:line | Description | Why (drift risk) | Recommended change | Conf | Risk |
|---|-----------|-------------|------------------|--------------------|------|------|
| 1 | `integrations/CraftElementIntegration.php:365,376,388`; `integrations/support/ElementMapping.php:21,64`; `widgets/SubmissionWidgetTrait.php:19`; `gql/resolvers/FormGqlResolver.php:187` | `list<array{label: string, value: string}>` CP/GraphQL option shape repeated **7×** across 4 files, no shared alias | 7 identical declarations across unrelated subsystems; any change (e.g. add `disabled`) drifts silently | Add `@phpstan-type SelectOption array{label: string, value: string}` once; `@phpstan-import-type` it into the 4 files (alias-only, no DTO) | HIGH | Low (annotation-only) |
| 2 | `services/SubmissionService.php:46,250` (identical), `:351` (subset) | `array{submission: Submission\|null, errors: array<string, mixed>\|null, data?: array<string, mixed>, paymentRedirectUrl?: string}` repeated; line 351 is the `data`/`paymentRedirectUrl`-less subset | The primary submission-result contract spelled out 3× in one file; a new key (e.g. `warnings`) risks drifting between `createFromRequest()`, `submit()`, and the editor path | Add `@phpstan-type SubmissionResult …` to the class docblock; use `SubmissionResult` at 46/250; for 351 either reuse it (all extra keys optional) or keep its narrower local shape | HIGH | Low (annotation-only) |
| 3 | `services/FormRenderService.php:604` (`@return`), `:631` (`@param`) | `array{values: array<string, mixed>, token: string}` save-&-resume prefill shape — producer/consumer pair, no alias | Aliasing guarantees the `_resumeValues()` return and `_buildResume()` param can't drift | Add `@phpstan-type ResumePrefill array{values: array<string, mixed>, token: string}`; apply to both sites | HIGH | Low (annotation-only) |
| 4 | `fields/HiddenFieldType.php:175,187,203` (strict) vs `helpers/HiddenValueResolver.php:78` (loose) | User-attr snapshot shape repeated 3× in `HiddenFieldType` as `array{email: ?string, id: int\|null, username: ?string}`, drifted from resolver's `array{email?: ?string, id?: int\|string\|null, username?: ?string}` | Within-file 3× repetition *plus* cross-file drift (optional keys + `id` widened to `int\|string`) — exactly the drift an alias prevents | Add `@phpstan-type HiddenUserAttrs …` (use resolver's looser superset) on `HiddenValueResolver`; import into `HiddenFieldType`; reconcile `id` type | HIGH | Low (annotation-only; reconcile `id` when unifying) |
| 5 | `services/FormRenderService.php:569,571,576` | Layout-block handles `'heading'`/`'divider'`/`'html'` compared as bare literals; canonical `FieldTypeRegistry::layoutTypeHandles()` (built from `isInput() === false`) already exists | Render path hardcodes the 3 non-input handles instead of the registry truth; a new layout block drifts | Add `FieldTypeRegistry::LAYOUT_TYPES = ['heading','divider','html']` (or reference `layoutTypeHandles()`); `in_array($type, …, true)` | MED | Low |
| 6 | `elements/Form.php:448,449,450`; `services/SubmissionService.php:728,734`; `services/FormPortabilityService.php:446,806,807`; `controllers/FormsController.php:122` | `postSubmitAction` value literals `'url'`/`'entry'`/`'message'` used raw in match/`===`/defaults; only the *list* const `Form::POST_SUBMIT_ACTIONS` exists — no individual value consts | List const exists but the discriminator values are still bare strings across 4 files; same idiom as the existing `GUEST_LIMIT_*`/`DUPLICATE_KEY_*`/`CLOSED_*` value consts | Add `Form::POST_SUBMIT_MESSAGE='message'`, `POST_SUBMIT_URL='url'`, `POST_SUBMIT_ENTRY='entry'`; define `POST_SUBMIT_ACTIONS` from them; reference at call sites | MED | Low (values storage-backed; spelling-only) |
| 7 | `fields/PaymentFieldType.php:51`; `services/PaymentsService.php:90` | `amountType` discriminator `'fixed'`/`'field'` compared as bare literals in 2 files, no const | 2-site cross-file discriminator with no canonical home; a rename needs grep | Add `PaymentFieldType::AMOUNT_TYPE_FIXED='fixed'`, `AMOUNT_TYPE_FIELD='field'`; reference at both sites | MED | Low |
| 8 | `integrations/WebhookIntegration.php:86,99` (`['POST','PUT']`); `:87,105` (`['json','format']` json/form) | HTTP-method set and payload-format set inlined twice each within one file, no const | Validation `range` and the runtime check restate the same set; could drift apart | Add `WebhookIntegration::METHODS = ['POST','PUT']` and `FORMATS = ['json','form']`; reference in both rule + check | LOW | Trivial (single-file) |
| 9 | `integrations/support/ElementMapping.php:41` | `list<array{label: string, value: string, data: array{section: string}}>` — enriched variant of Finding 1 | One-off superset; documents that the `SelectOption` alias won't cover it | Leave as a local shape (or `SelectOption & {data: …}` informally); do not force into the shared alias | LOW | n/a |

---

## (c) HIGH-CONFIDENCE RECOMMENDATIONS

Each is implementable without further investigation. Findings 1–4 are HIGH;
5–7 (MED) are included as clearly-scoped optional follow-ups.

### Rec 1 — `SelectOption` shared alias (Finding 1)
- **New alias:** `@phpstan-type SelectOption array{label: string, value: string}`
- **Declare on:** a neutral home both areas already relate to. Recommend
  `src/integrations/support/ElementMapping.php` (it owns the most occurrences),
  or `src/gql/types/SimpleFormObjectType.php` if you prefer it near other GQL
  aliases. Do **not** create a runtime DTO and do **not** add a `src/types/`
  folder — these are Craft-conventional `selectField`/GraphQL option arrays.
- **Import + replace `list<array{label: string, value: string}>` with `list<SelectOption>` at:**
  - `src/integrations/CraftElementIntegration.php:365, 376, 388`
  - `src/integrations/support/ElementMapping.php:21, 64`
  - `src/widgets/SubmissionWidgetTrait.php:19`
  - `src/gql/resolvers/FormGqlResolver.php:187`
- **Leave untouched** (intentional variants): `ElementMapping.php:41`
  (`+ data: array{section}`) and `fields/FormField.php:99` (`value: int`).
- **Why safe:** PHPStan resolves the alias to the identical type → `composer
  check` stays green; zero runtime change.

### Rec 2 — `SubmissionResult` alias (Finding 2)
- **New alias** on `src/services/SubmissionService.php` class docblock:
  `@phpstan-type SubmissionResult array{submission: \fabianhaef\simpleform\elements\Submission|null, errors: array<string, mixed>|null, data?: array<string, mixed>, paymentRedirectUrl?: string}`
- **Replace the inline `@return array{…}` with `@return SubmissionResult` at:**
  `SubmissionService.php:46` and `:250`.
- **Line 351:** its shape is the strict subset `array{submission, errors}`.
  Either reuse `SubmissionResult` (the extra keys are optional, so it validates)
  or leave 351's narrower local shape. Recommend reusing for one source of truth.

### Rec 3 — `ResumePrefill` alias (Finding 3)
- **New alias** on `src/services/FormRenderService.php` class docblock:
  `@phpstan-type ResumePrefill array{values: array<string, mixed>, token: string}`
- **Apply at:** `FormRenderService.php:604` (`@return ResumePrefill`) and
  `:631` (`@param ResumePrefill $prefill`).

### Rec 4 — `HiddenUserAttrs` alias (Finding 4)
- **New alias** on `src/helpers/HiddenValueResolver.php` class docblock (use the
  resolver's looser superset, which is the correct contract):
  `@phpstan-type HiddenUserAttrs array{email?: ?string, id?: int|string|null, username?: ?string}`
- **Import into** `src/fields/HiddenFieldType.php` and replace the 3 inline
  shapes at `:175, :187, :203` with `HiddenUserAttrs` (or `HiddenUserAttrs|null`
  for 175/187). Replace the `:78` `@param` in the resolver with the alias too.
- **Reconcile:** `HiddenFieldType` currently produces `id: int|null` from
  `$user->id`; widening its declared type to the alias is safe (superset). No
  runtime change.

### Rec 5 (MED) — `FieldTypeRegistry::LAYOUT_TYPES` (Finding 5)
- **Add** `public const LAYOUT_TYPES = ['heading', 'divider', 'html'];` to
  `src/services/FieldTypeRegistry.php` (alongside `OPTION_TYPES` etc.).
- **Replace** the three `$type === 'heading'/'divider'/'html'` branches at
  `src/services/FormRenderService.php:569, 571, 576`. (Note these are an
  if/elseif chain that dispatches to different render helpers, so keep the
  branch structure; just swap the literals for `FieldTypeRegistry::LAYOUT_TYPES`
  membership or named consts if you split them.) Lowest-effort form: leave the
  branches but source the truth from the new const for the storage-frozen list.

### Rec 6 (MED) — `Form` post-submit value consts (Finding 6)
- **Add to** `src/elements/Form.php`:
  `public const POST_SUBMIT_MESSAGE = 'message';`,
  `POST_SUBMIT_URL = 'url'`, `POST_SUBMIT_ENTRY = 'entry'`, and redefine
  `public const POST_SUBMIT_ACTIONS = [self::POST_SUBMIT_MESSAGE, self::POST_SUBMIT_URL, self::POST_SUBMIT_ENTRY];`
- **Reference** at: `Form.php:448` (`=== self::POST_SUBMIT_URL`), `:449`
  (same), `:450` (`=== self::POST_SUBMIT_ENTRY`); `SubmissionService.php:728`
  (`POST_SUBMIT_URL =>`), `:734` (`POST_SUBMIT_ENTRY =>`);
  `FormPortabilityService.php:446, 806` (`=== POST_SUBMIT_ENTRY`), `:807`
  (default `POST_SUBMIT_MESSAGE`); `FormsController.php:122` (fallback
  `POST_SUBMIT_MESSAGE`). Matches the established `GUEST_LIMIT_*`/`DUPLICATE_KEY_*`
  value-const idiom on the same class.

### Rec 7 (MED) — `PaymentFieldType` amount-type consts (Finding 7)
- **Add to** `src/fields/PaymentFieldType.php`:
  `public const AMOUNT_TYPE_FIXED = 'fixed';`,
  `public const AMOUNT_TYPE_FIELD = 'field';`
- **Reference** at: `PaymentFieldType.php:51`
  (`=== self::AMOUNT_TYPE_FIXED`) and `PaymentsService.php:90`
  (`=== PaymentFieldType::AMOUNT_TYPE_FIELD`).

---

## (d) Looks consolidatable but should NOT be (verified rejections)

- **`CompositeFieldType.php:43` `array{label, kind, required}`** — agent flagged
  as repeated; it is a **single** occurrence (the inline construction at the
  method body is the value, not a second type declaration). Aliasing a once-used
  shape is ceremony. Skip. (`kind` already uses `CompositeSubField::KIND_*`.)
- **`IntegrationsService.php:576` dispatch-health shape** (`array{total, success,
  failed, pending, lastStatus, lastDispatchedAt, lastResponseCode}`) — single
  definition, single consumer (`ListIntegrationsTool`). Not repeated. Skip.
- **`PhoneFieldType.php:123` `{raw, e164, country}`; `:267,:291` `array{0,1}`
  tuples; `ConsentFieldType.php:104` consent record** — single (or method-local,
  tightly-scoped) shapes. Skip.
- **PHP↔JS const mirrors** (`FieldTypeRegistry::OPTION_TYPES`/`RELATION_TYPES`/
  `ALLOWED_INNER_TYPES` ↔ `form-builder.js`) — intentional, comment-flagged
  cross-language duplication; PHP side is already the single source. No action.
- **`FieldTypeRegistry::OPTION_TYPES/SCALE_TYPES/RELATION_TYPES/ASSET_TYPES`
  built from `::getType()`** — prior reports rated this secondary/low; values are
  storage-frozen. Skip.
- **`CraftElementIntegration::ELEMENT_TYPES = ['entry','user']`** — an
  intentional *subset* of `RELATION_TYPES` (only entry/user are createable).
  Don't collapse the subset into the relation set.
- **Per-provider captcha `VERIFY_URL`/`TOKEN_PARAM`, `DEFAULT_PROVIDER`** —
  per-provider vendor facts; the abstract base + per-class const is correct.
- **Abstract integration bases** (`AbstractCrmIntegration`,
  `AbstractMarketingIntegration`, `AbstractChatIntegration`,
  `AbstractGoogleIntegration`, captcha base) — verified all properly extended by
  concretes; no reimplementation.
- **Field-type handles → PHP enum; `SubmissionStatus`/`DispatchStatus` merge;
  migration literals → runtime consts** — rejected in prior audits, still
  correct. Do not revisit.
- **`HubSpotIntegration.php:49` `=== 'contacts'`** — one bare literal vs the
  `OBJECT_TYPES` const; a `CONTACTS` const would be marginal. Optional at best;
  not recommended on its own.

---

## Verdict

**9 findings, 4 HIGH (all annotation-only `@phpstan-type` aliases) + 3 MED const
extractions + 2 LOW.** The headline item is `SelectOption` (7 sites, 4 files) —
deferred by the delta only for scope reasons and now fully actionable. Recs 1–4
are gate-safe (PHPStan resolves aliases to identical types). Recs 5–7 are
spelling-only const extractions matching idioms already on the same classes.
Prior structural findings (field-type handles, audit consts) remain resolved; 9
tempting-but-wrong consolidations are rejected with reasons in §(d).
