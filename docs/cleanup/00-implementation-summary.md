# 00 — Code-Quality Cleanup: Implementation Summary

**Date:** 2026-06-21
**Branch:** `chore/code-quality-cleanup`
**Baseline & final gate:** `composer check` (ECS + PHPStan L7 + 450 unit tests) green;
integration suite green (424 tests, 3 skipped) via DDEV.

Eight read-only audits (reports `01`–`08`) ran in parallel, one per dimension. The
codebase was already well-factored — five of the eight dimensions found nothing to
change. High-confidence, **execution-verifiable** recommendations were implemented in
four batches; three High/Medium items were deferred for the reasons below.

## Implemented

| Batch | Source report | Change |
|-------|---------------|--------|
| A | 04 (cycle) | Broke the only service↔service dependency cycle: moved the default submission-body builder out of `EmailService` into a new `SubmissionBodyRenderer`; `PdfService` now renders its sandboxed template through `SafeRenderService` directly. |
| B | 02-A, 02-B | Field-type discriminators now reference `FieldType::getType()` instead of `'email'`/`'file'`/… literals (8 services). Added `ACTION_*`/`TARGET_*` consts on `AuditService` and referenced them at all 13 `log()` call sites (stored values unchanged). |
| C | 01-H3/H4/H5 | Extracted `FormContentHelper` holding the per-site `CONTENT_ATTRS` list plus the byte-identical `handleExists()`/`fieldIdsByHandle()` queries; `FormCloneService` and `FormPortabilityService` now delegate. |
| F | 07-Low, 05 | `SubmissionValues::labelledLines()` now reads through a new mixed-typed `label()` plus `value()`, guarding legacy bare-scalar rows from a PHP 8 string-offset `TypeError`. Documented `McpServer` JSON-RPC `$id` as `string|int|null`. |

Dimensions **03 (unused code)**, **06 (defensive/try-catch)**, and **08 (comments/slop)**
found no changes worth making — see those reports. **05 (weak types)** found the code
already fully shaped (no bare `array`, no masking suppressions).

## Deferred (with rationale)

- **01-H1 — Extract a `FieldsService` to unify CP `FieldsController` and MCP `FieldOps`
  field-write CRUD.** Highest value (kills a documented drift hazard), but the two paths
  have several real *behavioral* divergences — `FieldOps` sanitizes/validates conditional
  rules while the CP path does not; `helpText` empty-coercion differs (`!== ''` vs `?:`);
  cache-invalidate timing, transaction ownership, and site-fallback all differ. A faithful
  merge must preserve each divergence deliberately, and the **CP write path has no runnable
  test** in this environment (the functional/browser smoke runner is unwired) — only the
  `FieldOps` path is integration-covered. Recommended as a human-reviewed follow-up: extract
  a pure-DB-mechanics `FieldWriter` both paths delegate to, after adding functional coverage
  for `actionAdd/Edit/Delete/Reorder`.

- **01-H2 — `withTransaction()` helper.** The three transaction bodies are large (56 and
  100 lines) and capture many locals; wrapping them in closures is mechanically error-prone
  and not a clear readability win over the correct, uniform explicit `begin/commit/catch-
  rollback` pattern. Marginal LOC reduction, real scoping-bug risk — not done.

- **01-H6 — `RecaptchaProvider` extends `AbstractSiteverifyProvider`.** Security-sensitive
  (spam protection), modest value (−25 LOC), and — unlike hCaptcha/Turnstile — Recaptcha
  has **no dedicated verify regression test**, so the changed v3-scoring/secret-parsing
  behavior can't be execution-verified here. Recommended together with a new
  `RecaptchaVerifyTest`.

Medium/Low items in reports `01`/`02` (M1–M4, L1–L2, type aliases C–G) were left as-is —
each is marginal ROI or carries a leaky-abstraction / scoping caveat called out in its
report.
