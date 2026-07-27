# Optimisation audit — services batch B

Veteran-PHP-optimiser pass over three service files in the `anvildev\simpleform`
plugin (PHP 8.3, Craft CMS 5). **Research only — no source was modified.**

**Scope (files reviewed in full):**

- `src/services/IntegrationsService.php`
- `src/services/FieldSyncService.php`
- `src/services/FormCloneService.php`

**Constraints honoured:** behaviour-preserving only (public I/O, side-effects,
every throw/catch/log/return, runtime ordering, observable behaviour all
unchanged); must stay green under ECS (Craft style — PHPDoc + early returns kept,
no golfed formatting) and PHPStan level 7; no new deps; no cross-file moves;
license/intent comments kept. The codebase has cleared 5 prior cleanup audits
(DRY / dead-code / weak-types), so those classes of issue are intentionally not
re-flagged — this is a pure optimisation/idiom pass on top of that.

---

## Findings table

| # | File:line | Pattern | Confidence | Verdict |
|---|-----------|---------|------------|---------|
| 1 | `IntegrationsService.php:337–342` | side-effect-free key-filter `foreach` → `array_filter(..., ARRAY_FILTER_USE_KEY)` | MED | Recommend |
| 2 | `IntegrationsService.php:554–567` | `->all()` + count-in-PHP → `GROUP BY` aggregate (DB load) | LOW | Note only — query-count/ordering change |
| 3 | `FormCloneService.php:69–82` | two adjacent same-array (`$siteIds`) read loops → fuse | LOW | Note only — changes query interleave order |

**Result: 1 recommended finding (MED) + 2 notes that do NOT qualify under the strict constraints.**

---

## HIGH-CONFIDENCE list

*(none)*

There are no HIGH-confidence findings. The one recommended change is MED.

---

## Recommended finding (MED)

### Finding 1 — `IntegrationsService.php:337–342` — `validateSettings()` options loop

**Current code:**

```php
        foreach ($rules as $rule) {
            $options = [];
            foreach ($rule as $key => $value) {
                if (!is_int($key)) {
                    $options[$key] = $value;
                }
            }
            $model->addRule((array) ($rule[0] ?? []), $rule[1] ?? 'safe', $options);
        }
```

**Replacement:**

```php
        foreach ($rules as $rule) {
            $options = array_filter($rule, static fn($key): bool => !is_int($key), ARRAY_FILTER_USE_KEY);
            $model->addRule((array) ($rule[0] ?? []), $rule[1] ?? 'safe', $options);
        }
```

**Why behaviour-identical:**
- The inner loop is a pure, side-effect-free transform: it copies every entry of
  `$rule` whose key is **not** an integer into `$options`, keeping both key and
  value. `array_filter` with `ARRAY_FILTER_USE_KEY` and the same `!is_int($key)`
  predicate selects exactly the same entries.
- `array_filter` preserves keys and **insertion order**, so the resulting
  `$options` array is identical (same keys, same values, same order) to the
  hand-built one — important because Yii's `addRule()` passes these as named rule
  options.
- No early return, no `continue`, no logging, no DB access inside the loop body —
  nothing else happens that could be reordered. The surrounding `foreach ($rules …)`
  and the `addRule()` call are untouched, so rule registration order is preserved.
- The `static fn` closure has no captures, so PHPStan L7 infers `bool`; the
  explicit `: bool` return type keeps it strict. ECS-clean (one statement, no
  golfing).

**Benefit:** removes a nested loop and a mutable accumulator in favour of one
idiomatic, self-describing call; marginally faster (single C-level pass, no
per-iteration PHP append).

**Confidence:** MED — semantics are provably identical, but it sits in a
validation path, so it is flagged MED rather than HIGH out of conservatism.

---

## Notes that do NOT qualify (kept for completeness)

### Note 2 — `IntegrationsService.php:554–567` — `getDispatchHealth()` loads all rows to count

`getDispatchHealth()` runs `(new Query())->…->all()` over the entire log set for
one integration, then counts statuses in PHP and reads `$rows[0]` for the latest
attempt. A `GROUP BY status` aggregate (+ a separate `ORDER BY … LIMIT 1` for the
last row) would avoid materialising every diagnostic row.

**Why it does not qualify:** it changes the query count (1 → 2) and the structure
of the work, and `total => count($rows)` counts **all** rows including any status
outside the three tracked keys — an aggregate rewrite would have to reproduce that
exactly. The brief requires identical behaviour *and ordering*; a multi-query
rewrite is a behavioural change in shape even if the returned array matches. Left
as a note only.

### Note 3 — `FormCloneService.php:69–82` — two adjacent `$siteIds` read loops

`duplicate()` iterates `$siteIds` twice in a row: first to build `$contentBySite`
(conditional `Form::find()` per site), then to build `$fieldsBySite`
(`sourceFieldsToSyncItems()` per site). They share the iterable, have no data
dependency on each other, and could be fused into a single `foreach`.

**Why it does not qualify:** both loops issue DB reads, and fusing them
interleaves the content query and the field query per site instead of running all
content queries first. The reads are side-effect-free so the final arrays are
identical, but the **runtime ordering of the emitted SQL changes** — which the
brief explicitly asks to preserve. The micro-gain (one fewer `foreach` header over
a tiny site list) does not warrant touching observable query order. Left as a note
only.

---

## Spots explicitly checked and deliberately left alone

- **`IntegrationsService::getFailedDispatches()` (619–627):** the
  `$submissionId => true` keyed dedup loop + `array_keys(...)` batch-load is
  already the intended optimisation (the comment says so); an `array_column`
  rewrite would lose the `(int)` cast / null-skip clarity for no gain.
- **`IntegrationsService::getDispatchHealth()` counts loop (563–567):** the
  `isset($counts[$status])` guard makes it a conditional accumulate, not a clean
  `array_count_values`/`array_filter` one-liner without changing which statuses
  are counted.
- **`encryptSettings` / `decryptSettings` / `scrubSecrets` / `redactSecrets`
  loops:** each mutates `$settings` in place under guards (and `decryptSettings`
  catches + logs); not pure transforms.
- **`parseEnvSettings` (531–546):** already uses a recursive `array_map` closure —
  the idiomatic form is in place.
- **`FieldSyncService::validate` / `calculationSetErrors` / `conditionalSetErrors`
  / `repeaterConfigErrors`:** every loop appends to `$errors` interleaved with
  multiple guards and `continue`/early-out; not side-effect-free transforms.
- **`FieldSyncService::htmlBlockBodyChanged` (344–372):** a DB query per HTML block,
  but it early-returns on the first changed body — batching would change the
  short-circuit behaviour (and load rows it currently skips). Correctly left as-is.
- **`FieldSyncService::sync` (405–492):** the per-item loop is all DB writes
  (insert/update/upsert) inside a transaction; not a candidate.
- **`FieldSyncService::splitOptionLabels` / `FormCloneService::mergeSiteLabels`:**
  by-reference `foreach` mutating options in place — required, not transformable.
- **`FormCloneService::orderSitesPrimaryFirst` (376):**
  `[$primarySiteId, ...array_filter(...)]` is already the clean idiom.
- **`FormCloneService::stripIds` / `applyFieldIds` / `sourceFieldsToSyncItems`:**
  already `array_map`-based; no further win.
- **`FormCloneService::copyNotifications` / `copyIntegrationAttachments` /
  `stencilNotifications`:** each loop does object construction + a DB write or
  `$service->save()` (side-effects), so not pure transforms.

---

## Conclusion

These three services are already lean — consistent with having cleared 5 prior
cleanup audits. A pure optimisation/idiom pass surfaces exactly **one** safe,
gate-compatible, behaviour-identical improvement (Finding 1, MED), plus two
DB/ordering-shaped notes that are deliberately *not* recommended because they
would alter query count or query-emission order.
