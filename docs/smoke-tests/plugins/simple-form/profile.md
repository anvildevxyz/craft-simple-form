# Simple Form Plugin Profile
Generated: 2026-06-18 | Plugin schemaVersion: 2.4.0

## Elements
- **Form** — table: `simpleform_forms` (+ `simpleform_forms_sites` per-site), statuses: none
  - Key fields: name, handle, title, description, emailTo, emailSubject, emailReplyTo, emailBody, propagationMethod
  - CP route: /admin/simple-form/forms/edit/{id}
  - Localized: yes (per-site content + propagation)
- **Submission** — table: `simpleform_submissions`, statuses (readStatus enum): [new, read, archived, **spam**]
  - Key fields: formId, siteId, data (JSON: `field_<id> => {label,type,value}`), userId, readStatus
  - CP route: /admin/simple-form/submissions/{id}

## CP Navigation
- Simple Form → Forms (index + new + per-form **Integrations**)
- Simple Form → Submissions (index + view + **Export CSV**)
- Simple Form → Settings (General / Email / Spam Protection / MCP Server)

## Field Types (11, via FieldTypeRegistry)
text, email, textarea, select, checkbox, radio, date, number, **phone** (#123),
**file** (asset upload), **payment**.
Per-field config can include: `required`, `placeholder`, length/min/max, `options`,
`conditional` (#68), **`page`** (multi-step), file config (`volume`,
`allowedExtensions`, `maxSize`, `multiple`), and phone config (`showCountrySelector`,
`defaultCountry`, `allowedCountries`, `pattern`, `minDigits`, `maxDigits`).
Phone stores a normalized `{raw, e164, country}` map (no new table).

## Controllers & Actions
- `simple-form/forms/index|edit|save|delete` — forms CRUD (MANAGE_FORMS)
- `simple-form/fields/add|edit|delete|reorder` — AJAX field builder
- `simple-form/integrations/index|edit|save|delete|toggle|resend` — per-form integrations (MANAGE_INTEGRATIONS)
- `simple-form/submissions/index|view|toggle-status|export` — submissions (VIEW_SUBMISSIONS; export streams CSV)
- `simple-form/settings/index|section|save|create-mcp-token|revoke-mcp-token` — settings (MANAGE_SETTINGS)
- `simple-form/submit/index` — POST (site) frontend submission
- `simple-form/mcp/index` — POST token-authed MCP transport

## CP Routes (new since v1)
- `/admin/simple-form/forms/{id}/integrations` (+ `/new`, `/{integrationId}`)
- `/admin/simple-form/submissions/export`
- `/admin/simple-form/settings/{tab}` (general|email|spam|mcp)

## DB Tables
- `simpleform_forms` / `simpleform_forms_sites`
- `simpleform_fields` / `simpleform_fields_sites` — config JSON holds page/conditional/file/etc.
- `simpleform_submissions` — readStatus enum incl. `spam`
- `simpleform_integrations` — id, formId(FK cascade), type, name, enabled, settings(JSON), sortOrder
- `simpleform_integration_logs` — id, integrationId(FK cascade), submissionId(FK set-null), status(pending|success|failed), attempts, responseCode, message

## Services & Key Operations
- **FieldTypeRegistry**: getFieldType(), typeHandles(), getAllFieldTypes()
- **SubmissionService**: createFromRequest() (handles file uploads + spam + integrations), submit(), updateStatus()
- **IntegrationsService**: getIntegrationsForForm(), saveIntegration(), validateSettings(), dispatchForSubmission(), runOnce(), getDispatchHealth(), logDispatch()
- **IntegrationTypeRegistry**: getType(), getAllTypes() — 7 connectors: webhook, slack, discord, mailchimp, activecampaign, hubspot, pipedrive
- **CaptchaService** (facade) + **CaptchaProviderRegistry**: recaptcha (default), turnstile, hcaptcha
- **AkismetService**: isSpam() (fails open)
- **AssetUploadService**: saveUploads(), deleteAssets()
- **EmailService**: sendSubmissionEmail() (links uploaded files)

## Widgets (Dashboard::EVENT_REGISTER_WIDGET_TYPES)
- **SubmissionCountWidget** — range (today/7d/30d/all) + optional form filter
- **RecentSubmissionsWidget** — latest N, linked

## Permissions (SimpleFormPermissions)
- `manageForms` → nested `manageIntegrations`
- `viewSubmissions` → nested `manageSubmissions`
- `manageSettings`

## Settings (Spam tab relevant to smoke tests)
- enableHoneypot, enableCaptcha, **selectedCaptchaProvider** (recaptcha|turnstile|hcaptcha)
- reCAPTCHA: captchaType (v2/v3) + site/secret keys + v3 min score
- Turnstile: turnstileSiteKey/turnstileSecretKey
- hCaptcha: hcaptchaSiteKey/hcaptchaSecretKey
- Akismet: enableAkismet, akismetApiKey, akismetMode (flag|block)

## Twig / Frontend
- `{{ simpleForm('handle') }}` renders the form (multipart when a file field exists; multi-step containers when fields span pages)
- Frontend JS: `src/web/assets/form/dist/js/simple-form.js` — conditional logic + multi-step nav, fetch+FormData submit

## Test environment notes
- CP creds + German UI: see memory `reference_cp_credentials` / `reference_booked_test_runner`.
- A form to reuse exists (id 9095 used during dev). Asset volumes in dev: `uploads`, `userGuide`.
- Checkbox gotcha: Craft renders a hidden input + checkbox under one name — Playwright must click the **label**, not the input ([[reference_craft_checkboxfield_double_input]]).
- Headless full-page-form POST may not redirect in Playwright (CP quirk) — verify persistence via DB, not just the redirect.
