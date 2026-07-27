# Optimisation audit — `src/models/`

Veteran-PHP-optimiser pass over all 6 files in `src/models/` of the
`anvildev\simpleform` plugin (PHP 8.3, Craft CMS 5). Research only — no source
was modified.

**Scope (files reviewed):**

- `src/models/Settings.php` (largest)
- `src/models/FieldModel.php`
- `src/models/FormModel.php`
- `src/models/ImportResult.php`
- `src/models/IntegrationModel.php`
- `src/models/NotificationModel.php`

**Constraints honoured:** behaviour-preserving only (public I/O, side-effects,
error behaviour, runtime ordering, validation rule meaning/order all
unchanged); must stay green under ECS (Craft style) and PHPStan level 7; no new
deps; no cross-file moves; license/intent comments kept. Codebase has already
passed 5 prior cleanup audits (DRY / dead-code / weak-types), so those classes
of issue are intentionally not re-flagged.

---

## Findings table

| # | File:line | Pattern | Confidence | Verdict |
|---|-----------|---------|------------|---------|
| — | — | — | — | No qualifying findings |

**Result: 0 findings.**

There are no safe, idiomatic, gate-compatible, behaviour-identical optimisation
opportunities in these 6 files.

---

## HIGH-CONFIDENCE list

*(none)*

---

## Why nothing qualified

Each file was checked against the four target patterns (manual transform/filter
loops → array_* ; redundant intermediates/casts/fusible loops ; hoistable
repeated sub-expressions ; `??`/`match`/`?->` simplifications). The code is
already at or below the optimisation surface those patterns target.

### `Settings.php`
- `defineRules()` (lines 258–292) is the only large structure. Per the brief,
  validation rule arrays are left as-is for readability; no rule-array rewrite
  here is *provably* identical-and-clearly-better. The two closures
  `$recaptcha` / `$provider` (lines 260–263) already hoist the shared
  `enableCaptcha && selectedCaptchaProvider …` sub-expression — the desirable
  hoist is done.
- `validateBlockedIps()` (lines 344–357): the `foreach` over
  `preg_split('/\R/', …)` performs a side-effect (`$this->addError(...)`) and
  short-circuits on the empty-string guard, so it is **not** a pure
  array_map/array_filter candidate; converting it would change side-effect
  semantics or readability for no gain.
- `parseValue()` (lines 359–370): the early-return + `is_string($parsed) && …`
  guard is already minimal and ECS-idiomatic; `App::parseEnv` can return
  non-string, so the explicit guard cannot collapse to a `??`/`?->` form.
- Getters (`getActiveSiteKey`/`getActiveSecretKey`, lines 313–328) use a clean
  ternary on `captchaType`; no repeated lookup to hoist.

### `FieldModel.php`
- No array-building/filtering loops exist.
- `applyOverride()` (lines 179–183) already uses a single guarded ternary;
  `isInputType()`/`normalizeValue()`/`persistValue()` already use `?->`/`??`
  and `!== null` idiomatically. Each field-type registry lookup occurs once per
  method, so there is no repeated sub-expression to hoist.

### `FormModel.php`
- The constructor `foreach` (lines 18–27) builds `$this->fields` but **is not a
  pure transform**: it instantiates `FieldModel` objects via a multi-argument
  named constructor and assigns them keyed by `$rawField['id']`. An
  `array_map` / `array_column` / `array_combine` rewrite would obscure the
  per-arg casts and the id-keying, and would not be ECS-cleaner or faster — so
  it is deliberately not flagged.

### `ImportResult.php`, `IntegrationModel.php`, `NotificationModel.php`
- Plain property bags plus a trivial `defineRules()` (and, for
  `NotificationModel`, a side-effectful `validatePdfAvailable` validator). No
  loops, no redundant intermediates, no hoistable expressions, nothing for a
  `match`/`??`/`?->` rewrite. `NotificationModel::validatePdfAvailable`
  (lines 68–76) is a guarded `addError` side-effect — not a transform.

---

## Conclusion

The `src/models/` directory is already lean. Consistent with its having cleared
5 prior cleanup audits, there are **no** behaviour-preserving, gate-safe
optimisations to recommend.
