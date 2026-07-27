# Cleanup Audit — Unused / Dead Code (net-new surface)

**Plugin:** Simple Form (Craft CMS 5)
**Concern:** 3 — Find & remove unused code (knip-equivalent), proven unreferenced.
**Scope of this pass:** the net-new code shipped since the round-3 audit (2026-06-24): payment
coupons (#246), opt-in address autocomplete (#250), submission approval workflow (#248),
conversational render mode + centered-card theme (#239/#243), payments (#116), logic jumps (#245),
and the review-fix commit (#246/#250/#248/#243). Commits `007ba83..HEAD`. Full `src/` was still
scanned, but findings concentrate on the new surface as instructed.
**Date:** 2026-06-27
**Mode:** Research-only. No source modified.

---

## 1. Critical assessment

The net-new code is **very nearly as clean as the prior three audits found the rest of the codebase.**
The earlier round-3 audit's three orphaned translation keys are gone, locale parity is exact (338 keys
× 8 locales), and a full re-scan of every translation key, every form/CP CSS class, every JS function,
and the new services/models/events/helpers found that essentially everything is referenced or
dynamically dispatched. New service surfaces (`CouponsService`, `WorkflowService`), the new event
(`WorkflowTransitionEvent`), new settings keys, new field-type code, and all new CSS classes are all
live. PHPStan L7 (which the gate runs) already guarantees the unused-private-method and
unused-`use`-import categories are clean by construction.

I found **three genuinely unreferenced items**, all small and all in the new feature code: one
write-only JS state attribute (`data-sf-coupon-applied`), one tested-but-never-called PHP helper
(`JumpResolver::referencedTargets()` plus a stale comment naming a `validateJumps()` method that does
not exist), and one tested-but-never-called model accessor (`CouponModel::isRedeemable()`). None is a
correctness bug; the `referencedTargets()` one is the most interesting because it signals an
*incomplete* save-time-validation feature rather than mere leftover code. The code is **largely
clean** for this concern.

### Method / proof discipline
- Translation keys: `require`d `en/simple-form.php` (338 keys) and `grep -rlF` each key over all of
  `src` excluding `src/translations/**` → **0 unused**. Locale parity verified (all 8 = 338).
- CSS: extracted every `.sf-*` / `.simple-form*` selector from `form/dist/css/simple-form.css` and the
  net-new selectors from `cp/dist/css/cp.css`; token-boundary-grepped each against `*.twig *.js *.php`
  → all referenced (including JS-built class strings).
- JS: enumerated every `function` declaration in `simple-form.js` (16 top-level) and `embed.js` (8) and
  confirmed ≥2 occurrences (decl + call) for each → no orphaned functions.
- `data-*`: diffed JS-read `data-sf-*` attributes against template/PHP emitters; the only two not in
  markup are JS-internal state flags — one is read (`data-sf-embed-ready`), one is **not**
  (`data-sf-coupon-applied`, see §3).
- PHP public symbols: grepped each new public method of `CouponsService`, `CouponModel`,
  `WorkflowService`, `JumpResolver`, `FormScreens`, `PaymentsService::authorizeForSubmit` across
  `src` + `tests`, ruling out test-only references.

### Dynamic references explicitly accounted for (NOT flagged)
- `CouponsController::actionEdit/Save/Delete/Toggle/SettingsIndex`, `SubmitController::actionCouponValidate`,
  `SubmissionsController` transition action, `SettingsController` workflow actions — Craft `actionX`
  auto-routing.
- `WorkflowService` registered via `Plugin::setComponents()` `getWorkflow()`; `WorkflowTransitionEvent`
  dispatched via `Plugin::EVENT_SUBMISSION_TRANSITIONED` (WorkflowService.php:192-195).
- `JumpResolver::next()` looks test-only but is called internally by `reachable()` (line 132) — **live**.
- `AddressFieldType::beforeSubFields()/subFieldOptions()` are overrides called by `CompositeFieldType`
  (lines 90, 165) — **live**.
- All new translation keys reachable via `Craft::t` / `|t`; `data-sf-coupon-network-error`,
  `data-provider/-endpoint/-api-key` emitted by `AddressFieldType` and read by JS — **live**.

---

## 2. Findings

### Finding A — `data-sf-coupon-applied` is a write-only attribute (never read)
**Confidence: HIGH (unreferenced) — risk: low**
**File:** `src/web/assets/form/dist/js/simple-form.js:811, 814, 820, 831`

The coupon UI sets/removes `data-sf-coupon-applied` on the coupon box in four places inside
`initCoupon()`, but **nothing ever reads it** — no `getAttribute`, no `[data-sf-coupon-applied]` CSS
selector, no querySelector, no template/PHP reference.

Proof:
```
$ grep -rn "coupon-applied" src docs tests | grep -v "simple-form.js"
(no output)
$ grep -rn "coupon-applied" src/web/assets/form/dist/css/*.css
(no output)
```
All four hits in `simple-form.js` are `setAttribute(...,"1")` / `removeAttribute(...)`; none is a read.

**Proposed change:** remove the four `box.setAttribute/removeAttribute("data-sf-coupon-applied", …)`
calls (lines 811, 814, 820, 831). *Alternative:* if it is meant as a public theming hook for site
authors (e.g. style an "applied" state), keep it and document it — but today it is undocumented and
inert.
**Blast radius:** JS only; `composer check` doesn't lint JS, and `composer test:js` has no coupon test
that asserts on this attribute, so removal is safe. Pure no-op deletion.

### Finding B — `JumpResolver::referencedTargets()` is dead in production + names a non-existent validator
**Confidence: MEDIUM (resolution is a judgment call) — risk: low–medium**
**Files:** `src/helpers/JumpResolver.php:147-158` (method) and `:82-84` (stale comment)

`referencedTargets()` is implemented and unit-tested (`tests/unit/JumpResolverTest.php:83`) but is
**never called anywhere in `src/`.** Its docblock says it is "for save-time dangling/forward
validation (mirrors `ConditionalEvaluator::referencedFields()`)", and the comment at line 82-84 says
invalid jumps are "refused at save by `validateJumps()`" — but **no `validateJumps()` exists** in the
codebase. The mirror method `ConditionalEvaluator::referencedFields()` *is* wired into save validation
(`FieldSyncService.php:275`); the jumps equivalent never was.

Proof:
```
$ grep -rnE 'validateJumps|referencedTargets' src tests
src/helpers/JumpResolver.php:83:   // (and refused at save by validateJumps()).
src/helpers/JumpResolver.php:147:  public static function referencedTargets(array $config): array
tests/unit/JumpResolverTest.php:83: ...JumpResolver::referencedTargets($config));
```
So `referencedTargets()` has zero production callers; the "refused at save" comment describes behaviour
that does not happen (invalid/backward jumps are instead silently dropped at render/replay by
`buildStepRules()`, which is functionally safe but not the claimed save-time rejection).

**Proposed change — pick one:**
1. **Preferred (closes a real gap):** wire `referencedTargets()` into `FieldSyncService` save
   validation the same way `referencedFields()` is, so dangling/backward jump targets are rejected at
   save — then the comment becomes true. (This is a small feature completion, not pure cleanup.)
2. **Pure cleanup:** remove `referencedTargets()` (148-158), its unit test, and rewrite the stale
   `validateJumps()` comment at line 82-84 to describe what actually happens (drop-at-render).
**Blast radius:** option 2 touches one helper + one test + one comment, all safe under the gate
(`helpers\*` are internal per `api-stability.md`). Option 1 adds a save-time rule — needs an integration
test. Flagged MEDIUM because the right answer is a decision, not a mechanical removal.

### Finding C — `CouponModel::isRedeemable()` is test-only
**Confidence: LOW — risk: low**
**File:** `src/models/CouponModel.php:55-58`

`isRedeemable()` is referenced only by `tests/unit/CouponModelTest.php`; production never calls it.
`CouponsService::evaluate()` deliberately checks `enabled` / `isExpired()` / `isUsedUp()` separately
(to surface a specific visitor message, per its line-78 comment) rather than the combined predicate.

Proof:
```
$ grep -rnF isRedeemable src   # → only the definition, no caller
src/models/CouponModel.php:55: public function isRedeemable(): bool
```
**Proposed change:** leave it. It is a coherent public accessor that parallels the heavily-used
`isExpired()`/`isUsedUp()`, is cheap, is test-covered, and is a plausible convenience for host-project
Twig (`coupon.isRedeemable`). Removing it would also delete a passing test for marginal gain. Recorded
for completeness only; **not recommended for removal.**

---

## 3. Verified-clean (no findings)

| Area | Why clean |
|---|---|
| Translation keys (338 × 8 locales) | Every key referenced in `src/` (grep-proven); exact locale parity. Round-3's 3 orphans already removed. |
| Form CSS (`simple-form.css`) | Every `.sf-*` / `.simple-form*` selector — incl. all coupon, conversational-step, progress, address-autocomplete, resume classes — token-matched in templates/JS. |
| CP CSS net-new (`cp.css`) | `sf-attribution`, `sf-dist-bar`, `sf-inline-form`, `sf-tight-top`, `sf-workflow-actions`, `sf-workflow-add` all referenced. |
| Form JS (`simple-form.js`, `embed.js`) | All 16 + 8 function declarations called; `data-sf-embed-ready` is a read state flag. |
| `CouponsService` (8 public methods) | All called by `PaymentsService`/`CouponsController`/tests. |
| `WorkflowService` (9 public methods) | All called by controllers/templates/SubmissionService/tests; `filterAllowed()` is the pure unit-tested gate. |
| `WorkflowTransitionEvent` | Dispatched; properties are handler API. |
| New Settings keys | `enableWorkflow`, `workflowStatuses/Transitions`, `partialRetentionDays`, `addressAutocomplete*` all consumed in templates + services + field type. |
| `JumpResolver` (other 4 methods), `FormScreens::conversational` | All wired into `SubmissionService` / `FormRenderService`; `next()` used internally by `reachable()`. |
| Unused `use` imports / unused private methods | Category guaranteed clean by PHPStan L7 (gate-enforced). |

---

## 4. Recommendation summary

| # | Item | File:line | Confidence | Action |
|---|---|---|---|---|
| A | `data-sf-coupon-applied` write-only attribute | `simple-form.js:811,814,820,831` | HIGH | Remove the 4 set/remove calls (or document as a hook) |
| B | `JumpResolver::referencedTargets()` dead + stale `validateJumps()` comment | `JumpResolver.php:147-158, 82-84` | MEDIUM | Wire into save validation (preferred) **or** remove method+test and fix the comment |
| C | `CouponModel::isRedeemable()` test-only | `CouponModel.php:55-58` | LOW | Keep (recorded only) |

**Bottom line:** the net-new feature code is largely clean. One genuine dead JS write-only attribute
(HIGH), one dead-but-meaningful PHP helper that flags an unfinished save-validation feature (MEDIUM),
and one harmless test-only accessor (LOW). No dead translation keys, no dead CSS, no orphaned JS
functions, no dead services/events/migrations.
