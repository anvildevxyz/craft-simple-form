# Delta Cleanup Summary — 2026-06-22

A second 8-concern code-quality pass over `simple-form`, scoped to PHP source
changed since the first audit (commit `c5b8fe7`, ~70 files). One read-only agent
per concern (reports `01`–`08`); high-confidence patches applied serially with
`composer check` green throughout. WIP files (FormsController, Form, FormQuery,
FormRenderService, templates, tests) were excluded.

## Findings per concern

| # | Concern | Result |
|---|---------|--------|
| 1 | DRY / dedup | **1 applied** (P2), 1 deferred (P1) |
| 2 | Type consolidation | **5 applied** |
| 3 | Unused code | 0 — every new symbol has a live caller |
| 4 | Circular deps | 0 — clean DAG (prior EmailService↔PdfService cycle already broken by SubmissionBodyRenderer) |
| 5 | Weak types | 0 — no new weak typing; remaining `mixed` is framework-mandated |
| 6 | Defensive code | 0 — new try/catch is legit (payment IO, external/serialized data) |
| 7 | Legacy / fallback | 0 — delta clean; the one prior target is now a deliberate migration-backed fallback |
| 8 | Comments / slop | 0 — comments are WHY-context + issue refs; no in-motion narration |

The codebase was already clean — 6 of 8 concerns found nothing, matching the
2026-06-21 audit. The delta had also *closed* several prior findings (FormContentHelper
for the dedup items; AuditService consts and `::getType()` discriminators for the
type items).

## Applied patches (6)

**Concern 2 — file-local `@phpstan-type` aliases** (annotation-only, zero runtime change):
- `Formula.php` — `Token` (replaced a 6× inline shape)
- `EmailService.php` — `EmailAttachment` (3×)
- `DialCodes.php` — `DialCode` (3×)
- `RepeaterFieldType.php` — `RepeaterInnerField` (3×)
- `SubmissionCsv.php` — `CsvColumn` (2×)

**Concern 1 — P2: single source for the asset-type taxonomy:**
- Added `FieldTypeRegistry::ASSET_TYPES` (`['file', 'signature']`) next to the
  existing OPTION/SCALE/RELATION consts. `SubmissionCsv::ASSET_TYPES` now derives
  from it; `RetentionService` references it directly. Removes the duplicated
  literal across the CSV-export and retention-GC subsystems.

## Deferred

- **Concern 1 — P1 (`withTransaction()` helper):** the 3 "duplicated" transaction
  shells wrap 7-, ~50-, and ~100-line method bodies. Folding those into a closure
  helper adds nesting and hurts debuggability of load-bearing transaction methods
  without reducing real complexity — net-negative for the two long ones. Skipped
  by design, not by risk.
- Cross-file type-shape consolidations (prior Findings C/D/E/F) — their canonical
  homes are outside the delta; deferred to a whole-file pass.

## Verification

`composer check` green: ECS clean · PHPStan L7 no errors · 458 PHPUnit tests pass.
All applied changes are behavior-neutral (annotations + a const whose value is
identical to the literal it replaced), so the integration/smoke suites were not
required to confirm them.
