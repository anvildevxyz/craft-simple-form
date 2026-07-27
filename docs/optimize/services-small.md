# Optimisation audit — src/services/ (small services)

Scope: the ~20 service files in `src/services/` **excluding** SubmissionService,
FormPortabilityService, FormRenderService, IntegrationsService, FieldSyncService,
FormCloneService (covered separately).

Research only — no source was modified. Findings are behaviour-preserving,
idiomatic, and intended to stay green under Craft ECS + PHPStan level 7.

## Summary

This corner of the codebase is already very tight (it has passed 5 prior cleanup
passes). A pure optimisation/idiom pass turns up **2 genuine low-risk findings**
and a handful of explicitly-rejected near-misses recorded so they are not
re-flagged later. There are **0 HIGH-confidence** findings — both surviving items
are LOW/MED.

## Findings table

| # | File:lines | Kind | Confidence | One-line |
|---|-----------|------|-----------|----------|
| 1 | ReportsService.php:176–177 (call site) / spamRate 53–60 | Redundant repeated queries | LOW | CP analytics view runs `statusBreakdown` (5 count queries) then `spamRate` which runs `statusBreakdown` again (5 more) — 10 element counts for the same site/form. Cannot be fixed inside the audited file behaviour-identically (see notes); recorded, not recommended. |
| 2 | FieldTypeRegistry.php:138–144 layoutTypeHandles() | Per-call object instantiation | LOW | Instantiates every registered field type on each call just to read `isInput()`. Cold path (called once during field sync); no cleaner ECS-safe in-place idiom. Recorded, not recommended. |

## HIGH-CONFIDENCE findings

**None.** Nothing in this file set is both a clear win and unambiguously
behaviour-preserving + gate-safe.

---

## Detail / rationale

### 1. ReportsService spamRate ↔ statusBreakdown double-count (LOW — NOT recommended)

`SubmissionsController` (out of scope, different file) calls:

```php
'stats' => $reports->statusBreakdown($siteId, $formId),   // 5 element count() queries
'spam'  => $reports->spamRate($siteId, $formId),          // calls statusBreakdown again → 5 more
```

`spamRate()` deliberately delegates to `statusBreakdown()` for count-consistency.
A real fix (compute the breakdown once and derive the spam/ham split from it)
lives at the **call site**, which is in another file — out of scope and disallowed
("no moving code between files"). Narrowing `spamRate()` to only the `spam`+`total`
counts would change its query count but is still 2 redundant queries against the
already-computed breakdown, and would not remove the duplication the controller
causes. Each `count()` also goes through the Submission **element query** (honours
default status / excludes trashed), so it is not safely replaceable by a single
raw `GROUP BY` without changing the numbers. **Recorded, not recommended.**

### 2. FieldTypeRegistry::layoutTypeHandles() (LOW — NOT recommended)

```php
return array_values(array_filter(
    array_keys($this->fieldTypes),
    fn(string $type): bool => !(new $this->fieldTypes[$type]())->isInput(),
));
```

Constructs a throwaway instance of every field type per call. It is already a
clean `array_filter` idiom; the only "optimisation" would be memoising the result
or hoisting the instances, which is a behavioural/structural change (caching),
not a pure idiom swap, and the method is on a cold path (one call during field
sync). **Recorded, not recommended.**

---

## Explicitly rejected near-misses (so they are not re-flagged)

These looked like candidates but are NOT behaviour-preserving and/or NOT cleaner,
so they are intentionally left alone:

- **ReportsService::perFormTotals (102–115)** — looks like a classic N+1 (one
  `Submission::find()->count()` per form). A batched `GROUP BY` would change the
  result: the element query honours default status and excludes trashed rows and
  resolves siteId via the element, whereas a raw column `GROUP BY` would not, and
  zero-submission forms must still appear. Counts would diverge. **Rejected.**

- **ReportsService::statusBreakdown (28–46)** — 5 element-query counts. A single
  grouped raw query would not match element-query count semantics (status,
  trashed, site). **Rejected.**

- **ReportsService::submissionsPerDay (83–87)** — the `foreach` building
  `$counts` casts `(string) $row['d']` / `(int) $row['c']`. `array_column($rows,
  'c', 'd')` would drop both casts (values stay as DB strings), changing the
  zero-fill lookup type behaviour. **Rejected (not equivalent).**

- **ReportsService::dispatchHealth (207–211)** — the `isset($health[...])` guard
  intentionally ignores unknown statuses; `array_column`/`array_combine` cannot
  reproduce that filtering. **Rejected.**

- **DenylistService::extractText (236–251)** vs **AkismetService::extractContent
  (84–98)** — near-duplicate blob builders, but in two different files; DRY-merge
  would require moving code between files (disallowed) and they differ in the
  array-join separator (`' '` vs `', '`). **Rejected.**

- **NotificationsService::getForForm (40)** already uses
  `array_map($this->rowToModel(...), $rows)`; **resolveRecipients (170)** already
  uses `array_values(array_unique(array_filter(...)))`. Already idiomatic.

- **RetentionService::purgeSubmissions (68)** already
  `array_map('intval', $query->column())`. **SubmissionBodyRenderer::formatFileValue**
  already batch-loads assets (no N+1). **PdfService**, **AssetUploadService**,
  **SafeRenderService**, **DraftService**, **SubmissionEditTokenService**,
  **AuditService**, **CaptchaService**, **CaptchaProviderRegistry**,
  **IntegrationTypeRegistry**, **FormStructureService**, **FieldsService** —
  reviewed, nothing left to tighten without a semantic/structural change.

## Conclusion

2 LOW-confidence findings, both explicitly **not recommended**; 0 HIGH-confidence.
The audited services are effectively at a local optimum for a behaviour-preserving
idiom pass — the remaining inefficiencies (per-form/per-status element counts) are
load-bearing for count correctness and cannot be batched without changing results.
