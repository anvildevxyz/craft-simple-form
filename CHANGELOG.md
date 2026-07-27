# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 - 2026-07-27

First public release. Simple Form is a lightweight, fully translatable form
builder for Craft CMS 5: build forms in the control panel, render them with one
Twig tag, and manage submissions as native Craft elements.

### Added

#### Editions

- Two editions, **free Solo** and **paid Standard**. Solo is the "better contact form":
  unlimited forms, stored submissions, 22 of the 33 field types, email
  notifications, honeypot/rate-limit/CAPTCHA spam protection, the webhook and
  Craft entry/user integrations, attribution capture, address autocomplete,
  submission analytics, logic jumps, the approval workflow, and full multi-site
  translation. Standard adds the 11 advanced field types (signature, payment,
  rating, opinion scale, calculation, repeater, and the five element
  relations), conditional logic, multi-page and conversational forms, save &
  continue later, quiz scoring, partial capture, the third-party integrations,
  Commerce payments, Akismet and denylists, PDF attachments, the audit log,
  automated retention, and the MCP server plus forms-as-code tooling.
- The edition gate governs **authoring, never the visitor-facing runtime**: a
  form built on Standard keeps rendering and accepting submissions after a downgrade
  to Solo. Saving preserves its Standard features but cannot extend them, and the
  editor shows a non-blocking "Standard features in use" banner. Spam protection,
  denylists, and retention keep running after a downgrade; the Standard-only
  back-office services (conditional submit-message resolution, PDF attachments,
  audit logging) pause gracefully and resume on returning to Standard.

#### Form building

- A drag-and-drop form builder with multi-column row layouts, keyboard and touch
  reordering (per-card move buttons and Alt/Ctrl+Arrow, with live-region
  announcements), a palette grouped into Basic/Choice/Advanced/Layout with a
  search filter, and live duplicate-handle warnings.
- **Multi-step / multi-page forms**: assign each field a step, rendered one step
  at a time with next/back navigation, a progress indicator, per-step client
  validation, and "Step N" separators in the builder canvas.
- **Conversational render mode** — one question per screen, with a built-in
  centered-card theme and progress bar.
- **Save & continue later**: resumable drafts behind a tokenized link.
- **Passive partial capture**: debounced auto-save of in-progress forms behind a
  consent gate, with a CP "abandoned" view, its own retention window, and an
  `EVENT_PARTIAL_CAPTURED` developer event.
- **Stencils** to start a form from a preset, plus a duplicate-form action.
- **Embed & share modes**: a shareable standalone form URL and an embeddable
  variant.
- Per-form post-submit behaviour: override the success/error message and
  redirect to a URL or entry, with submitted-value templating.
- Opt-in **query-string prefill** and authored **field default values**.

#### Field types

- **33 field types**: text, email, URL, textarea, number, phone (country code +
  validation), date, time, date & time, hidden (dynamic defaults), select,
  checkbox, radio, rating, opinion scale / NPS, agree/consent (GDPR), name and
  address (composite, with optional autocomplete via Photon, Nominatim, or
  Google Places), file upload, signature, calculation (formula engine),
  repeater, payment, entry/category/tag/user/asset relations, and the
  heading/divider/HTML/text/callout layout blocks.
- File uploads are saved as Craft Assets in a configurable volume (with a
  plugin-wide default), with a server-enforced extension allowlist, size and
  count ceilings, and rollback of orphaned assets when a submission fails.

#### Conditional logic

- Show/hide fields and make them conditionally required based on other fields'
  values, with live client-side evaluation and authoritative server-side
  enforcement — hidden fields are neither validated nor stored.
- **Logic jumps** branch a multi-page form to a non-adjacent step; jump targets
  are validated at save, and skipped steps' controls are disabled so they can't
  block submission.
- **Conditional submit messages**: per-form ordered rules that swap the
  post-submit message based on submitted values, with per-site translations and
  a save-time guard rail for rules referencing removed fields.
- Deleting a field asks for confirmation and names the fields whose rules depend
  on it; saves that prune dependent rules say so in the notice.

#### Spam protection

- Honeypot, per-IP submit rate limiting (on by default at 10/minute), and
  pluggable CAPTCHA providers — Google reCAPTCHA v2/v3, hCaptcha, and Cloudflare
  Turnstile — registrable via `EVENT_REGISTER_CAPTCHA_PROVIDERS`.
- **Akismet** content scoring, either flagging (saved with a `spam` status) or
  blocking. Fails open: a missing key or outage never rejects a legitimate
  submission.
- Keyword/email/IP **denylists**, per-form duplicate prevention with its own
  block/flag mode, and a spam **review queue** — approving a false positive
  re-fires its withheld notification and integration dispatch.

#### Availability & limits

- Per-form open/close windows, a total submission quota, login-required, and
  per-user (and per-guest-email) submission limits — all enforced server-side
  across the AJAX, no-JS, and GraphQL paths.

#### Notifications

- Any number of per-form notifications, each with its own recipient, subject,
  reply-to, CC/BCC, and body. A recipient can be a fixed address or a form
  field, enabling autoresponders.
- Send conditions reuse the conditional-logic engine. Bodies are translatable
  per site, carry a documented variable reference, send a plain-text alternative
  part, and can be trialled with a **Send test** button.
- Optional **PDF attachments** of the submission (via the optional dompdf
  dependency) and attachment of the submitter's uploaded files.
- A CP **notification log**: per-send rows with status, error messages, filters,
  stat cards, and a per-row **Resend** that cross-references the original send.

#### Integrations

- An outbound integrations framework with a pluggable connector architecture.
  Dispatch runs asynchronously on the queue with retries, per-attempt logging, a
  CP management screen, a submission-detail dispatch panel with Resend, and a
  `manageIntegrations` permission.
- Built-in connectors: **Webhook** (JSON or form-encoded, optional HMAC-SHA256
  signing over a timestamped payload for replay protection), **Slack**,
  **Discord**, **Mailchimp**, **ActiveCampaign**, **HubSpot**, **Pipedrive**,
  **Google Sheets**, and **Create Craft Element** (build an Entry or User from a
  submission).
- Integrations are defined centrally under Settings → Integrations and enabled
  per form, so one definition is reusable across many forms. Register custom
  connectors via `EVENT_REGISTER_INTEGRATION_TYPES`.

#### Payments

- Collect a fixed or field-driven amount on submit through Craft Commerce's
  configured gateway, using its embedded form — pay-to-submit, so a decline
  saves nothing. Notifications and integrations are withheld until the payment
  settles and released automatically once it does; abandoned offsite checkouts
  expire to a `canceled` status.
- **Coupons / discount codes**: percent or fixed discounts with usage limits and
  windows, a CP management screen, a live discount preview, and
  case-insensitive code uniqueness on both MySQL and Postgres.
- Commerce is an optional soft dependency — without it the Payment field is
  inert.

#### Submissions

- Submissions are native Craft elements on a native element index:
  All/status/per-form sources, a Trashed source with restore, bulk
  set-status/delete, and deep links from the forms listing.
- A CP **Dashboard** landing page with submission activity over a real time
  axis, a by-weekday breakdown, a "needs attention" list, and per-form quick
  links; plus a per-form **Stats** tab and front-end **Preview**.
- **Analytics**: submissions over time with a 7/30/90-day selector, status
  breakdown, spam-vs-legitimate split, per-form totals, and dispatch health.
- **Quiz mode** with per-option scores and grade bands, and per-form **survey
  reporting** with per-question aggregates.
- A configurable **approval workflow**: statuses, transitions, CP transition
  buttons, and an `EVENT_SUBMISSION_TRANSITIONED` hook.
- Immutable **per-submission field snapshots**, so renaming, reordering, or
  deleting a field later never corrupts the labels or order shown for existing
  submissions.
- **CSV export** with optional column selection (plus Craft's native
  JSON/XML exporters and a field-column exporter), formula-injection
  neutralization on every cell, and streaming in bounded batches for large
  exports.
- **Editing submissions**: from the CP with *Manage submissions*, or by the
  submitter through a secure tokenized link (`craft.simpleForm.editForm()`) with
  a per-form toggle and edit window, and a GraphQL `updateSubmission` mutation.
- Soft delete (trash) and restore, dashboard widgets (submission count and
  recent submissions), and an append-only **audit log** of form, integration,
  notification, and submission-status changes.
- **UTM/referrer attribution capture**, stored with the submission and shown on
  the CP detail.

#### Privacy & data retention

- IP capture is a three-state policy — **full, anonymized** (last IPv4 octet or
  low 80 bits of an IPv6 address masked before storage), **or off**. Rate
  limiting works under any mode; IP-based duplicate detection degrades to the
  other dedupe keys.
- Configurable retention windows for submissions, flagged spam (30 days by
  default), integration logs, notification logs, the audit log, drafts, and
  partials — pruned on Craft's garbage-collection run, with hard delete or
  anonymize-in-place.
- Data-subject console commands: `submissions/export-by-email` and
  `submissions/erase-by-email` (delete or anonymize, with `--dry-run`).
- Denormalized privacy fingerprints are HMAC-keyed with the site security key,
  not bare digests. Note that they do not survive a `securityKey` rotation.

#### Multi-site & translation

- Form titles, descriptions, field labels, options, messages, and notification
  bodies translate per site, with a configurable propagation method.
- The control panel ships English plus machine-translated German, Spanish,
  French, Italian, Japanese, Dutch, and Portuguese — every CP screen and the
  builder's JavaScript UI. A unit test enforces key parity across all catalogs.
  The non-English catalogs are pending native review.

#### Developer API

- `craft.simpleForm.*` Twig API — `.form()`, `.forms()`, `.submissions()`,
  `.render()`, `.editForm()`, `.editUrl()` — plus the `simpleForm()` function.
- A **Form field type** to embed a form in any element's field layout, locked to
  one form or author-selectable.
- **Custom render templates**: override how forms and fields render with your
  own Twig partials.
- **GraphQL**: form/field queries, `submitForm` and `updateSubmission`
  mutations, and a committed SDL at `docs/reference/schema.graphql`.
- **Events**: `EVENT_REGISTER_FIELD_TYPES` plus lifecycle seams
  (`EVENT_DEFINE_FIELD_SET`, `EVENT_MODIFY_RENDER_CONTEXT`,
  `EVENT_BEFORE_VALIDATE`, `EVENT_BEFORE_SEND_NOTIFICATION`,
  `EVENT_BEFORE_INTEGRATION_DISPATCH`) for modifying or cancelling behaviour
  without forking.
- A front-end **JavaScript hook API**: `simpleform:beforeSubmit` (cancelable),
  `afterSubmit`, `validationFailed`, and `stepChange` events on the form
  element.
- **No-JS submissions** round-trip as HTML — errors flashed per form and
  rendered through the `errors.twig` theme seam. Programmatic and JSON clients
  are unaffected.
- **Import / export** of a form's full definition as a portable, versioned,
  secret-free JSON document, and **forms as code**: keep definitions in
  `config/simple-form/forms/` and deploy them with `simple-form/forms/apply`,
  which updates existing forms in place and id-stably so submissions and
  conditional references survive. Optionally runs on `craft up`.
- An **MCP server** (off by default, bearer-token authenticated with scoped,
  optionally expiring tokens) exposing 17 tools for form management and
  submission analysis over JSON-RPC 2.0.
- Console commands: `submissions/purge`, `submissions/export`,
  `integrations/redispatch`, `cache/warm`, `cache/clear`, `doctor`, the
  `forms/*` family, and `make/field-type`, `make/integration`, `make/theme`
  scaffolding generators.
- Copy-paste `examples/` (custom field type, integration, captcha provider,
  theme override), a `.phpstorm.meta.php` for service autocomplete, and a
  documented [API stability contract](docs/extending/api-stability.md) defining
  what is public and what a major/minor/patch means.

### Requirements

- Craft CMS 5.x and PHP 8.2 or later. Runs on MySQL 8 and PostgreSQL 16; the
  integration suite runs against both in CI.
- The **Solo edition is free**; **Standard is $39** per project, licensed through the
  Craft Plugin Store — see [LICENSE.md](LICENSE.md) and
  [Editions](docs/editions.md).
