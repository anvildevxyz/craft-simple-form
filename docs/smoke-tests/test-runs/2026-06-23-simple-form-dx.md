# Smoke run — Simple Form DX/extensibility (#218–#226)

Date: 2026-06-23 · Plugin schemaVersion 2.13.0 · Scenarios: `scenarios.md` §H (SF-DX-01..13)
Verification channels: console (`ddev exec php …`), HTTP (`curl` via the DDEV web
container), DB (`ddev mysql`), and sentinel files written by the `modules/sfdx`
dogfood harness. **Playwright was not used** — its backend has no network route to
the local DDEV site; these are CLI/HTTP/extensibility features, so console/HTTP/DB
verification is the appropriate channel (and the same surfaces are covered by the
plugin's automated integration suite).

## Result: 13/13 passed ✅  · 1 bug found (BUG-1, see below)

| # | Scenario | Evidence | Result |
| --- | --- | --- | --- |
| SF-DX-01 | Extension points registered | `sfdx-check.php`: color / sfdxLog / sfdxNull = YES | ✅ |
| SF-DX-02 | forms/apply creates + status | `+ dxSmoke: created (id 9295)`; status `[config] dxSmoke` | ✅ |
| SF-DX-03 | id-stable re-apply | `~ updated in place (id 9295)`; id 9295→9295 stable | ✅ |
| SF-DX-04 | prune guards submission data | `IdStableApplyTest` (keep-with-data / drop-empty) | ✅ |
| SF-DX-05 | full-fidelity export + v1 back-compat | `FullFidelityExportTest` (5 cases) | ✅ |
| SF-DX-06 | custom field type renders + stores | `type="color"` in markup; stored `#ff8800` | ✅ |
| SF-DX-07 | custom theme applied | `sfdx-themed` class ×3 in `/smoke/sfdx` | ✅ |
| SF-DX-08 | lifecycle events fire | `SFDX-CONTEXT-OK`; `SFDX-AFTERSAVE id=9296 new=1` | ✅ |
| SF-DX-09 | captcha provider scoped bypass | dxSmoke `success:true`; smokeForm `captcha` error | ✅ |
| SF-DX-10 | custom integration dispatches | `SFDX-INTEGRATION submission=9296` | ✅ |
| SF-DX-11 | front-end JS hook events | served `simple-form.js` has all 4 `simpleform:*` events | ✅ |
| SF-DX-12 | make/* generators | `make/field-type` → valid PHP, `extends FieldType` | ✅ |
| SF-DX-13 | apply-on-craft-up | `php craft up` → `~ dxSmoke: updated in place` | ✅ |

Cross-cutting: web log had **0 error/warning-level entries** and no exceptions
(1693 info-level lines from `craft up` + request logging in devMode). No failed
queue jobs (sync dispatch).

## BUG-1 — a trashed form with a config-managed handle wedges `forms/apply`

**Found during SF-DX-02 setup** (after soft-deleting `dxSmoke` to demonstrate a
fresh create). Realistic trigger: **delete a config-managed form in the CP, then
deploy.**

**Symptom:** `forms/apply` aborts the whole run with
`! dxSmoke: A form with the handle "dxSmoke" already exists.` (exit DATAERR), and
can neither create nor update.

**Root cause (inconsistent existence checks):**
- `FormsController::actionApply()` chooses create-vs-update with
  `Form::find()->handle($h)->siteId('*')->status(null)->one()`, which **excludes
  trashed** elements → sees no live form → takes the *create* path.
- The create path (`import(..., MODE_ABORT)` → `resolveHandle`) calls
  `FormContentHelper::handleExists()`, a **raw `simpleform_forms` query that
  ignores `dateDeleted`** → returns `true` for the trashed form → throws.

**Severity:** medium — blocks deploys after a CP delete; one bad/edge file also
aborts the entire `apply` run (no per-file isolation).

**Proposed fix** (`src/console/controllers/FormsController.php`, in `actionApply`,
per file): detect the trashed-handle case and **skip that file with an actionable
warning**, continuing the run — never silently resurrect or hard-delete.

```php
$existing = Form::find()->handle($handle)->siteId('*')->status(null)->one();
if ($existing === null && \anvildev\simpleform\helpers\FormContentHelper::handleExists($handle)) {
    $this->stdout("  = {$handle}: a trashed form with this handle exists; restore or permanently delete it, then re-apply. Skipped.\n", Console::FG_YELLOW);
    $skipped++;
    continue;
}
```

(Consider also catching per-file exceptions in the create/update `try` so one bad
file warns + skips instead of aborting the whole run.)

**Status:** reported; fix not yet applied (awaiting decision). Worked around in this
run by purging the trashed row.

## Test data / state left behind
- Form `dxSmoke` (id 9295) + its submissions (9296…) in the dev DB.
- `modules/sfdx/` harness bootstrapped; `config/simple-form.php` selects the scoped
  captcha + sync dispatch (no `applyFormsConfigOnUp` — set/removed during DX-13).
- `config/simple-form/forms/dxSmoke.json`, theme `_sfdx-theme/`, route `smoke/sfdx`.
