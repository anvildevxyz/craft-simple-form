# Code-quality cleanup — round 3 (2026-06-24)

Full re-audit of all 210 `src/` PHP files across the 8 concerns, run as 8
parallel read-only research agents (reports `01`–`08` in this folder). Baseline
was green (PHPStan L7, ECS, 463 PHPUnit) and stayed green after every batch;
integration suite (464 tests) green at the end.

Branch: `chore/code-quality-round3` (off the committed migration-collapse work).

## Applied

| Concern | Change | Commit |
|---------|--------|--------|
| Legacy (#7) | Removed dead `IntegrationsService::encryptStoredSecrets()` — its only caller (migration `m260620_000001`) was deleted in the collapse — and its now-dead integration test. | `ae11ea1` |
| Unused (#3) | Removed 3 orphaned translation keys (`Email Subject`, `Email Reply-To`, an unused captcha message) from all 8 locale catalogs. | `ae11ea1` |
| DRY (#1) | `FormPortabilityService` export/import now derives the per-site content list from `FormContentHelper::CONTENT_ATTRS` (the #226 export work had re-introduced the divergence). Preserves the title→name fallback. | `a4e63fe` |
| Comments (#8) | Deleted 2 restatement comments above `buildValueMap()`; refreshed stale "v1 is current" schema-upgrader comments (SCHEMA_VERSION is 2); trimmed 2 parentheticals narrating prior implementations. | `a4e63fe` |
| Types (#2) | `Form::POST_SUBMIT_*` and `PaymentFieldType::AMOUNT_TYPE_*` value consts replace raw magic strings across 5 files (matches the existing `GUEST_LIMIT_*` idiom). | `98e731b` |
| Types (#2) | `@phpstan-type` aliases for repeated array shapes: `SubmissionResult`, `ResumePrefill`, `HiddenUserAttrs`, `SelectOption` (integrations cluster). Annotation-only, PHPStan-verified. | `aae4c0e` |
| Harness | Fixed the migration-collapse fallout in the integration harness (double-applied `Install` in `codeception.yml`; orphaned `FormEmailMergeMigrationTest`). | `39c6361` |

## Deliberately not applied

- **Weak types (#5) — `SubmissionEvent::$data` → `?array`**: the audit claimed
  `yii\base\Event` has no `$data` property; it does (untyped), so a narrowed
  override fails PHPStan's property-variance check. Reverted, kept as `mixed`.
- **DRY (#1) HC-2 — `withTransaction()` extraction (3 services)**: the duplicated
  part is a 6-line `beginTransaction/try/commit/catch{rollBack;throw}` idiom, but
  the wrapped bodies are large (FieldSyncService ~90 lines, FormCloneService ~50).
  Extraction forces closure-wrapping with many `use()` captures — indirection that
  does not reduce complexity. Deferred (the prior two passes deferred it too).
- **DRY (#1) HC-3 — `FieldsService` extraction** (FieldsController ↔ MCP FieldOps
  CRUD duplication): high value but behaviour-sensitive and large; the audit
  recommends its own PR with MCP smoke Cests. Deferred.
- **Types (#2) Rec 5 — `LAYOUT_TYPES` const**: the site is a 3-way if/elseif that
  dispatches to different render helpers; a membership const doesn't fit. Skipped.
- **Types (#2) Rec 1 cross-domain sites**: `SelectOption` applied only to the
  integrations cluster (ElementMapping + CraftElementIntegration). The single
  widget/GraphQL occurrences were left inline to avoid a doc dependency on
  `integrations\support` from unrelated subsystems.

## Found clean (no action)

- **Circular deps (#4)**: 0 cycles — clean DAG, new code adds only downward edges.
- **Defensive code (#6)**: 52 catch sites, all justified; 0 changes.
- Comments/stubs, unused PHP symbols, and type discipline were otherwise at ship
  quality — see the individual reports.
