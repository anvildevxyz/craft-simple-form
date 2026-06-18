# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Submissions element index: bulk actions (mark as read / archive / mark as spam,
  plus delete) and a native **Submissions (with field columns)** exporter that
  appears in the index export menu (metadata + one column per field, CSV/JSON/XML).
- **Form field type**: embed a form in any element's field layout (entries, users,
  categories, …). Lock it to one form in the field settings, or let authors pick a
  form per entry. The value normalizes to the Form element — `entry.myForm.handle`
  works and it renders with `{{ simpleForm(entry.myForm.handle) }}`.
- Console commands (`php craft simple-form/*`): `submissions/purge` (delete or
  anonymize old submissions, optional `--form`), `submissions/export` (CSV to
  stdout or `--out`), `integrations/redispatch` (re-queue dispatch for a
  submission), `cache/warm` + `cache/clear` (form-structure cache), and `doctor`
  (config + data health check).
- Data retention (Settings → Privacy): submissions and integration dispatch logs
  can be auto-pruned past a configurable age on Craft's garbage-collection run
  (0 = keep forever). Submissions can be hard-deleted or anonymized in place
  (scrubbing the submitted data + user reference while keeping the row for stats).
- Integrations are now defined centrally under **Settings → Integrations**
  (create / edit / delete / enable) and enabled per form from each form's
  Integrations screen. One integration definition can be reused across many
  forms. Primary actions (**New Form**, **New Integration**) now render as
  Control Panel header action buttons.
- Dashboard widgets: a **Form Submissions** count widget (selectable range —
  today / 7 days / 30 days / all — and optional per-form filter) and a **Recent
  Submissions** widget linking to each submission. Both respect the current site
  and the view-submissions permission.
- Submissions CSV export in the Control Panel: an **Export CSV** button on the
  submissions index downloads the currently-filtered submissions (form, status,
  search, date range), with metadata columns plus one column per field label.
- Multi-step / multi-page forms: assign each field a step via its “Step / Page”
  number in the builder; the rendered form shows one step at a time with
  next/back navigation, a progress indicator, and per-step client validation. The
  final submit still creates a single submission, and conditionally-hidden fields
  are skipped during step validation. Single-page forms are unchanged. The step is
  exposed on `SimpleFormField.page` (GraphQL) and in the MCP field config.
- File-upload field type: visitors can attach files, saved as Craft Assets in a
  configurable volume, with server-enforced extension allowlist, max size, and
  single/multiple limits. Uploaded files are linked in the notification email and
  downloadable from the submission detail screen; orphaned assets are rolled back
  if a submission ultimately fails.
- Akismet content spam scoring: each submission is checked against Akismet
  (when enabled) and either **flagged** (saved with a new `spam` status, visible
  and filterable in the CP) or **blocked** (silently dropped), per setting. Fails
  open — a missing key or Akismet outage never rejects a legitimate submission.
- hCaptcha captcha provider, selectable as an alternative to reCAPTCHA
  (site/secret keys are env-aware).
- Cloudflare Turnstile captcha provider, selectable as an alternative to reCAPTCHA
  (site/secret keys are env-aware).
- Pluggable captcha provider architecture: a `CaptchaProviderInterface` + registry
  behind a `selectedCaptchaProvider` setting, so alternative captchas can slot in
  without touching the submit path or form renderer. The existing Google reCAPTCHA
  (v2/v3) is now a `RecaptchaProvider` and remains the default — existing installs
  behave identically. Register custom providers via
  `Plugin::EVENT_REGISTER_CAPTCHA_PROVIDERS`.
- CRM integration connectors: **HubSpot** (create a contact or deal via the v3
  API with a private-app token) and **Pipedrive** (create a person), mapping form
  fields to CRM properties. Built on the integrations framework.
- Email-marketing integration connectors: **Mailchimp** (upsert a member into an
  audience, with double opt-in and merge-field mapping) and **ActiveCampaign**
  (sync a contact and optionally add to a list). API-key authenticated; built on
  the integrations framework.
- Slack and Discord integration connectors: post each submission to an incoming
  webhook as a message — auto field list or a `{handle}` placeholder template,
  with optional channel/username overrides. Built on the integrations framework.
- Outbound integrations framework: push submissions to external services via a
  pluggable connector architecture, with a built-in **Webhook** connector
  (JSON/form-encoded, optional HMAC-SHA256 signing, field mapping). Dispatch runs
  asynchronously on the queue with retries, per-attempt logging, a CP management
  screen per form, a submission-detail dispatch panel with **Resend**, and a new
  `manageIntegrations` permission. Read-only exposure via GraphQL
  (`SimpleForm.integrations`) and the MCP `list_integrations` tool — never
  exposing settings/secrets. Register custom connectors via
  `Plugin::EVENT_REGISTER_INTEGRATION_TYPES`. See [docs/integrations.md](docs/integrations.md).
- Conditional logic: show/hide fields and make them conditionally required based
  on other fields' values, with live client-side evaluation and authoritative
  server-side enforcement (hidden fields are not validated or stored). Exposed
  via the field builder, GraphQL (`SimpleFormField.conditional`), and the MCP
  `add_field`/`update_field` tools. See [docs/conditional-logic.md](docs/conditional-logic.md).
- Initial plugin scaffold with Form and Submission element types
- CP navigation menu for Forms and Submissions
- Database migrations for forms, fields, and submissions tables
