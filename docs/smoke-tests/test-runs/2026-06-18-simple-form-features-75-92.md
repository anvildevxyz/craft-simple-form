# Smoke-test run — Simple Form features #75–#92
Date: 2026-06-18 · Env: DDEV craft-plugin-dev (Craft 5.10.5) · Form under test: **Smoke Form** (id 9130)

Executed against the scenario library (`docs/smoke-tests/plugins/simple-form/scenarios.md`).
Verification: CP UI snapshots + DB (`ddev mysql`) + queue + `web-2026-06-18.log` (stayed at 0 lines throughout).

## Results
| Scenario | Result | Evidence |
|----------|--------|----------|
| S0 builder create | ✅ | Form 9130 + fields name(text,#9), email(email,#10) persisted; palette shows all 9 types incl. File Upload; Step/Page input present |
| S1 add Webhook integration | ✅ | `simpleform_integrations` row id 2 (webhook/“Ops hook”/enabled), settings JSON (no plaintext secret) |
| S3 submit → dispatch + Resend | ✅ | submission 9131; queue ran; `integration_logs` success/200/attempt 1; detail panel + Resend → second row attempt 2 |
| S5 connectors selectable | ✅ | Type picker lists all 7 (webhook/slack/discord/mailchimp/activecampaign/hubspot/pipedrive); webhook settings form renders URL/method/format/secret |
| S6 captcha providers | ✅ | Provider select = [recaptcha,turnstile,hcaptcha]; switching to Turnstile shows only its block + reveals turnstile key fields (JS toggle) |
| S7 Akismet spam status/filter | ✅ | Akismet settings section present; `UPDATE readStatus='spam'` succeeds (enum migration); index **Spam** filter lists 9132 with SPAM badge |
| S8 file field builder config | ✅ | field #11 config `{volume:uploads, maxSize:5, allowedExtensions:"pdf,png"}`; volume dropdown populated from SF_VOLUMES |
| **S9 file upload → asset** | **❌ → ✅ (bug fixed)** | See below |
| S11 CSV export | ✅ | `text/csv` + `attachment; filename="submissions.csv"`; header `ID,Form,Status,Submitted,Name,Email,"File Upload"`; all rows; file col = asset id |
| S12 dashboard widgets | ✅ | “Form Submissions” + “Recent Submissions” appear in the New Widget menu (registration); count/recent logic covered by integration tests |

## 🐛 Bug found + fixed — S9 (file uploads silently dropped)
**Symptom:** uploading a `.pdf` via `/simple-form/submit` stored the file field as `value: null` and created **no asset** (submission 9132).

**Root cause:** `SubmitController::actionIndex` built values from body params only (`fieldValues()`) and called `SubmissionService::submit()` directly, **bypassing `createFromRequest()`** — where all file-upload handling lives (UploadedFile → validate → asset → ids + orphan rollback). Files are never body params, so the field resolved to null. The #89 tests passed because they exercised the service in isolation, never the controller→upload wiring.

**Fix (`c1f830b`):** `SubmitController` now calls `createFromRequest($form, $request)` (upload-aware; also resolves honeypot + userId); removed the dead `fieldValues()`. Added a regression integration test that drives the **real `SubmitController`** with an injected `$_FILES` entry + stubbed `AssetUploadService` (asserts the file field carries asset ids, not null), and updated the source-guard unit test.

**Re-verified live:** submission 9134 → `field_11: value [9133]`, asset **9133 `fix-verify.pdf`** created in `uploads`. Gate green: 157 unit / 140 integration (1 vol-skip) / 39 JS.

## Not executed this run (lower risk / covered elsewhere)
- S2 (toggle/delete integration), S4 (manageIntegrations gating) — controller logic covered by integration tests; quick manual follow-up.
- S5 per-connector settings forms beyond webhook — all confirmed **selectable**; each form’s fields covered by the unit/integration suites.
- S9 submission-detail download link — not viewed live (rendering logic in `view.html`, integration-covered).
- S10 multi-step render — no front-end page renders the form in dev; covered by `MultiStepFormTest` (step markup + single submission).
- S12 add-widget-to-dashboard modal interaction — registration confirmed; widget count/recent logic integration-tested.

## Test data left behind
Form **9130 “Smoke Form”** (handle `smokeForm`) with fields name/email/attachment + Webhook integration id 2; submissions 9131 (new), 9132 (spam), 9134 (with asset 9133); asset 9133 `fix-verify.pdf` in `uploads`. Remove if undesired.
