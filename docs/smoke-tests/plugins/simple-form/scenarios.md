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

### S5 — All 7 connector types render their settings ⬜
For each `type` in [webhook, slack, discord, mailchimp, activecampaign, hubspot, pipedrive]:
1. EXECUTE (UI): `/admin/simple-form/forms/{id}/integrations/new` → type picker lists the type.
2. EXECUTE (UI): choose it → settings form renders the expected fields:
   - webhook: URL, HTTP method, payload format, signing secret
   - slack: Slack webhook URL, channel, username, message template
   - discord: Discord webhook URL, username, message template
   - mailchimp: API key, Audience ID, email field, double opt-in
   - activecampaign: API URL, API key, list ID, email field
   - hubspot: private-app token, object type (contact/deal), email field
   - pipedrive: API domain, API token, name field, email field
3. VERIFY (UI): required-field markers present; saving with the required field blank shows a validation error (no row written).
4. VERIFY (DB): a valid save persists `type` correctly in `simpleform_integrations`.

> Live dispatch for slack/mailchimp/etc. needs real endpoints/keys — out of scope for
> CP smoke. Dispatch mechanics are covered generically by S3 (webhook) + the unit/
> integration suites (mocked transports).

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

## I. Agree / Consent field (GDPR) (#125)

### S13 — Required consent blocks submit + records the agreement ⬜
1. SETUP: edit the S0 form → from the palette add **Agree / Consent**. In the inspector confirm **Required** is on by default; set **Consent Text** to `I agree to the [privacy policy](https://example.com/privacy)` (or click **Add link**) → Save. Capture the consent field id.
2. VERIFY (UI render): `{{ simpleForm('smokeForm') }}` on a test page → the consent group renders a single `<input type="checkbox" id="field_{cid}" name="field_{cid}" value="1" required>` followed by a `<label for="field_{cid}">` whose text contains `<a href="https://example.com/privacy" target="_blank" rel="noopener noreferrer">privacy policy</a>`. There is exactly **one** label for the box (no duplicate group label).
3. EXECUTE (UI, negative): fill the other required fields, leave the consent box **unticked**, submit.
4. VERIFY (UI): the form re-renders with the consent error (default `You must agree before submitting.`, or the German `Sie müssen zustimmen, bevor Sie absenden.`).
5. VERIFY (DB): `SELECT COUNT(*) FROM simpleform_submissions WHERE formId={id};` is unchanged — **no** row was created.
6. EXECUTE (UI, positive): submit again with the box **ticked**.
7. VERIFY (DB): a new row exists; `SELECT data FROM simpleform_submissions WHERE id={sid};` → `field_{cid}.value` is a JSON object `{consented:true, consentedAt:"…", textVersion:"I agree to the privacy policy (https://example.com/privacy)", textHash:"sha256:…"}`. `consentedAt` is a server time within seconds of now (not client-supplied).
8. VERIFY (UI detail): `/admin/simple-form/submissions/{sid}` shows the consent row as **Consented: Yes — {date} — Text: I agree to the privacy policy (…)** (not a bare `1`).
9. VERIFY (Logs): no errors.

### S14 — Per-site translated consent label + localized snapshot ⬜
1. SETUP: switch the builder to a second (e.g. French) site and translate the consent field's text (or confirm the per-site label), then publish.
2. EXECUTE (UI): render the form on the second site → VERIFY the consent label shows the localized text and the link still renders safely.
3. EXECUTE (UI): submit with the box ticked on the second site.
4. VERIFY (DB): the stored `field_{cid}.value.textVersion` reflects the **localized** text shown for that site.

### S15 — Export shows a human-readable consent column ⬜
1. SETUP: at least one ticked-consent submission from S13.
2. EXECUTE (UI): submissions index → filter to the S0 form → **Export CSV**.
3. VERIFY (content): the consent column header is the field label (e.g. `Consent`) and its cell reads `Yes (YYYY-MM-DD HH:MM)` (or `No`), **not** the raw JSON record.

### S16 — Conditionally-required consent (XSS-safe) ⬜
1. SETUP: edit the S0 form → on the consent field open **Conditions** → require it only when another field matches (e.g. a "Subscribe" checkbox is ticked) → Save.
2. EXECUTE (UI): submit with the trigger **unmet** and consent **unticked** → VERIFY the submission succeeds (consent not required) and no consent record marks `consented:true`.
3. EXECUTE (UI): submit with the trigger **met** and consent **unticked** → VERIFY the server blocks it with the consent error (the conditional-required rule is enforced server-side).
4. VERIFY (XSS): set the consent text to `Click [x](javascript:alert(1)) and <script>alert(2)</script>` → render → VERIFY no `<a>` with a `javascript:` href and no live `<script>` (the script text is HTML-escaped). The box is still required.

---

## Runner index (execute individually later)
```
/craft-smoke-test plugin:simple-form S1: add a Webhook integration to a form and verify the row + DB
/craft-smoke-test plugin:simple-form S3: submit a form, run the queue, verify an integration_logs row + Resend
/craft-smoke-test plugin:simple-form S5: open New Integration and verify each of the 7 connector settings forms render
/craft-smoke-test plugin:simple-form S6: Settings Spam — switch captcha provider to Turnstile/hCaptcha and verify key fields toggle + persist
/craft-smoke-test plugin:simple-form S7: enable Akismet, mark a submission spam, verify the Spam filter on the index
/craft-smoke-test plugin:simple-form S8+S9: add a File field, upload a pdf, verify asset + download link, reject a .exe
/craft-smoke-test plugin:simple-form S10: assign fields to 2 steps, verify step nav + single submission
/craft-smoke-test plugin:simple-form S11: export submissions CSV and verify headers/rows
/craft-smoke-test plugin:simple-form S12: add the count + recent dashboard widgets and verify against the DB
/craft-smoke-test plugin:simple-form S13: add a required Consent field, block an unticked submit, then verify the stored consent record + CP detail
/craft-smoke-test plugin:simple-form S14: translate the consent label on a second site and verify the stored textVersion is localized
/craft-smoke-test plugin:simple-form S15: export submissions and verify the consent column reads Yes (date) / No
/craft-smoke-test plugin:simple-form S16: make consent conditionally required and verify server-side enforcement + XSS-safe rich label
```

## Coverage notes / known limits
- **External-dependent** (need real endpoints/keys, not in CP smoke): live Slack/Discord/Mailchimp/ActiveCampaign/HubSpot/Pipedrive dispatch, real Turnstile/hCaptcha/reCAPTCHA verification, real Akismet verdicts. These are covered by the unit/integration suites with mocked transports — smoke only confirms the CP config surface (S5/S6/S7).
- **File asset persistence** needs a real asset volume (dev has `uploads`); the integration test for this skips in CI where no volume exists, so S9 is the primary real-asset check.
