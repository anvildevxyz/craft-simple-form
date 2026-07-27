# Concern 2 — Shared Type Consolidation (net-new features pass, 2026-06-27)

Scope: the features shipped **since** `docs/cleanup/round3/02-type-consolidation.md`
(2026-06-24) — logic jumps (#245), payment coupons (#246), address autocomplete
(#250), submission approval workflow (#248), conversational theme (#243),
plus the review-fix commit. Targets from the brief: `PaymentResult` /
`SubmissionData` / `SubmissionContext`, the workflow `{handle,label,color}` /
`{from,to,label,groups}` shapes, the coupon `evaluate()` `{coupon,discount,total,error}`
result, and repeated field-config shapes. Research-only — **no source modified**.

This builds on three prior passes (`02-type-consolidation.md`, `delta/`, `round3/`)
that already resolved the structural items (field-type handles → `::getType()`,
audit consts, `SubmissionResult`/`ResumePrefill`/`HiddenUserAttrs`/`SelectOption`
aliases, `PaymentResult`). I re-verified those are still in place and concentrate
below only on shapes the new features introduced.

---

## (a) Critical assessment

**Type discipline on the net-new code is, with two exceptions, already excellent.**
The big shared shapes the brief named are handled the right way:

- **`PaymentResult`** is a single `@phpstan-type` on `PaymentsService.php:30`,
  produced by the `result()` factory (`:251/:253`) and referenced via the alias
  everywhere (`:124`). The coupon work (#246) extended it in place with
  `couponCode`/`discount` rather than spawning a parallel shape. Correct.
- **`SubmissionData`** (`Submission.php:17`) and **`SubmissionContext`**
  (`SubmissionService.php:35`) are declared once and `@phpstan-import-type`d by
  consumers (`NotificationsService`, `SubmissionBodyRenderer`, `AkismetService`,
  `integrations/support/SubmissionValues`). No drift.
- **The coupon `evaluate()` result** `array{coupon, discount, total, error}` is
  declared exactly **once** (`CouponsService.php:67`). Its two consumers
  (`PaymentsService.php:150-155`, `SubmitController.php:169-184`) only *read* the
  keys; neither re-declares the shape. A single-declaration shape needs no alias —
  aliasing it would be the ceremony the prior passes explicitly reject. Leave it.
- **Address autocomplete (#250)** is built on the existing `CompositeSubField`
  value object (`AddressFieldType::subFieldDefs()` → `CompositeSubField`), so it
  introduced **no** duplicated array shapes. Clean.
- **The Settings workflow shapes** (`Settings.php:273/284`,
  `workflowStatuses`/`workflowTransitions`) are deliberately typed loose as
  `list<array<string, mixed>>`, with a docblock that states *why* ("raw stored
  config may be partial; WorkflowService normalizes it"). This is the correct
  "raw settings config stays loose" call — **do not** tighten it to the normalized
  shape.

**The two genuine findings are both within-file `array{…}` repetition** in the
new feature code — the exact pattern the prior passes accepted for
`SubmissionResult` (3× in one file) and `ResumePrefill` (producer/consumer pair):
the approval-workflow status/transition shapes in `WorkflowService`, and the
logic-jump step-rule shape in `JumpResolver`. Both are annotation-only, gate-safe
(PHPStan resolves an alias to the identical type, so `composer check` stays
green), and confined to one file each — no cross-file or out-of-scope edits.

The codebase is **largely clean** for this concern; these are two incremental
polish aliases, not structural debt.

---

## (b) Findings

### Finding 1 — Workflow status/transition shapes repeated in `WorkflowService` — **HIGH**

`src/services/WorkflowService.php` (#248) re-spells the same two `array{…}` shapes
across its method signatures:

- **Status shape** `array{handle: string, label: string, color: string}` — declared
  2× : `:35` (`@return list<…>` on `getStatuses()`) and `:56`
  (`@return …|null` on `getStatus()`).
- **Transition shape** `array{from: string, to: string, label: string, groups: list<string>}`
  — declared 3× : `:79` (`@return list<…>` on `getTransitions()`), `:137`
  (`@param … $transitions` on `filterAllowed()`), `:139`
  (`@return list<…>` on `filterAllowed()`).

These are the canonical contracts the service hands to controllers
(`SubmissionsController.php:125`, `SettingsController.php:191/215/219/250/272/318`)
and templates; a new key (say a `description`) would have to be edited into
2–3 separate docblocks and could silently drift between the producer and the pure
`filterAllowed()` gate.

**Recommended change (annotation-only):** add two `@phpstan-type` aliases to the
`WorkflowService` class docblock and reference them:

```php
 * @phpstan-type WorkflowStatus array{handle: string, label: string, color: string}
 * @phpstan-type WorkflowTransition array{from: string, to: string, label: string, groups: list<string>}
```

- `:35` → `@return list<WorkflowStatus>`
- `:56` → `@return WorkflowStatus|null`
- `:79` → `@return list<WorkflowTransition>`
- `:137` → `@param list<WorkflowTransition> $transitions`
- `:139` → `@return list<WorkflowTransition>`

**Canonical home:** `WorkflowService` itself — it owns and produces both shapes,
and the only PHP type declarations of them live here (controllers consume the
return values without re-declaring shapes; their `array $s`/`array $t` closure
params are inferred). No `@phpstan-import-type` needed anywhere.

**Leave inline (do NOT alias):** the *derived* allowed-transition shape
`array{from, to, label, toLabel, toColor}` at `:107` (`allowedTransitions()`).
It is declared once and is a presentation-enriched one-off — aliasing a
single-occurrence shape is ceremony (same rationale round3 used to reject the
`CompositeFieldType` subfield shape).

**Risk / blast-radius:** Low. PHPStan resolves each alias to the identical
type, so L7 + ECS + PHPUnit stay green; zero runtime change. Single file, no
concurrent-branch hazard (the field/registry merge seams from earlier passes are
resolved). 5 docblock lines touched.

---

### Finding 2 — Logic-jump step-rule shape repeated in `JumpResolver` — **HIGH**

`src/helpers/JumpResolver.php` (#245) declares the same step-rule shape
`array{field: string, operator: string, value: mixed, to: int}` (wrapped in
`list<list<…>>`) 3× : `:64` (`@return` on `stepRules()`, the producer), `:104`
(`@param $stepRules` on `next()`), `:122` (`@param $stepRules` on `reachable()`).

This is a producer + two-consumer triple in one file — the identical situation
the prior pass aliased as `ResumePrefill`. The shape is the resolved render-ready
jump table that `next()`/`reachable()` walk; the class docblock (`:18-20`) even
documents it in prose as "a list of `{field, operator, value, to}`". It is not
referenced outside this file.

**Recommended change (annotation-only):** add one alias to the `JumpResolver`
class docblock:

```php
 * @phpstan-type StepRule array{field: string, operator: string, value: mixed, to: int}
```

- `:64` → `@return list<list<StepRule>>`
- `:104` → `@param list<list<StepRule>> $stepRules`
- `:122` → `@param list<list<StepRule>> $stepRules`

**Canonical home:** `JumpResolver` (sole producer + only consumers).

**Risk / blast-radius:** Low. Annotation-only, identical resolved type, single
file. 3 docblock lines touched.

---

## (c) Looks consolidatable but should NOT be

- **Coupon `evaluate()` result `array{coupon, discount, total, error}`**
  (`CouponsService.php:67`). Single declaration; both consumers only read keys.
  No alias — aliasing a one-site shape adds ceremony with no drift to prevent.
- **Settings `workflowStatuses` / `workflowTransitions`** (`Settings.php:273/284`,
  `list<array<string, mixed>>`). Deliberately loose raw-config arrays, documented
  as such and normalized by `WorkflowService`. Keep loose — tightening them to the
  normalized shape would lie about what the stored config can contain.
- **`PaymentResult`** (`PaymentsService.php:30`). Already a single aliased shape,
  correctly extended in place by the coupon work. No action.
- **Address `CompositeSubField`** value object. Already the shared model for the
  address sub-fields; #250 added no parallel array shape. No action.
- **`WorkflowTransitionEvent`** (`events/WorkflowTransitionEvent.php`). A clean
  constructor-promoted value object (`submission, from, to, user`); the workflow
  shapes are *not* re-spelled here. No action.
- **`CompositeFieldType.php:43` `array{label, kind, required}`**,
  **`GoogleSheetsIntegration` `{handle, column}`**, **`FieldOps` repeater shape** —
  out of this pass's net-new scope and already adjudicated by round3 (§(b)/§(d)).
  No change.

---

## Verdict

**2 HIGH findings, 0 MEDIUM** — both annotation-only `@phpstan-type` aliases for
within-file `array{…}` repetition introduced by the new approval-workflow (#248)
and logic-jumps (#245) code:

1. `WorkflowStatus` + `WorkflowTransition` on `WorkflowService` (5 sites).
2. `StepRule` on `JumpResolver` (3 sites).

Both are gate-safe (PHPStan resolves the alias to the identical type) and
single-file. Every other shape the brief named is already handled correctly:
`PaymentResult` aliased, `SubmissionData`/`SubmissionContext` aliased+imported,
the coupon result is a single declaration, address autocomplete uses a value
object, and the Settings workflow config is intentionally (and correctly) loose.
The net-new code is **largely clean** for this concern.
