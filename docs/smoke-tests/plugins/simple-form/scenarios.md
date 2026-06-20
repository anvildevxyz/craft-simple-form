# Simple Form — Smoke-Test Scenario Library (#75–#92)

Authored 2026-06-18. **Not yet executed** (Playwright backend was unresponsive when
these features landed). Run each with the `craft-smoke-test` skill once the browser
is back. Every UI assertion below also has a DB/queue check so failures are
diagnosable without screenshots.

**Env:** `https://craft-plugin-dev.ddev.site/admin` · DB: `ddev mysql -e "…"` ·
queue: `ddev craft queue/run` · logs: `storage/logs/web-$(date +%F).log`.
German CP UI — match buttons by role, click checkbox **labels** not inputs.

**Status legend:** ⬜ not executed · ✅ pass · ❌ fail (file a bug)

**Shared setup (S0):** ensure a test form exists with a few fields.
- `/admin/simple-form/forms/new` → name “Smoke Form”, handle `smokeForm`, emailTo `ops@example.test` → add a Text field “Name” + an Email field “Email” → Save.
- Capture the form id from the edit URL for later DB queries.

---

## A. Outbound Integrations framework (#76–#81)

### S1 — Add a Webhook integration ⬜
1. SETUP: S0 form exists.
2. EXECUTE (UI): go to `/admin/simple-form/forms/{id}/integrations` → **New integration** → choose **Webhook**.
3. EXECUTE (UI): fill Name “Ops hook”, Webhook URL `https://example.test/hook`, leave Enabled on → Save.
4. VERIFY (UI): redirected to the integrations index; a row “Ops hook / Webhook / Enabled” is listed.
5. VERIFY (DB): `SELECT name,type,enabled FROM simpleform_integrations WHERE formId={id};` → `Ops hook | webhook | 1`.
6. VERIFY (DB): settings stored — `SELECT settings FROM simpleform_integrations WHERE formId={id};` contains the url (and **no** plaintext secret if an env ref was used).
7. VERIFY (Logs): no new errors.

### S2 — Toggle + delete an integration ⬜
1. SETUP: S1 done (one integration on the form).
2. EXECUTE (UI): click the enabled-status toggle on the row.
3. VERIFY (DB): `SELECT enabled FROM simpleform_integrations WHERE formId={id};` → `0`.
4. EXECUTE (UI): click delete → confirm.
5. VERIFY (DB): `SELECT COUNT(*) FROM simpleform_integrations WHERE formId={id};` → `0`.

### S3 — Submit → dispatch logged → Resend ⬜
1. SETUP: re-add an enabled Webhook (S1) pointing at a URL that returns non-2xx (e.g. `https://example.test/404`) so we can observe a `failed` log deterministically. Set **Dispatch integrations synchronously** ON in Settings → (or run the queue).
2. EXECUTE: submit the form on the front end (or `ddev craft` submit) so a submission is saved.
3. EXECUTE (Queue): `ddev craft queue/run`.
4. VERIFY (DB): `SELECT status,attempts FROM simpleform_integration_logs WHERE submissionId={sid};` → at least one `failed`/`success` row.
5. VERIFY (UI): submission detail `/admin/simple-form/submissions/{sid}` shows the **Integration Dispatches** panel with the integration name + status badge + a **Resend** button.
6. EXECUTE (UI): click **Resend** → notice “Dispatch re-queued.”
7. VERIFY (DB): a new log row appears for `(integrationId, submissionId)` with `attempts` incremented.
8. VERIFY (Queue): `ddev craft queue/info` → no *stuck* failed jobs beyond the expected retries.

### S4 — manageIntegrations permission gating ⬜
1. SETUP: create a CP user in a group with `viewSubmissions` but NOT `manageForms`/`manageIntegrations`.
2. EXECUTE (UI): as that user, visit `/admin/simple-form/forms/{id}/integrations`.
3. VERIFY (UI): access denied / no Integrations access (the controller requires `manageIntegrations`).
4. VERIFY (UI): submission detail still shows the dispatch panel but **no Resend** button for this user.

---

## B. Connectors selectable + settings render (#82–#84)

### S5 — All 8 connector types render their settings ⬜
For each `type` in [webhook, slack, discord, mailchimp, activecampaign, hubspot, pipedrive, google-sheets]:
1. EXECUTE (UI): `/admin/simple-form/forms/{id}/integrations/new` → type picker lists the type.
2. EXECUTE (UI): choose it → settings form renders the expected fields:
   - webhook: URL, HTTP method, payload format, signing secret
   - slack: Slack webhook URL, channel, username, message template
   - discord: Discord webhook URL, username, message template
   - mailchimp: API key, Audience ID, email field, double opt-in
   - activecampaign: API URL, API key, list ID, email field
   - hubspot: private-app token, object type (contact/deal), email field
   - pipedrive: API domain, API token, name field, email field
   - google-sheets: Authentication mode, service-account JSON key / OAuth client+refresh token, Spreadsheet, Worksheet, Field→column mapping (editable table), Write a header row
3. VERIFY (UI): required-field markers present; saving with the required field blank shows a validation error (no row written).
4. VERIFY (DB): a valid save persists `type` correctly in `simpleform_integrations`.

> Live dispatch for slack/mailchimp/etc. needs real endpoints/keys — out of scope for
> CP smoke. Dispatch mechanics are covered generically by S3 (webhook) + the unit/
> integration suites (mocked transports).

### S13 — Google Sheets connector (service account) (#141) ⬜
1. SETUP: S0 form exists with at least a `name` and `email` field.
2. EXECUTE (UI): `/admin/simple-form/settings/integrations/new` → choose **Google Sheets**.
3. EXECUTE (UI): Authentication = **Service account (JSON key)**; paste a (test) service-account JSON key (or an env ref like `$GOOGLE_SA_KEY`); Spreadsheet = a full sheet URL; Worksheet = `Leads`; add two mapping rows (`name → Name`, `email → Email`); turn **Write a header row** on → Save.
4. VERIFY (UI): redirected to the integrations index; a row “… / google-sheets / Enabled” is listed.
5. VERIFY (DB — encryption): `SELECT settings FROM simpleform_integrations WHERE type='google-sheets';` → the `serviceAccountKey` value is prefixed `sfenc:` (ciphertext); the raw `private_key` text is **not** present in cleartext. The mapping + spreadsheet id persist.
6. VERIFY (no echo): re-open the edit screen → the JSON key field is **not** re-rendered in plaintext (decrypted only at dispatch).
7. EXECUTE (UI): attach the integration to the S0 form (per-form Integrations toggle).
8. EXECUTE: submit the form; run `ddev craft queue/run`.
9. VERIFY (DB): a `simpleform_integration_logs` row for the submission. In CI without live Google access the most-recent attempt is `failed` with a **scrubbed** message (no bearer token, no key) — confirm by inspecting the `message` column. The success path is covered by `GoogleSheetsDispatchTest` (HTTP mocked).
10. EXECUTE (UI): on a deliberately-wrong worksheet name, after fixing it hit **Resend** on the submission detail and verify a fresh log row.
11. VERIFY (exposure): inspect the form via GraphQL (`SimpleFormIntegration` type) and the `list_integrations` MCP tool → only name/type/enabled (+ health) are returned; **no** settings/secrets cross either boundary.

---

## C. Pluggable captcha providers (#85–#87)

### S6 — Provider selector + per-provider key fields ⬜
1. EXECUTE (UI): Settings → **Spam Protection** (`/admin/simple-form/settings/spam`) → tick **Enable CAPTCHA**.
2. VERIFY (UI): a **CAPTCHA Provider** select appears listing **Google reCAPTCHA, Cloudflare Turnstile, hCaptcha**.
3. EXECUTE (UI): select **Cloudflare Turnstile** → VERIFY (UI) the Turnstile site/secret key fields show and the reCAPTCHA fields hide.
4. EXECUTE (UI): select **hCaptcha** → VERIFY (UI) hCaptcha site/secret key fields show.
5. EXECUTE (UI): pick Turnstile, fill dummy keys, Save.
6. VERIFY (DB/project config): `selectedCaptchaProvider = turnstile` persisted (Settings → reload shows Turnstile selected, keys retained).
7. VERIFY (UI): a rendered form (`{{ simpleForm('smokeForm') }}`) now emits the Turnstile widget (`cf-turnstile`) instead of reCAPTCHA. *(Needs valid keys to fully render; with dummy keys verify the widget container/script tag is present.)*

---

## D. Akismet content spam (#88)

### S7 — Akismet settings + spam status/filter ⬜
1. EXECUTE (UI): Settings → Spam → enable **Akismet**, enter a key, mode **Flag** → Save.
2. VERIFY (UI): reload shows Akismet enabled + mode Flag.
3. SETUP (DB): since we can't make Akismet flag a real submission deterministically without their API, insert/mark a submission as spam to exercise the CP surface:
   `UPDATE simpleform_submissions SET readStatus='spam' WHERE id={sid};`
4. VERIFY (UI): submissions index → the **Spam** filter option exists; selecting it (`?status=spam`) lists that submission with a red **SPAM** badge.
5. VERIFY (DB): the readStatus enum accepts `spam` (the UPDATE above succeeds — proves migration `m260618_000002`).

> Live flag/block behaviour is covered by the integration suite (mocked Akismet:
> flag-saves-spam, block-drops). CP smoke covers the settings + spam filter surface.

---

## E. File-upload field (#89)

### S8 — Configure a File field in the builder ⬜
1. EXECUTE (UI): edit the S0 form → drag/add a **File Upload** field; in the inspector set Asset Volume `uploads`, Allowed Extensions `pdf,png`, Max Size `5`, Allow Multiple off → Save.
2. VERIFY (DB): `SELECT type,config FROM simpleform_fields WHERE formId={id} AND type='file';` → config has `volume:uploads`, `allowedExtensions`, `maxSize:5`.
3. VERIFY (UI): render the form; the file field is `<input type="file" accept=".pdf,.png">` and the `<form>` has `enctype="multipart/form-data"`.

### S9 — Upload → asset + download link ⬜
1. EXECUTE (UI): on the rendered form, fill required fields + choose a small `.pdf`, submit.
2. VERIFY (DB): `SELECT data FROM simpleform_submissions WHERE id={sid};` → the file field value is a list of asset id(s).
3. VERIFY (DB): the asset exists — `SELECT id,filename FROM assets WHERE id={assetId};` (volume `uploads`).
4. VERIFY (UI): submission detail shows the uploaded filename as a **download link** to the asset URL.
5. VERIFY (rejection): submit a `.exe` → VERIFY (UI) a field error, VERIFY (DB) no new submission row and no orphan asset.
6. VERIFY (Logs): no errors.

---

## F. Multi-step forms (#90)

### S10 — Steps render + single submission ⬜
1. EXECUTE (UI): edit the S0 form → set the Name field **Step / Page** = 1, Email field = 2 → Save.
2. VERIFY (UI): render the form → two `.simple-form-step` containers (`data-sf-step="0"`,`"1"`), a **Next/Back** nav (`data-sf-multistep="2"`), and a “Step 1 of 2” progress indicator; step 2 is hidden initially.
3. EXECUTE (UI): fill step 1 Name → **Next** → step 2 shows; fill Email → **Submit**.
4. VERIFY (DB): exactly **one** new row in `simpleform_submissions` for the form, `data` containing both fields.
5. VERIFY (UI): Back from step 2 returns to step 1 with values retained.

---

## G. CP submissions CSV export (#91)

### S11 — Export filtered submissions ⬜
1. SETUP: at least 2 submissions exist for the S0 form.
2. EXECUTE (UI): submissions index → set the Form filter to “Smoke Form”, Status = All → click **Export CSV**.
3. VERIFY (download): the response is `text/csv` named `submissions.csv`.
4. VERIFY (content): header row is `ID,Form,Status,Submitted,<field labels…>` (e.g. `Name,Email`); one data row per submission; multi-value cells pipe-joined.
5. VERIFY (filter honoured): exporting with a Status filter (e.g. `new`) yields only matching rows.

---

## H. Dashboard widgets (#92)

### S12 — Count + Recent widgets ⬜
1. EXECUTE (UI): Dashboard → **New Widget** → the list includes **Form Submissions** and **Recent Submissions**.
2. EXECUTE (UI): add **Form Submissions**, set Range = “All time”, Form = “Smoke Form” → save widget.
3. VERIFY (UI): the widget shows a number equal to `SELECT COUNT(*) FROM simpleform_submissions WHERE formId={id}` (current site).
4. EXECUTE (UI): change Range to “Today” → VERIFY the count reflects only today's submissions.
5. EXECUTE (UI): add **Recent Submissions** (limit 5) → VERIFY it lists the newest submissions, each linking to `/admin/simple-form/submissions/{sid}`.

---

## Runner index (execute individually later)
```
/craft-smoke-test plugin:simple-form S1: add a Webhook integration to a form and verify the row + DB
/craft-smoke-test plugin:simple-form S3: submit a form, run the queue, verify an integration_logs row + Resend
/craft-smoke-test plugin:simple-form S5: open New Integration and verify each of the 8 connector settings forms render
/craft-smoke-test plugin:simple-form S13: add a Google Sheets integration (service account), verify the key is stored encrypted + not echoed, submit + Resend, and confirm no secret crosses GraphQL/MCP
/craft-smoke-test plugin:simple-form S6: Settings Spam — switch captcha provider to Turnstile/hCaptcha and verify key fields toggle + persist
/craft-smoke-test plugin:simple-form S7: enable Akismet, mark a submission spam, verify the Spam filter on the index
/craft-smoke-test plugin:simple-form S8+S9: add a File field, upload a pdf, verify asset + download link, reject a .exe
/craft-smoke-test plugin:simple-form S10: assign fields to 2 steps, verify step nav + single submission
/craft-smoke-test plugin:simple-form S11: export submissions CSV and verify headers/rows
/craft-smoke-test plugin:simple-form S12: add the count + recent dashboard widgets and verify against the DB
```

## Coverage notes / known limits
- **External-dependent** (need real endpoints/keys, not in CP smoke): live Slack/Discord/Mailchimp/ActiveCampaign/HubSpot/Pipedrive dispatch, real Turnstile/hCaptcha/reCAPTCHA verification, real Akismet verdicts. These are covered by the unit/integration suites with mocked transports — smoke only confirms the CP config surface (S5/S6/S7).
- **File asset persistence** needs a real asset volume (dev has `uploads`); the integration test for this skips in CI where no volume exists, so S9 is the primary real-asset check.
