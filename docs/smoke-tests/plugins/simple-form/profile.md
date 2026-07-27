# Simple Form Plugin Profile
Generated: 2026-06-23 | Plugin schemaVersion: 2.13.0
(Refreshed for the developer-experience work #218–#226; earlier sections still apply.)

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

## Developer Experience & Extensibility (#218–#226)

### Extension events (all on `Plugin::class`)
- Register events: `EVENT_REGISTER_FIELD_TYPES` (→ `RegisterFieldTypesEvent::$types`, classes extend `fields\FieldType`), `EVENT_REGISTER_INTEGRATION_TYPES`, `EVENT_REGISTER_CAPTCHA_PROVIDERS`, `EVENT_REGISTER_STENCILS`.
- Lifecycle seams: `EVENT_BEFORE_VALIDATE`, `EVENT_DEFINE_FIELD_SET`, `EVENT_MODIFY_RENDER_CONTEXT`, `EVENT_BEFORE_SEND_NOTIFICATION` (cancel via `$e->send=false`), `EVENT_BEFORE_INTEGRATION_DISPATCH` (cancel), plus the existing `EVENT_BEFORE/AFTER_SUBMISSION_SAVE`. Each only fires when a handler is attached.
- Extension interfaces: `integrations\IntegrationTypeInterface`, `captcha\CaptchaProviderInterface`, `fields\FieldType` (base), `stencils\Stencil`.

### Console commands (added)
- `simple-form/forms/apply [--dry-run] [--prune]` — create/id-stably-update forms from `config/simple-form/forms/*.json`.
- `simple-form/forms/status` — `[config]` vs `[db]` inventory + unapplied files.
- `simple-form/forms/export --form=<h> --out=<path>` — creates the target dir if missing; v2 doc carries **all** form settings.
- `simple-form/make/field-type|integration|theme [Class] [--namespace=] [--path=]` — scaffolding generators.

### Forms as code
- File: `config/simple-form/forms/<handle>.json` (the portable, secret-free export doc; **not** Craft project config). v2 schema = full form settings; v1 files still import.
- `applyFormsConfigOnUp` setting (default false): `craft up` runs `forms/apply` on finish (never prunes).
- Apply is id-stable (form + fields matched by handle keep ids → submissions survive); `--prune` never drops a field that holds submission data.

### Front-end JS hook API
- `simple-form.js` dispatches CustomEvents on the `<form>`: `simpleform:beforeSubmit` (cancelable), `:afterSubmit`, `:validationFailed`, `:stepChange`.

### IDE / headless
- `docs/reference/schema.graphql` (committed SDL) · root `.phpstorm.meta.php`.

### DX dogfood harness (in the craft-plugin-dev project, not the plugin repo)
- Module `modules/sfdx/` (bootstrapped in `config/app.php`) registers: field type `color` (`ColorField`), integration `sfdxLog` (`LogIntegration`, writes `storage/sfdx-integration.txt`), captcha `sfdxNull` (`DxBypassCaptchaProvider` — bypasses captcha for the `dxSmoke` form only, delegates to turnstile otherwise). Listeners write sentinels: `storage/sfdx-context.txt` (modify-render-context), `storage/sfdx-aftersave.txt` (after-save).
- Form `dxSmoke` (config-defined) · theme `templates/_sfdx-theme/` · route `smoke/sfdx` · `config/simple-form.php` selects the scoped captcha + sync dispatch.
- Runner scripts: `scripts/sfdx-check.php` (registry assertions), `scripts/sfdx-submit.sh` (CSRF render+submit), `scripts/sfdx-attach.php` (attach integration), `scripts/sfdx-other-form-check.sh` (captcha-scope proof).

## Test environment notes
- CP creds + German UI: see memory `reference_cp_credentials` / `reference_booked_test_runner`.
- A form to reuse exists (id 9095 used during dev). Asset volumes in dev: `uploads`, `userGuide`.
- Checkbox gotcha: Craft renders a hidden input + checkbox under one name — Playwright must click the **label**, not the input ([[reference_craft_checkboxfield_double_input]]).
- Headless full-page-form POST may not redirect in Playwright (CP quirk) — verify persistence via DB, not just the redirect.
