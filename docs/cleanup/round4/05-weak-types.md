# Round 4 Cleanup 05 — Weak Types (net-new feature surface)

Research-only audit (no source modified) of PHP's `any`/`unknown` equivalents —
`mixed`, bare `array`/`object`/`iterable`/`callable`, missing param/return types,
untyped properties, untyped closures, and over-broad shapes — focused on the code
shipped **since the round-3 baseline** (`d2748b9`): logic jumps (#245), payment
coupons (#246), embed/share (#247), submission approval workflow (#248), UTM capture
(#249), address autocomplete (#250), conversational mode + built-in theme (#239/#243),
quiz scoring (#241), per-form reporting (#240), passive partials (#242), and the
review-fix commit (`c274dea`).

Scope of the delta: **105 changed files** under `src/` (`git diff --name-only
d2748b9 HEAD -- src/`). Gate baseline: PHPStan **level 7** (`phpstan.neon:5`,
`--memory-limit=1G`), green in the prior rounds; ECS clean. Builds on
`docs/cleanup/05-weak-types.md`, `delta/05-weak-types.md`, `round3/05-weak-types.md` —
items those rounds soundly rejected are not re-litigated.

---

## 1. Critical assessment

**The new feature surface holds the bar set by the prior rounds — it is, if anything,
better-typed out of the gate.** Whole-delta pattern counts:

- **Untyped properties: 0** across all 105 changed files (the only untyped `public $`
  in `src/` remains the framework-mandated `enableCsrfValidation` overrides — see K1).
  Every new model/service/event property carries a native type: `CouponModel`
  (`?int`, `string`, `float`, `?DateTime`, `?int`, `int`, `bool`, `?string`),
  `WorkflowTransitionEvent` (promoted-ctor `Submission`/`?string`/`string`/`?User`).
- **Missing param / return types: 0.** A multi-line + single-line signature scan over
  the delta returned no untyped params and no missing return types.
- **Bare `object` / `iterable` / `callable` params or returns: 0.** The one new
  `iterable` (`ReportsService::aggregateFieldReport`/`reportDataRows`) is fully
  shaped — `iterable<array<string, mixed>>` / `@return iterable<array<string, mixed>>`
  (`src/services/ReportsService.php:275,385`).
- **Bare `@param array` / `@return array` (no shape): 0.** Every array docblock added
  in the delta is generic- or shape-typed. Spot-checked the biggest new shapes and
  they are precise: `CouponsService::evaluate` →
  `array{coupon: CouponModel|null, discount: float, total: float, error: string|null}`
  (`CouponsService.php:67`); `PaymentsService` ships a
  `@phpstan-type PaymentResult array{status: string, orderId: int, amount: float,
  redirectUrl: string|null, error: string|null, couponCode: string|null, discount: float}`
  alias (`PaymentsService.php:30`) and uses it everywhere; `WorkflowService` returns
  `list<array{handle: string, label: string, color: string}>`,
  `list<array{from,to,label,groups: list<string>}>`,
  `list<array{from,to,label,toLabel,toColor}>`; `JumpResolver` threads
  `list<list<array{field: string, operator: string, value: mixed, to: int}>>` through
  every method; `ReportsService` shapes every return (`array{total,new,read,archived,spam}`,
  `list<array{date: string, count: int}>`, `array{spam,ham}`, etc.).
- **`@var mixed`: 0. Inline `@phpstan-ignore` / baseline suppressions: 0** new.
- **`mixed` in real signatures (delta): every occurrence is a previously-categorised
  *correct* `mixed`.** The delta added a handful, all in the four established-legit
  buckets: (a) field-type value contract slots (`CompositeFieldType::validate`/
  `renderInput`/`serializeValue`, mirroring the `FieldType` base + Craft's
  `craft\base\Field`), (b) element-query `Db::parseParam` params
  (`SubmissionQuery::workflowStatus(mixed $value = null)`), (c) GraphQL/HTTP input
  narrowing layers (`CouponsController::intOrNull(mixed $value): ?int`,
  `IntegrationsService::normalizeSettings`), and (d) stored-value flatten helpers
  (`SubmissionCsv`, `QuizScoringService::selectedValues`). None is strengthenable
  without false precision; all do their own `is_*` narrowing.

The coupon/workflow/payment path in particular is a model of strong typing: discount
math on `CouponModel` is all `float`/`int`/`?int`/`bool`; `CouponsService` casts every
DB column explicitly in `rowToModel` (`(int)`, `(string)`, `(float)`, `(bool)`);
`PaymentsService::authorizeForSubmit` flows a `SubmissionData`-typed map and returns a
shaped `PaymentResult`. There is **no source change warranted on this dimension.**

The only nameable item in the entire delta is one cosmetic shape-tightening (L1), and
even that is defensible as-is. The untyped *closure* params (e.g. `array_map(static
fn($v): array => …, …)`) are idiomatic — PHPStan infers each from the mapped/filtered
array's element type and L7 stays green; typing them adds nothing.

---

## 2. Findings

### High confidence

**None.** No untyped properties, no missing types, no bare arrays, no strengthenable
`mixed` in the new code. The strong-typing discipline is already applied.

### Medium confidence

**None.** (No item rises above cosmetic.)

### Low confidence / cosmetic (optional, leave unless touching the file anyway)

**L1 — `DraftService::listPassive()` return shape: `dateCreated: mixed,
dateUpdated: mixed` could be `string`.**
`src/services/DraftService.php:174` declares
`@return list<array{id: int, data: array<string, mixed>, fieldCount: int,
dateCreated: mixed, dateUpdated: mixed}>`. The two timestamps are read straight from a
`(new Query())->select([...,'dateCreated','dateUpdated'])->all()` row (`:179`) and
passed through verbatim (`:200-201`), so PHPStan sees them as `mixed` (a `Query` row is
`array<string, mixed>`). Both columns are non-null datetimes; the CP abandoned-listing
template only formats them. **Proposed:** cast at assignment —
`'dateCreated' => (string) $row['dateCreated']`, `'dateUpdated' => (string) $row['dateUpdated']`
— and narrow the shape to `dateCreated: string, dateUpdated: string`.
**Risk: very low.** The cast is required to keep PHPStan L7 green (returning `mixed`
into a declared `string` slot is reported); behaviour is unchanged (template usage is
already string/date formatting). **Blast radius:** one method + its template consumer.
Honestly marginal — the current `mixed` is an acceptable raw-DB-passthrough; flag only
because it is the single shape member in the delta that is looser than its real domain.

**L2 — Untyped closure param in `WorkflowService::userGroupHandles()` (cosmetic only).**
`src/services/WorkflowService.php:220`:
`array_map(static fn($g): string => (string) $g->handle, $user->getGroups())`. Craft's
`User::getGroups()` is typed `UserGroup[]`, so PHPStan already infers `$g` as
`craft\models\UserGroup` and L7 is green; `$g` could be annotated
`craft\models\UserGroup $g` for reader clarity. **Risk: none; value: negligible.**
Representative of ~10 similar idiomatic untyped closures in the delta (FormsController
mappers, SettingsController/SubmitController `is_string` predicates, SubmissionCsv).
**Recommend leaving all of them** — Craft/Yii idiom, PHPStan-inferred, no gate benefit.

---

## 3. Spots that should legitimately stay loose (do NOT "fix")

All carried over from prior rounds and re-confirmed present-and-correct in the delta:

- **Field-type value contract** — `CompositeFieldType::validate(mixed $value): array`
  (`:101`), `renderInput(string $name, mixed $value = null)` (`:154`),
  `serializeValue(mixed $value): array` (`:216`); plus every concrete type added/changed
  in the delta (`CalculationFieldType`, `CheckboxFieldType`, `OpinionScaleFieldType`,
  `PaymentFieldType`, `PhoneFieldType`, `RadioFieldType`, `RatingFieldType`,
  `SelectFieldType`, `FileFieldType`). These override the `FieldType` base /
  `craft\base\Field` contract over heterogeneous stored shapes. **Must stay `mixed`.**
- **`SubmissionQuery::workflowStatus(mixed $value = null): static`** (`:58`) and the
  sibling `userId`/`paymentStatus`/`orderId` setters/props — canonical Craft
  element-query `Db::parseParam` pattern (must accept int|string|list|`:empty:`|`not …`
  |range). **Leave** (already documented in each method).
- **Input-narrowing layers** — `CouponsController::intOrNull(mixed $value): ?int`
  (`:146`, over `getBodyParam`, whose Craft return type is `mixed`),
  `IntegrationsService::normalizeSettings(mixed $raw)` (`:453`),
  `FormPortabilityService::resolveRedirectEntry/parseDate(mixed …)` (`:817/:836`),
  `QuizScoringService::selectedValues(mixed $entry)` (`:142`). Each *is* the narrowing
  boundary with internal `is_*` guards — `mixed` in is the correct untrusted domain.
- **GraphQL resolver `mixed $source`** (`FormMutations::resolveSubmit/resolveUpdate`,
  `buildValueMap(mixed $inputValues)`) — graphql-php/Craft-mandated signature.
- **`SubmissionCsv` stored-entry helpers** (`cell`/`valueForExport`/`collectAssetIds`/
  `cellValue`/`scalar`/`entryLabel`/`isComposite`, all `mixed $entry`) — polymorphic
  legacy/partial stored rows; `mixed` is the honest input type.
- **`WorkflowTransitionEvent::__construct(…, array $config = [])`** (`:25`) — the Yii
  `Event` ctor `$config` convention; already as strong as the framework allows.
- **`JumpResolver` rule `value: mixed`** (`:64` et al.) — a jump's compared value is an
  arbitrary stored field answer routed through `ConditionalEvaluator::compare()`; the
  `mixed` member is correct, not a gap.

---

## Summary table

| Category | Delta count | Action |
|---|---|---|
| Untyped properties | 0 (excl. framework `enableCsrfValidation`) | None ✅ |
| Missing param / return types | 0 | None ✅ |
| Bare `object` / `iterable` / `callable` | 0 | None ✅ |
| Bare `@param array` / `@return array` (no shape) | 0 | None ✅ |
| `@var mixed` / new `@phpstan-ignore` | 0 | None ✅ |
| `mixed` in new signatures | all in the 4 established-legit buckets | Leave ✅ |
| **Genuinely strengthenable** | **1 cosmetic** (L1) + 1 trivial (L2) | Optional, low value |

**Verdict: the net-new code is already strongly typed — clean on this dimension.** No
high- or medium-confidence change is warranted. The lone nameable item (L1: cast two
passthrough DB timestamps to `string` in `DraftService::listPassive`) is cosmetic and
defensible as-is. Recommend **no source change** unless one of those files is being
edited for another reason.
