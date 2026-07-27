# 02 — Type Consolidation Audit (Simple Form)

Scope: PHP type/shape/const consolidation across `src/` (223 PHP files, Craft 5,
PHPStan L7). PHP has no TS-style type files, so this maps to: repeated array
shapes that want a DTO/typed model, magic-string literals that want an enum or
class const, parallel const groups that want a single canonical home, and
repeated `array{...}` PHPDoc shapes that want a shared `@phpstan-type` alias.

Research-only. No source files were modified.

---

## 1. Critical assessment

**This codebase is already well above average on type discipline.** The most
important consolidations are *already done*:

- Status value-objects are centralized: `SubmissionStatus`
  (`src/elements/SubmissionStatus.php`) and `DispatchStatus`
  (`src/integrations/DispatchStatus.php`) are single-source holder classes with
  `all()` / `isValid()`, and their docblocks explicitly note that migrations
  keep their own literals on purpose.
- Field types, integration types, and captcha providers all use a
  **registry + interface + `getType()`/`handle()`** pattern
  (`FieldTypeRegistry`, `IntegrationTypeRegistry`, captcha providers), so the
  type handles are owned by their classes, not scattered consts.
- There is already a healthy set of shared `@phpstan-type` aliases —
  `SubmissionData` (Submission), `ResolvedFieldRow` (FieldQueryHelper),
  `TokenArray` (McpToken), `McpError`, `ResourceDescriptor`,
  `GqlFieldDefinitionMap` — and they are imported across consumers with
  `@phpstan-import-type`. This is exactly the right idiom.

So the remaining findings are **incremental**, not structural. The single most
valuable one is the field-type handle literals (Finding A): the registry owns
the *list* of types, but individual handle strings (`'email'`, `'file'`,
`'payment'`, `'select'`) are still hardcoded as discriminators inside services.
Everything else is low/medium polish and several "tempting but don't" cases are
called out in §3 to prevent over-engineering.

Be wary: this plugin has concurrent in-flight branches (per project memory,
`FieldType.php` and migration seams have known merge conflicts). Any
consolidation that touches `src/fields/*` or `src/services/FieldTypeRegistry.php`
should be sequenced carefully against that work.

---

## 2. Findings

### Finding A — Field-type handle literals used as discriminators — **High**

**The literal/shape:** field-type handles (`'email'`, `'text'`, `'file'`,
`'payment'`, `'select'`, `'checkbox'`, `'radio'`, `'number'`) appear both as
return values of each field type's `getType()` **and** as bare string literals
in `===` comparisons across services. The registry owns the *set* of types and
the grouping consts (`OPTION_TYPES`, `SCALE_TYPES`, `RELATION_TYPES` in
`FieldTypeRegistry`), but the individual handles are duplicated at call sites.

**Sites (handle literals as discriminators):**
- `src/services/SubmissionService.php:867` — `($entry['type'] ?? '') === 'email'`
- `src/services/PaymentsService.php:43` — `$field['type'] === 'payment'`
- `src/services/PaymentsService.php:208` — `$field['type'] === 'email'`
- `src/services/FormRenderService.php:641` — `($field['type'] ?? null) === 'file'`
- `src/services/DenylistService.php:272` — `($entry['type'] ?? '') === 'email'`
- `src/services/EmailService.php:336` — `$fieldData['type'] === 'file'`
- `src/services/AkismetService.php:118,120` — `=== 'email'`, `=== 'text'`
- `src/services/FieldSyncService.php:217` — `$type === 'select'`
- `src/fields/RepeaterFieldType.php:35` — `ALLOWED_INNER_TYPES = ['text', 'email', 'number', 'select']`
- `src/services/FieldTypeRegistry.php:45,54,62` — `OPTION_TYPES`, `SCALE_TYPES`, `RELATION_TYPES` literal arrays
- Source of truth (one each): `EmailFieldType::getType()` → `'email'`,
  `FileFieldType` → `'file'`, `PaymentFieldType` → `'payment'`,
  `SelectFieldType` → `'select'`, etc.

**Canonical home:** the handle already lives in each field type's `getType()`.
Make call sites reference it instead of a literal, e.g.
`$field['type'] === EmailFieldType::getType()` /
`PaymentFieldType::getType()` / `FileFieldType::getType()`. This matches the
guideline "declare on the owning class, reference everywhere else" and matches
how `FieldSyncService` already does it for `RepeaterFieldType::getType()` and
`CalculationFieldType::getType()` (FieldSyncService.php:77,108,116). The grouping
arrays in `FieldTypeRegistry` could likewise be built from `::getType()`
references for drift-safety, but that is secondary.

**RISK:** Low. Pure literal→`::getType()` substitution; the values are stable
and migration-backed. No behavior change. Main caution is the concurrent
`FieldType.php`/registry branch — coordinate before editing `src/fields/*`.
Do **not** turn handles into a PHP `enum` (see §3) — `getType()` returning a
plain string is the established Craft-idiomatic contract here.

---

### Finding B — Audit action / target-type literals have no canonical home — **High**

**The literal/shape:** `AuditService::log(string $action, string $targetType, …)`
(`src/services/AuditService.php:23`) is always called with free-form magic
strings. The action namespace is a de-facto enum (`'<entity>.<verb>'`) with no
declared constants, so a typo silently produces an un-filterable audit row, and
the `AuditController` filter (`AuditController.php:26`) has no canonical list to
validate against.

**Sites (action, targetType):**
- `FormsController.php:219` — `'form.save'`, `'form'`
- `FormsController.php:301` — `'form.export'`, `'form'`
- `FormsController.php:363` — `'form.delete'`, `'form'`
- `FormCloneService.php:270` — `'form.duplicate'`, `'form'`
- `FormPortabilityService.php:147` — `'form.import'`, `'form'`
- `SubmissionService.php:361` — `'submission.edit'`, `'submission'`
- `SubmissionService.php:723` — `'submission.status'`, `'submission'`
- `IntegrationsService.php:184` — `'integration.create'` / `'integration.save'`, `'integration'`
- `IntegrationsService.php:344` — `'integration.delete'`, `'integration'`
- `NotificationsService.php:86` — `'notification.create'` / `'notification.save'`, `'notification'`
- `NotificationsService.php:101` — `'notification.delete'`, `'notification'`

**Canonical home:** `public const` action keys on `AuditService` (the owner),
e.g. `AuditService::ACTION_FORM_SAVE = 'form.save'`, and a small set of
target-type consts (`TARGET_FORM = 'form'`, etc.) — mirroring the existing
`SubmissionStatus`/`DispatchStatus` holder style already established in this
plugin. Callers reference `AuditService::ACTION_FORM_SAVE`.

**RISK:** Low. The stored string values stay identical, so existing audit rows
and filter behavior are unaffected; this is a refactor of *how* the literal is
spelled at the call site, not the value. ~11 call sites across 6 files.

---

### Finding C — `array{label: string, value: …}` option-list shape unaliased — **Medium**

**The shape:** the CP/GraphQL "option" / "selectable source" pair
`array{label: string, value: string}` (sometimes `value: int`, occasionally
`+ data: array{section: string}`) recurs across unrelated subsystems with no
shared `@phpstan-type`.

**Sites:**
- `src/integrations/support/ElementMapping.php:21,41,64`
- `src/integrations/CraftElementIntegration.php:365,376,388`
- `src/gql/resolvers/FormGqlResolver.php:187`
- `src/fields/FormField.php:99` (`value: int`)
- `src/widgets/SubmissionWidgetTrait.php:19`

**Canonical home:** a `@phpstan-type SelectOption array{label: string, value: string}`
alias (PHPDoc only, no runtime class). A reasonable home is a neutral helper
the relevant areas already import, or `FormField` if you want it field-centric.
Consumers `@phpstan-import-type` it. **Do not** make it a real DTO class — these
are Craft-conventional `['label' => …, 'value' => …]` option arrays consumed
directly by `selectField`/GraphQL; a value object adds ceremony with no payoff.

**RISK:** Low (annotation-only). The catch: the `value` element is sometimes
`int` (`FormField.php:99`) and one variant carries an extra `data` key
(`ElementMapping.php:41`), so a single alias won't cover every site — either
parametrize informally or accept that 1–2 sites keep a local shape. Marginal
value; only worth it if you're already touching these files.

---

### Finding D — Hidden-field user-attribute shape `array{email, id, username}` — **Medium**

**The shape:** `array{email: ?string, id: int|null, username: ?string}`
describing a captured logged-in-user snapshot.

**Sites:**
- `src/fields/HiddenFieldType.php:175,187,203`
- `src/helpers/HiddenValueResolver.php:78` (slightly looser:
  `array{email?: ?string, id?: int|string|null, username?: ?string}`)

**Canonical home:** a `@phpstan-type` alias on `HiddenValueResolver` (the helper
both files relate to), imported by `HiddenFieldType`. The two declarations have
drifted (optional keys + `int|string` on `id`), which is exactly the drift a
shared alias prevents.

**RISK:** Low (annotation-only). Reconcile the optionality/`id` type when
unifying — the looser resolver signature is probably the correct superset.

---

### Finding E — Repeater inner-field-definition shape — **Medium**

**The shape:** `array{handle: string, type: string, label: string, config: array<string, mixed>}`
for a repeater's resolved inner field definitions, plus a near-twin
`array{handle: string, label: string, config: array<string, mixed>}` (no `type`).

**Sites:**
- `src/fields/RepeaterFieldType.php:86,134,294`
- `src/mcp/tools/support/FieldOps.php:119` (the `type`-less twin)
- compare also `src/fields/CompositeFieldType.php:43`
  (`array{label, kind, required}`) — a *different* sub-field shape, do not merge.

**Canonical home:** `@phpstan-type RepeaterInnerField …` on `RepeaterFieldType`,
imported by `FieldOps`. Keep it scoped to the repeater domain.

**RISK:** Low (annotation-only), but confidence Medium because `FieldOps` omits
`type` — confirm whether that's intentional before sharing one alias, or keep
two.

---

### Finding F — Google Sheets `{handle, column}` mapping shape — **Low**

**The shape:** `list<array{handle: string, column: string}>` (3 occurrences, all
in one file: `GoogleSheetsIntegration.php:341,391,440`).

**Canonical home:** a file-local `@phpstan-type` on `GoogleSheetsIntegration`.

**RISK:** Trivial. Single-file repetition; a local alias is pure readability.
Borderline — only do it opportunistically.

---

### Finding G — `RELATION_TYPES` const vs. relation-source map duplication — **Low**

**The shape:** the relation type set `['entry', 'category', 'tag', 'user', 'asset']`
exists as `FieldTypeRegistry::RELATION_TYPES` (line 62), but the relation→source
resolver in `FormsController.php:449-453` re-enumerates the same five handles in
a `match`/array map, and `CraftElementIntegration::ELEMENT_TYPES`
(line 44) defines its own `['entry', 'user']` subset. The individual relation
field types own their handles via `getType()` (`EntryRelationFieldType` →
`'entry'`, etc.).

**Canonical home:** these are *parallel* but not strictly duplicate — the
controller map binds handle→Craft API which is genuinely controller-specific,
and `ELEMENT_TYPES` is an intentional subset (only entry/user are createable).
Best lever is having `RELATION_TYPES` reference the field-type `::getType()`
values for drift-safety, and leaving the maps alone.

**RISK:** Low, but low value and easy to over-reach. The subset relationship is
meaningful, not accidental — don't collapse `ELEMENT_TYPES` into
`RELATION_TYPES`.

---

## 3. Looks consolidatable but should NOT be

- **Field-type handles → a PHP `enum`.** Tempting given 30+ field types, but
  `getType(): string` is the established polymorphic contract, types are
  pluggable via the registry (third parties register classes returning their own
  handle), and the values are migration-/storage-backed strings. An enum would
  break extensibility and fight Craft idiom. Keep `getType()`; just stop
  hardcoding the *result* (Finding A).

- **`SubmissionStatus` / `DispatchStatus` → unify into one enum or base.** They
  describe unrelated lifecycles (submission read-state vs. integration dispatch
  result) and already share a deliberate holder *style*. Merging would couple two
  domains for zero benefit. The code even documents the parallel intentionally.

- **Migration literals → reference the status/handle consts.** Several status
  holders' docblocks explicitly say migrations keep their own literals because
  migrations must stay self-contained and version-frozen. Correct — do not
  "consolidate" migration enum strings against runtime consts.

- **`captcha` `TOKEN_PARAM` / `VERIFY_URL` consts → a shared registry const.**
  Each provider (`RecaptchaProvider`, `HcaptchaProvider`, `TurnstileProvider`)
  owns its own vendor-specific endpoint/param. These are per-provider facts, not
  a shared value — the abstract base + per-class const is the right shape.

- **MCP `array{...}` shapes (`McpError`, `TokenArray`, `ResourceDescriptor`,
  `GqlFieldDefinitionMap`, etc.).** Already aliased and imported correctly. No
  action.

- **`CompositeFieldType` `array{label, kind, required}` ↔ repeater
  `array{handle, type, label, config}`.** Superficially similar (both describe
  sub-fields) but semantically different (kind/required vs. type/config). Merging
  would produce a lowest-common-denominator shape. Leave separate.

- **`array{label, value}` everywhere → one global DTO class.** These are
  Craft-conventional option arrays; a runtime value object adds ceremony with no
  payoff (Finding C is a *PHPDoc alias* at most, not a class).
