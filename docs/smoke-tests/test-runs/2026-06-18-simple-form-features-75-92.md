# Smoke-test run — Simple Form features #75–#92
Date: 2026-06-18 · Env: DDEV craft-plugin-dev (Craft 5.10.5) · Form under test: **Smoke Form** (id 9130)

Executed against the scenario library (`docs/smoke-tests/plugins/simple-form/scenarios.md`).
Verification: CP UI snapshots + DB (`ddev mysql`) + queue + `web-2026-06-18.log` (stayed at 0 lines throughout).

## Results
| Scenario | Result | Evidence |
|----------|--------|----------|
| S0 builder create | ✅ | Form 9130 + fields name(text,#9), email(email,#10) persisted; palette shows all 9 types incl. File Upload; Step/Page input present |
| S1 add Webhook integration | ✅ | `simpleform_integrations` row id 2 (webhook/“Ops hook”/enabled), settings JSON (no plaintext secret) |
| **S2 toggle + delete** | **❌ → ✅ (bug fixed)** | Toggle/delete returned 400 — see bug #2. After fix: toggle flips `enabled=0`; delete removes row + cascades logs |
| S3 submit → dispatch + Resend | ✅ | submission 9131; queue ran; `integration_logs` success/200/attempt 1; detail panel + Resend → second row attempt 2 |
| S5 connectors selectable + forms | ✅ | Type picker lists all 7; webhook + Mailchimp settings forms render; blank Mailchimp save → “Api Key/Audience Id cannot be blank” (no row) |
| S10 multi-step | ✅ | front-end `smoke/simple-form` route renders Step 1 of 2 (Name+File) → Next → Step 2 (Email)+Back+Submit; submit 9135 = one submission, all fields |
| S6 captcha providers | ✅ | Provider select = [recaptcha,turnstile,hcaptcha]; switching to Turnstile shows only its block + reveals turnstile key fields (JS toggle) |
| S7 Akismet spam status/filter | ✅ | Akismet settings section present; `UPDATE readStatus='spam'` succeeds (enum migration); index **Spam** filter lists 9132 with SPAM badge |
| S8 file field builder config | ✅ | field #11 config `{volume:uploads, maxSize:5, allowedExtensions:"pdf,png"}`; volume dropdown populated from SF_VOLUMES |
| **S9 file upload → asset + link** | **❌ → ✅ (bug fixed)** | See bug #1; after fix submission 9134 → asset 9133, detail shows download link to `/uploads/fix-verify.pdf` |
| S11 CSV export | ✅ | `text/csv` + `attachment; filename="submissions.csv"`; header `ID,Form,Status,Submitted,Name,Email,"File Upload"`; all rows; file col = asset id |
| S12 dashboard widgets | ✅ | “Form Submissions” + “Recent Submissions” appear in the New Widget menu (registration); count/recent logic covered by integration tests |

## 🐛 Bug found + fixed — S9 (file uploads silently dropped)
**Symptom:** uploading a `.pdf` via `/simple-form/submit` stored the file field as `value: null` and created **no asset** (submission 9132).

**Root cause:** `SubmitController::actionIndex` built values from body params only (`fieldValues()`) and called `SubmissionService::submit()` directly, **bypassing `createFromRequest()`** — where all file-upload handling lives (UploadedFile → validate → asset → ids + orphan rollback). Files are never body params, so the field resolved to null. The #89 tests passed because they exercised the service in isolation, never the controller→upload wiring.

**Fix (`c1f830b`):** `SubmitController` now calls `createFromRequest($form, $request)` (upload-aware; also resolves honeypot + userId); removed the dead `fieldValues()`. Added a regression integration test that drives the **real `SubmitController`** with an injected `$_FILES` entry + stubbed `AssetUploadService` (asserts the file field carries asset ids, not null), and updated the source-guard unit test.

**Re-verified live:** submission 9134 → `field_11: value [9133]`, asset **9133 `fix-verify.pdf`** created in `uploads`. Gate green: 157 unit / 140 integration (1 vol-skip) / 39 JS.

## 🐛 Bug #2 found + fixed — S2 (integration toggle/delete 400)
**Symptom:** clicking the enable toggle (and delete) on the per-form Integrations index did nothing; the POST returned **HTTP 400**.

**Root cause:** the index template's `post()` XHR helper set `X-Requested-With`/`X-CSRF-Token`/`Content-Type` but **not `Accept: application/json`**, while `IntegrationsController::actionToggle()` and `actionDelete()` call `requireAcceptsJson()` → 400. Slipped because #79's CP UI was authored while the browser backend was down (never live-tested).

**Fix (`25a7b6b`):** add `xhr.setRequestHeader('Accept', 'application/json')` to the shared `post()` helper (covers both toggle + delete). **Re-verified live:** toggle flips `enabled`, delete removes the row and cascades its `integration_logs`.

## Front-end smoke harness added
`templates/_smoke/simple-form.twig` + route `smoke/simple-form` (site repo, mirrors the existing beacon/cartograph smoke templates) render `{{ simpleForm('smokeForm') }}` so the multi-step + file-input + JS can be exercised live.

## Final tally
Executed live: **S0, S1, S2, S3, S5, S6, S7, S8, S9, S10, S11, S12** — all ✅ (S2/S9 after fixing the two bugs below). **2 real bugs found + fixed + re-verified.**

## Discoverability improvement
Integrations are **per-form** (no global nav item). The only entry point was the Forms-index row action; added an **Integrations** link to the form **edit** screen too (saved forms) so it's reachable while editing — committed on the plugin repo.

## S4 (manageIntegrations gating) — ✅ verified LIVE with a limited user
Created group **sfLimited** (CP access + `accessplugin-simple-form` + `simple-form:viewSubmissions` only) and user `limited@example.test`, logged in as them:
- `/admin/simple-form/submissions` + view → **200** (has viewSubmissions)
- `/admin/simple-form/forms` → **403** (lacks manageForms)
- `/admin/simple-form/forms/9130/integrations` → **403** (lacks manageIntegrations)
- Simple Form subnav shows **only Submissions** (no Forms/Settings) — `getCpNavItem` gates by permission.

**Note (not a bug, worth documenting):** every `/admin/simple-form/*` URL also requires Craft's section permission **`accessplugin-simple-form`**; without it Craft returns 403 before the plugin's own gate runs (it gates the whole section). So a user needs *both* plugin-section access *and* the granular Simple Form permission. Initially missed this in setup → all URLs 403'd until granted.
- S5 per-connector settings forms beyond webhook + Mailchimp — all confirmed **selectable**; each form’s fields covered by the unit/integration suites.
- S12 add-widget-to-dashboard modal interaction — registration confirmed live; count/recent logic integration-tested.

## Bugs found
1. **File uploads dropped** — `SubmitController` bypassed `createFromRequest` (`c1f830b`).
2. **Integration toggle/delete 400** — missing `Accept: application/json` header (`25a7b6b`).

Both were CP/front-end paths authored while the Playwright backend was down — exactly the gap this smoke run closed.

## Test data left behind
Front-end harness: `templates/_smoke/simple-form.twig` + `smoke/simple-form` route (site repo). Form **9130 “Smoke Form”** (handle `smokeForm`, now multi-step: email on page 2) with fields name/email/attachment; submissions 9131 (new), 9132 (spam), 9134 (asset 9133), 9135 (multi-step); asset 9133 `fix-verify.pdf` in `uploads`. Remove if undesired.
