# Round 3 Cleanup 05 — Weak Types

Research-only audit (no source modified) of PHP's `any`/`unknown` equivalents:
`mixed`, bare `array`, missing type declarations, `@var mixed`, broad unions, bare
`callable`/`iterable`, `object` where a concrete class fits, and nullable-never-null.

Scope: full re-audit of `src/` (210 PHP files), with deep focus on the ~39 commits of
new code since `bdff98c` (payments, forms-as-code apply/export/status, dev extension
events, JS hook API, GraphQL SDL, `make/*` generators, tabbed editor, `Install`
collapse). Baseline: PHPStan **level 7**, green; ECS clean; 463 PHPUnit tests pass.
Builds on `docs/cleanup/05-weak-types.md` and `docs/cleanup/delta/05-weak-types.md` —
soundly-rejected items are not re-flagged.

---

## 1. Critical assessment

**Typing health remains excellent, and the new code holds the bar.** The new feature
surface is, if anything, *better* typed than the prior baseline — the event classes
ship `class-string<...>`, `list<ResolvedFieldRow>`, and `@phpstan-type` aliases out of
the gate. Whole-codebase pattern counts:

- **Bare `@param array` / `@return array` (no shape): 0.** Every array docblock carries
  a generic or shape. The big new `FormPortabilityService` (~1050 lines) is fully
  shaped: `array<string, mixed>`, `list<array<string, mixed>>`,
  `array<int, callable(array<string, mixed>): array<string, mixed>>`, etc.
- **`@var mixed`: 0. `@param object` / `@return object`: 0. Bare `iterable`: 0.**
- **Missing param/return types: 0.** Multi-line-signature scan surfaced only 4 matches,
  all constructors (`FieldType`, `Formula`, `FieldModel`, `FormModel`) — which correctly
  have no return type.
- **Inline `@phpstan-ignore` / baseline suppressions masking types: 0.** No baseline file.
- **Bare `callable`: 4**, all previously vetted (graphql-php `FieldDefinition` shape
  alias, two `Formula.php` doc-comment prose mentions, `SafeRenderService::withSandbox`
  render closure). None delta-new behavior.
- **`mixed` in real signatures: 147.** Every new occurrence is a field-type value
  handler, a framework-override slot, a `Db::parseParam` element-query param, or a raw
  decoded-JSON/HTTP narrowing layer (with `is_array`/`is_string` guards inside) — all
  categories the prior reports already established as **correct** `mixed`.

**One genuine, gate-safe finding exists** (H1 below): a single untyped public property
on `SubmissionEvent` that is provably always `?array`. Everything else is either
framework-mandated or a correct narrowing boundary.

---

## 2. Findings

| # | File:line | Current | Proposed | Evidence | Conf. | Gate / contract risk |
|---|---|---|---|---|---|---|
| H1 | `src/events/SubmissionEvent.php:14` | `public $data = null;` (untyped; `@var array<string, mixed>\|null`) | `public ?array $data = null;` | All 5 constructions in `SubmissionService` (305/316/382/390/804) pass `$data` typed `array<string, mixed>` (`:263-264`, `:369-370`, `:801`); ctor param is `?array $data` (`:21`); body assigns `$this->data = $data` only. No code path sets it to a non-array/non-null. Parent `yii\base\Event` declares no `$data` property → no override conflict. | **HIGH** | **None.** `?array` is strictly tighter than the absent native type; PHPStan L7 stays green; no parent contract. Docblock can be kept for the `<string, mixed>` value shape. |
| K1 | `src/controllers/{Submit,Fields,SubmissionEdit,Mcp}Controller.php` (`enableCsrfValidation`) | `public $enableCsrfValidation = ...;` (untyped) | **KEEP untyped** | Overrides `yii\web\Controller::$enableCsrfValidation` which is declared `public $enableCsrfValidation = true;` (untyped) at `vendor/.../yii2/web/Controller.php:39`. PHP requires a typed override to match; adding a type would violate the parent property contract. | n/a | **MUST-KEEP.** Framework override. |
| K2 | `src/events/*` value props (`settings`, `recipients`, `submissionData`, `values`, `valuesByHandle`, `context`, `types`, `fields`, `providers`, `stencils`) | `public array $...` + `@var array<...>` | KEEP | These are public event surface configured by listeners; native `array` + shaped `@var` is already the strongest PHP allows for a mutable public array prop. | n/a | Already optimal. |
| K3 | `src/services/FormPortabilityService.php:817` `resolveRedirectEntry(mixed $ref, …)`, `:836` `parseDate(mixed $value): ?\DateTime` | `mixed` param | KEEP | Both receive leaf values pulled from a decoded export-document node (`array<…, mixed>`); each method **is** the narrowing layer (`is_array($ref)` / `is_string($value)` guards inside). `mixed` in is the correct domain for untrusted decoded JSON. | n/a | Leave (same pattern accepted in prior reports). |
| K4 | `src/console/controllers/MakeController.php:203/215` `mixed $value` | inside generated code string | KEEP | These are characters of a **scaffold template string** the generator emits; the emitted `renderInput(mixed $value)` / `validate(mixed $value)` correctly mirror the `FieldType` base contract. Not a real signature in this file. | n/a | Leave. |
| K5 | `src/controllers/SettingsController.php:198` `normalizeTab(string\|int\|float\|bool\|null $raw): string` | scalar-union param | KEEP | Already **tighter than `mixed`**. Callers pass a `?string` route arg (`:64`) and `getBodyParam('tab')` (`:75`). Gate is green at L7 (which does not enforce `mixed` membership), so the union is accepted and is more honest than `mixed`. | n/a | Leave — this is good typing, not weak. |

No overly-permissive multi-member unions, no `object`-where-concrete-class-fits, and no
nullable-never-null properties were found in the new code (`ImportResult::$form` is
genuinely nullable — set only on success).

---

## 3. HIGH-CONFIDENCE RECOMMENDATIONS

Exactly **one** verified, gate-safe strengthening:

- **H1 — `src/events/SubmissionEvent.php:14`**: change `public $data = null;` →
  `public ?array $data = null;` (retain the `@var array<string, mixed>|null` docblock
  for the inner value shape). Verified type-correct against all 5 call sites and the
  constructor; PHPStan L7 stays green; no parent-property contract (Yii `Event` has no
  `$data`). This also brings the property in line with the project's typed-property
  convention already used by every sibling event class
  (`?NotificationModel $notification`, `Form $form`, etc.).

Everything else is framework-mandated (`enableCsrfValidation` override; field-value
`mixed`), a correct decoded-JSON/HTTP narrowing boundary, generated-template text, or
already optimally typed. No other source change is warranted on this dimension.

---

## Summary

| Metric | Count |
|---|---|
| Bare `@param array`/`@return array` (unshaped) | 0 |
| `@var mixed` / `@param object` / `@return object` / bare `iterable` | 0 |
| Missing param/return types | 0 |
| Inline `@phpstan-ignore` / baseline suppressions | 0 |
| `mixed` in real signatures (all categorically correct) | 147 |
| **Genuine, gate-safe strengthenings (HIGH)** | **1** (H1) |
| MUST-KEEP / leave-as-is items documented | 5 (K1–K5) |

**Verdict:** The round-3 new code upholds the project's excellent typing. One small,
high-confidence improvement (typing `SubmissionEvent::$data` as `?array`) is available
and safe; no other change is justified short of a deliberate PHPStan 2.x / level-9
upgrade, where the field-value `mixed` surface would need local narrowing rather than
type removal.
