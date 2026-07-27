# 02 — Type Consolidation (DELTA pass, 2026-06-22)

Scope: PHP source changed since `c5b8fe7` (72 files). Builds on
`docs/cleanup/02-type-consolidation.md`. Research-only — no source modified.
WIP files (FormsController, Form, FormQuery, FormRenderService, templates, tests)
excluded from patches; read only for context.

---

## 1. Critical assessment

**The two high-value findings from the prior full audit are now FULLY RESOLVED
in the delta — no action needed:**

- **Finding A (field-type handle literals as discriminators):** done. Every call
  site that previously hardcoded `=== 'email'` / `'file'` / `'payment'` /
  `'select'` now references the owning class's `::getType()` —
  `EmailFieldType::getType()`, `FileFieldType::getType()`,
  `PaymentFieldType::getType()`, etc. across `SubmissionService`,
  `PaymentsService`, `DenylistService`, `EmailService`, `AkismetService`,
  `FieldSyncService`. The only remaining bare handle literals are the *grouping
  consts* in `FieldTypeRegistry` (`OPTION_TYPES`, `SCALE_TYPES`,
  `RELATION_TYPES`, line 45/54/62) and `RepeaterFieldType::ALLOWED_INNER_TYPES`
  (line 35) — the prior report already rated rewiring those to `::getType()` as
  *secondary/low value*, and they're storage-frozen and stable. Leave them.

- **Finding B (audit action / target-type literals):** done. `AuditService`
  (lines 18–35) now declares `ACTION_*` and `TARGET_*` `public const`s, and all
  ~11 call sites (`SubmissionService`, `FormCloneService`,
  `IntegrationsService`, `NotificationsService`, `FormPortabilityService`,
  `FormsController`) pass the consts. Exactly the holder pattern the prior report
  prescribed.

Type-alias discipline remains strong: `SubmissionData`, `ResolvedFieldRow`,
`GqlFieldDefinitionMap`, the MCP shapes (`McpError`, `TokenArray`,
`ResourceDescriptor`, …), and the new `PaymentResult` (PaymentsService:30) are
all declared with `@phpstan-type` and imported via `@phpstan-import-type`.

**What's left is incremental and annotation-only.** Several delta files now
repeat the *same* `array{…}` PHPDoc shape 3–6× within one file with no local
`@phpstan-type` alias. These are the only clearly-worth-it, gate-safe items.

**Scope caveat that kills the prior cross-file findings:** prior Findings C
(`{label,value}` option shape), D (hidden-field `{email,id,username}`), E
(repeater inner-field shape shared with `FieldOps`), F (GoogleSheets
`{handle,column}`) were *cross-file* consolidations. Their canonical-home /
consumer files (`HiddenFieldType`, `GoogleSheetsIntegration`, `ElementMapping`,
`CraftElementIntegration`, `FormField`, `SubmissionWidgetTrait`,
`mcp/tools/support/FieldOps`) are **NOT in the delta**. Sharing an alias across
delta + non-delta files would require editing out-of-scope source, so those stay
deferred. The patches below are strictly *within-file* repetition in delta files
— no out-of-scope edits, no drift risk.

---

## 2. High-confidence patch list (annotation-only, gate-safe)

All patches add a `@phpstan-type` alias to the class docblock and replace the
inline shape at each repeat. Zero runtime change; PHPStan resolves the alias to
the identical type, so `composer check` stays green.

### Patch 1 — `Formula.php` token shape — **High**
`src/helpers/Formula.php` — `array{type: string, value: string}` repeated **6×**
(lines 74, 78, 118, 249, 386, 393).
- **Change:** add to the class docblock (after line 6 block):
  `@phpstan-type Token array{type: string, value: string}` and replace each
  inline `array{type: string, value: string}` with `Token`
  (e.g. `list<Token>`, `Token|null`).
- **Why:** single tokenizer shape repeated 6× in one file; a local alias is the
  textbook `@phpstan-type` use and the highest-repetition case in the delta.

### Patch 2 — `EmailService.php` attachment shape — **High**
`src/services/EmailService.php` — `list<array{content: string, fileName: string, contentType: string}>`
repeated **3×** (lines 60, 106, 181).
- **Change:** add `@phpstan-type EmailAttachment array{content: string, fileName: string, contentType: string}`
  to the class docblock; replace the three sites with `list<EmailAttachment>`.
- **Why:** identical attachment shape across builder + sender methods; the file
  already declares/imports aliases (`@phpstan-import-type SubmissionData`), so
  this matches the established idiom in the same file.

### Patch 3 — `DialCodes.php` dial/label shape — **High**
`src/helpers/DialCodes.php` — `array<string, array{dial: string, label: string}>`
repeated **3×** (lines 30, 69, 110).
- **Change:** add `@phpstan-type DialCode array{dial: string, label: string}` to
  the class docblock; replace the three sites with
  `array<string, DialCode>`.
- **Why:** one entry shape, three identical declarations in one file; pure
  readability + drift safety.

### Patch 4 — `RepeaterFieldType.php` inner-field shape — **High**
`src/fields/RepeaterFieldType.php` —
`list<array{handle: string, type: string, label: string, config: array<string, mixed>}>`
repeated **3×** (lines 86, 134, 291; the 134/291 docblocks already cross-ref via
`{@see self::innerFields()}`).
- **Change:** add `@phpstan-type RepeaterInnerField array{handle: string, type: string, label: string, config: array<string, mixed>}`
  to the class docblock; replace the three sites with
  `list<RepeaterInnerField>`.
- **Why:** the prior report flagged this exact shape (Finding E) but wanted it
  *shared with* `FieldOps` (non-delta). The within-file 3× repetition is
  independently worth a local alias and is fully delta-safe. Keep it scoped to
  this class — do **not** attempt to share with `FieldOps` (out of scope, and
  `FieldOps`'s twin omits `type`).

### Patch 5 — `SubmissionCsv.php` column descriptor shape — **High**
`src/helpers/SubmissionCsv.php` — `list<array{key: string, sub: ?string, label: string}>`
repeated **2×** (lines 421, 468 — a return then its consuming `@param`).
- **Change:** add `@phpstan-type CsvColumn array{key: string, sub: ?string, label: string}`
  to the class docblock; replace both sites with `list<CsvColumn>`.
- **Why:** producer/consumer pair of the same column shape; aliasing guarantees
  they can't drift. Only 2 sites, so lowest priority of the five — but identical
  and trivially safe.

---

## 3. Looks consolidatable but should NOT (delta-specific)

- **`FieldTypeRegistry::OPTION_TYPES/SCALE_TYPES/RELATION_TYPES` &
  `RepeaterFieldType::ALLOWED_INNER_TYPES` → build from `::getType()`.** Prior
  report rated this secondary/low; values are storage-frozen and stable, and the
  rewire touches the concurrent `FieldType.php`/registry merge seam. Skip.
- **Prior Findings C / D / F (option `{label,value}`, hidden-field
  `{email,id,username}`, GoogleSheets `{handle,column}`).** Their canonical-home
  or consumer files are out of delta scope — sharing an alias would edit
  out-of-scope source. Defer (unchanged from prior report; still valid,
  just not delta-actionable). Note `HiddenValueResolver` (in delta) still carries
  the drifted looser variant `array{email?: ?string, id?: int|string|null, username?: ?string}`,
  but its partner `HiddenFieldType` is out of delta, so don't touch in isolation.
- **`PaymentResult`, `SubmissionData`, `ResolvedFieldRow`, MCP shapes.** Already
  aliased + imported correctly. No action.
- **`IntegrationsService:557` dispatch-stats shape, `FieldTypeRegistry:157`
  `{type,label}` map, one-off `array{url,label}` / `array{raw,e164,country}` /
  `array{name,email}` returns.** Single occurrences (not repeated) — aliasing a
  shape used once adds indirection for no payoff. Skip.

---

## Verdict

**5 high-confidence patches** — all annotation-only file-local `@phpstan-type`
aliases for within-file repeated `array{…}` shapes (Formula, EmailService,
DialCodes, RepeaterFieldType, SubmissionCsv). The prior audit's two structural
findings (A field-type handles, B audit consts) are already resolved in the
delta; the prior cross-file shape findings are out of delta scope and stay
deferred.
