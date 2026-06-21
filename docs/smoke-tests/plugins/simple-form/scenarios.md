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

## I. Phone field type (#123)

**Not yet executed** (authored 2026-06-20 with the feature; DDEV/Playwright run pending).
Covered by unit (`PhoneFieldTypeTest`, `DialCodesTest`) + integration
(`PhoneSubmissionTest`, GraphQl phone mutations); these scenarios add the CP-builder
and front-end UX checks.

### S13 — Build a form with a Phone field (country selector) ⬜
1. SETUP: `/admin/simple-form/forms/new` → name “Phone Smoke”, handle `phoneSmoke`,
   emailTo `ops@example.test` → Save. Capture the form id.
2. EXECUTE (UI): in the builder, click **Phone** in the field-type palette (right column).
3. EXECUTE (UI): select the new field → in the inspector set Label “Mobile”, tick
   **Required field** and **Show Country Selector**, set Default Country = “Switzerland (+41)”,
   tick Allowed Countries = Switzerland + Germany → Save the form.
4. VERIFY (DB): the field persisted with the right type + config —
   `SELECT type, config FROM simpleform_fields WHERE formId={id} AND name='mobile';`
   → `phone` and a JSON config containing `"showCountrySelector":true`,
   `"defaultCountry":"CH"`, `"allowedCountries":["CH","DE"]`.
5. VERIFY (Logs): no new errors in `web-$(date +%F).log`.

### S14 — Front-end render + valid submit normalizes to E.164 ⬜
1. SETUP: S13 form exists; render it on a front-end template
   (`{{ simpleForm('phoneSmoke') }}`) or use the dev render route.
2. VERIFY (UI): the field renders a `<select name="field_{fid}[country]">` limited to
   **Switzerland (+41)** and **Germany (+49)** (no other countries), followed by an
   `<input type="tel" name="field_{fid}[number]">`.
3. EXECUTE (UI): pick Switzerland, type `079 123 45 67`, submit (solve captcha if on).
4. VERIFY (UI): success state (no field error, thank-you/redirect).
5. VERIFY (DB): the stored value is the normalized map —
   `SELECT data FROM simpleform_submissions WHERE formId={id} ORDER BY id DESC LIMIT 1;`
   → `field_{fid}.value.e164` = `+41791234567`, `.country` = `CH`, `.raw` = `079 123 45 67`.

### S15 — Invalid number re-renders with the translated error, no row ⬜
1. SETUP: S13 form; capture `SELECT COUNT(*) FROM simpleform_submissions WHERE formId={id};`.
2. EXECUTE (UI): submit with the number `abc` (or `12`).
3. VERIFY (UI): the form re-renders showing **“Enter a valid phone number.”**
   (localized per the active site language).
4. VERIFY (DB): the submission count is unchanged — no row was written.

### S16 — Selector hidden → flat string normalizes against defaultCountry ⬜
1. SETUP: edit the Phone field, untick **Show Country Selector**, set Default Country
   = “Germany (+49)” → Save.
2. VERIFY (UI): only the `<input type="tel" name="field_{fid}">` renders (no `<select>`).
3. EXECUTE (UI): submit `030 1234567`.
4. VERIFY (DB): `field_{fid}.value.e164` = `+49301234567`, `.country` = `DE`.

### S17 — CSV export shows the normalized number ⬜
1. SETUP: S14 produced at least one phone submission.
2. EXECUTE (UI): submissions index → **Export** (CSV).
3. VERIFY (file): the “Mobile” column cell is `+41791234567` (the clean E.164 string,
   not the `{raw|e164|CH}` map).

---

## J. Hidden field — dynamic default capture (#124)

### S18 — Query-sourced Hidden field captures a URL param ⬜
1. SETUP: S0 form exists (id captured).
2. EXECUTE (UI): edit the S0 form → from the **Field Types** palette add a **Hidden** field → in the inspector set **Label** = “UTM Source”, **Handle** = `utmSource`, **Source** = “URL query parameter”, **Query Parameter** = `utm_source` → Save.
3. VERIFY (UI): the field card shows the **Hidden** type pill (titled “Hidden — captured silently”); the inspector shows **no** Required / Help Text / Error Message rows.
4. VERIFY (DB): `SELECT type,config FROM simpleform_fields WHERE formId={id} AND name='utmSource';` → type `hidden`, config JSON has `"source":"query","queryParam":"utm_source"`.
5. EXECUTE (UI): visit the rendered form at `…?utm_source=spring-sale`. VERIFY (DOM): the form contains `<input type="hidden" name="field_{hid}" value="spring-sale">` and **no** visible label/group for it.
6. EXECUTE (UI): fill the visible Name/Email fields → Submit.
7. VERIFY (DB): `SELECT data FROM simpleform_submissions WHERE formId={id} ORDER BY id DESC LIMIT 1;` → `data.field_{hid}` is `{ "label":"UTM Source", "type":"hidden", "value":"spring-sale" }`.
8. VERIFY (UI): the CP submission detail lists **UTM Source = spring-sale**.
9. VERIFY (export): Export CSV → a **UTM Source** column holds `spring-sale` for that row.

### S19 — User-sourced Hidden field ignores a spoofed value ⬜
1. SETUP: be logged in to the front end as a known user (note their email); S0 form has a **Hidden** field “Member Email”, Source = “Logged-in user”, User Attribute = “Email”.
2. EXECUTE (DevTools): on the rendered form, edit the hidden input’s value to `attacker@evil.test` before submitting → Submit.
3. VERIFY (DB): the stored `data.field_{hid}.value` is the **real** logged-in user’s email, **not** `attacker@evil.test`.
4. VERIFY (Logs): no errors.

### S20 — Hidden field renders with no visible label ⬜
1. EXECUTE (UI): render any form carrying a Hidden field.
2. VERIFY (DOM): there is exactly one `<input type="hidden" name="field_{hid}">` and **no** `<label for="field_{hid}">`, no `.simple-form-group` wrapper, no help text for it.

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
/craft-smoke-test plugin:simple-form S13: build a form with a Phone field + country selector and verify the persisted config
/craft-smoke-test plugin:simple-form S14: submit a Swiss national number and verify the stored value.e164 is +41791234567
/craft-smoke-test plugin:simple-form S15: submit an invalid phone number and verify the translated error + no row written
/craft-smoke-test plugin:simple-form S16: hide the selector and verify a flat string normalizes against defaultCountry
/craft-smoke-test plugin:simple-form S17: export submissions CSV and verify the phone column shows the normalized E.164 number
/craft-smoke-test plugin:simple-form S18: add a query-sourced Hidden field, submit with ?utm_source=spring-sale, verify capture in DB + detail + CSV
/craft-smoke-test plugin:simple-form S19: user-sourced Hidden field, spoof the hidden input, verify the stored value is the real logged-in email
/craft-smoke-test plugin:simple-form S20: verify a Hidden field renders with no visible label/wrapper
```

## Coverage notes / known limits
- **External-dependent** (need real endpoints/keys, not in CP smoke): live Slack/Discord/Mailchimp/ActiveCampaign/HubSpot/Pipedrive dispatch, real Turnstile/hCaptcha/reCAPTCHA verification, real Akismet verdicts. These are covered by the unit/integration suites with mocked transports — smoke only confirms the CP config surface (S5/S6/S7).
- **File asset persistence** needs a real asset volume (dev has `uploads`); the integration test for this skips in CI where no volume exists, so S9 is the primary real-asset check.
