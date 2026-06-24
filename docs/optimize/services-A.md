# Optimisation audit — services batch A

Scope (read in full):

- `src/services/SubmissionService.php`
- `src/services/FormPortabilityService.php`
- `src/services/FormRenderService.php`

Constraint reminder: behaviour-preserving only (public I/O, side-effects, error
behaviour, ordering, observable output unchanged); must stay green under ECS
(Craft style, PHPDoc + early returns kept) and PHPStan level 7; no new deps, no
cross-file moves. The codebase has passed 5 prior cleanup audits (DRY,
dead-code, weak-types), so this pass only hunts pure idiom/perf wins those
sweeps don't cover.

## Summary

This is a deliberately conservative pass. The three files are already in good
shape: side-effect-free loops are mostly `array_map`/`array_combine` already
(`FormRenderService::_resolvePartials`, `stepRows`, `contentHash`), repeated
service lookups (`$plugin`, `$settings`, `$formModel`, `$fieldTypeRegistry`) are
already hoisted out of loops, and the two field loops in `processSubmission`
cannot be fused (see "Rejected" below). What remains are a handful of micro
idiom wins, each low blast-radius.

Counts: **HIGH 1 · MED 2 · LOW 2**

## Findings table

| # | File:line | Current | Replacement | Why behaviour-identical | Benefit | Confidence |
|---|-----------|---------|-------------|-------------------------|---------|------------|
| 1 | FormPortabilityService.php:224-227 | `$typeByHandle = [];`<br>`foreach ($existingRows as $row) {`<br>`    $typeByHandle[(string)$row['name']] = (string)$row['type'];`<br>`}` | `$typeByHandle = array_column($existingRows, 'type', 'name');` | Same input array, same key→value mapping, no side-effects, result consumed only by `isset()`/`!==` string comparison later. SEE CAVEAT below — value `(string)` cast is dropped and `array_column` int-coerces purely-numeric string keys; only safe because field handles are validated identifiers (never numeric) and `row['type']` is already a string column. | Removes an explicit loop; one builtin call | LOW |
| 2 | FormRenderService.php:205-208 | `$prefill = [];`<br>`foreach (($submission->data ?? []) as $key => $entry) {`<br>`    $prefill[$key] = SubmissionValues::value($entry);`<br>`}` | `$prefill = array_map([SubmissionValues::class, 'value'], $submission->data ?? []);` | `array_map` preserves string keys for a single array argument, iterates in the same order, and `SubmissionValues::value()` is a pure static accessor (no side-effects). Output map is identical. | Pure transform expressed as a transform; one fewer mutable accumulator | MED |
| 3 | SubmissionService.php:269 & 375 | `$spamReason = $core['spamReason'] ?? ($isSpam ? 'akismet' : null);` | (no change — documented as a non-finding) | — | — | — |
| 4 | FormRenderService.php:688-692 | `$css = $this->_readInlineAsset(FormAsset::distPath('css/simple-form.css'));`<br>`$js = $this->_readInlineAsset(FormAsset::distPath('js/simple-form.js'));`<br>`return '<style>' . $css . '</style>' . '<script>' . $js . '</script>';` | Inline the two single-use locals into the return string. | `_readInlineAsset` is called once each, in the same order; no reuse of `$css`/`$js`. Concatenation order and content unchanged. | Removes 2 single-use intermediates | LOW |
| 5 | SubmissionService.php:285 & 556 | `$siteId = $context['siteId'] ?? $form->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;` (computed in both `submit()` and `processSubmission()`) | (no change — see Rejected) | — | — | — |

## HIGH-confidence findings

> None of the findings clear the HIGH bar without a caveat. The cleanest win
> (#2) is rated MED because it changes a `foreach` to `array_map` across an
> element-model-derived array (`$submission->data`); behaviour is identical but
> it is worth a reviewer's eye on the null-coalesce on the right-hand side.

The closest-to-mechanical, lowest-risk change is **#4** (inline two single-use
locals) and **#2** (`foreach` → `array_map`). Recommend landing those two; treat
#1 as optional (the cast caveat makes it not strictly mechanical).

## Notable rejections (intentionally NOT flagged)

These looked like candidates but violate a hard constraint or aren't a genuine
win — recorded so a later pass doesn't re-litigate them:

- **`processSubmission` loops (3) + (4) NOT fusible** (SubmissionService.php:523
  and 558). Loop (4)'s `$field->isVisible($valuesByHandle)` reads the *complete*
  `$valuesByHandle` snapshot — which is finalised only after the
  `EVENT_BEFORE_VALIDATE` handler at line 542-543 may replace it. Fusing would
  evaluate visibility against a partially-built map. Ordering/semantics change →
  not a finding.

- **`$siteId` recomputed in `submit()` and `processSubmission()`** (lines 285,
  556). They live in different methods; `processSubmission` returns only `data`/
  `isSpam`/`spamReason`, not the site id. Threading it back would change the
  method's return contract (observable surface) and is a refactor, not an
  idiom swap. Not behaviour-preserving-by-construction → skip.

- **`contentHash` (881-890), `stringifyValue` (1078-1089)** already use
  `array_map`/`array_filter`. No change.

- **`firstEmail` (911-922), `guestEmailValue` (998-1010)** are `foreach` loops
  with an early `return` (first-match short-circuit) — not pure transforms;
  rewriting via `array_filter` + `array_key_first` would scan the whole array
  and lose the short-circuit. Leave as-is.

- **`fieldIdsWithSubmissionData` (374-396)** uses a `$ids[$k] = true` set then
  `array_keys()` — the de-dupe-via-keys idiom is already the efficient form; the
  body does `json_decode` + `preg_match` per row and cannot collapse to a single
  builtin. No win.

- **`_resolvePartials` (399-403)** already `array_combine(array_map(...))`.
  `stepRows`/`resolvedFields` (277, 286) already `array_map`. No change.

- Repeated `Plugin::getInstance()` calls inside loops were already hoisted in
  prior audits (`$plugin`, `$settings`, `$formModel`, `$fieldTypeRegistry`,
  `$service`, `$sites`). Confirmed none remain inside a loop body.

### Caveat detail for #1 (`array_column`)

`array_column($existingRows, 'type', 'name')` differs from the explicit loop in
two ways that are *safe here but not in general*:

1. It does not apply `(string)` to the value. Safe because `type` is a string DB
   column (`FieldQueryHelper` selects `f.type` raw).
2. PHP coerces a purely-numeric string index key to int. Safe because the `name`
   column is a field *handle* (a validated identifier, never all-digits), and
   the map is only read via `isset()` / string `!==`, both of which tolerate the
   key type. If handles could ever be numeric this would silently change keys —
   hence LOW, not MED.
