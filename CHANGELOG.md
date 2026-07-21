# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- Closed an SSRF bypass where IPv4-mapped/-compatible and NAT64 IPv6 spellings
  (e.g. `::ffff:169.254.169.254`) slipped past the outbound-request guard and
  could reach cloud metadata or localhost through an integration URL.
- Front-end submission editing now verifies the edit token (or submission
  ownership) **before** rendering the pre-filled form, closing a read-side IDOR
  that could disclose a submission's stored values by id.
- The Google integration token endpoint is now pinned to Google's constant URL
  and no longer honors a `token_uri` from the uploaded service-account JSON,
  which could redirect the signed assertion to an attacker host.
- The `raw` filter is now denied in the notification/HTML-block Twig sandbox, so
  an author template can no longer emit a submitter's markup unescaped.
- Outbound integration requests now validate the host and pin its resolved IP in
  a single lookup, closing a DNS-rebinding window between the SSRF check and
  connect.
- File-upload fields now enforce a hard file-count cap (20 for multi-file
  fields) and a default 25 MB per-file ceiling when no size is configured, and
  reject browser-rendered/executable extensions (`svg`, `xml`, `html`, …)
  regardless of the field's allowlist — backing up the content-type sniff.
- Erasing or anonymizing a submission now also deletes its notification send-log
  rows (recipient + rendered body), so that PII no longer outlives the erasure.
- A front-end edit token is now single-use: it is consumed after a successful
  token-authorized edit, so a leaked edit link can't be replayed for the rest of
  the edit window.
- The Akismet API key (carried in the request host) is now scrubbed from
  transport-error log messages, and integration-secret redaction covers secrets
  from 4 characters up.
- PDF generation explicitly disables in-template PHP and JavaScript execution as
  defense-in-depth for author-overridden `pdf.twig` templates.

## 1.0.0 - 2026-07-12

### Added
- A **spam-retention window** (`retainSpamDays`, default **30**): flagged-spam
  submissions older than the window are pruned on garbage collection,
  independently of `retainSubmissionsDays` (which stays `0` = keep legitimate
  submissions forever), so a flag-mode spam pile can't grow without bound.
  Upgrading installs are pinned to `0` (keep spam forever) so no spam is deleted
  without an explicit choice; fresh installs get the 30-day default. Installs with
  read-only project config (`allowAdminChanges = false`) are not auto-pinned — set
  `retainSpamDays` explicitly if you retain spam for review (#338).
- A **Callout** layout block: a presentational panel with a tone
  (info/success/warning/error), an optional icon, and per-site translatable body
  copy shown between fields. Like the heading/divider/text blocks it stores no
  submission value and is skipped by validation, storage, and export (#322).
- Two commercial editions, **Solo** and **Pro**. Solo is the "better contact
  form": unlimited forms, stored submissions, the 18 core field types, email
  notifications, honeypot/rate-limit/CAPTCHA spam protection, the webhook +
  Craft entry/user integrations, and full multi-site / translatable forms. Pro
  adds the 11 advanced field types (signature, payment, rating, opinion scale,
  calculation, repeater, the element relations), conditional logic, multi-page,
  save & continue later, the third-party integrations (Slack/Discord/CRM/Sheets),
  Commerce payments, Akismet + denylists, PDF attachments, the audit log,
  automated submission retention, and the MCP server / forms-as-code dev tools.
- The edition gate governs **authoring, never the visitor-facing runtime**: a
  form built on Pro keeps rendering and accepting submissions after a downgrade
  to Solo. Saving preserves its Pro features but can't extend them
  (no-new-escalation), and the form editor shows a non-blocking "Pro features in
  use" banner. Spam protection, denylists, and retention keep running after a
  downgrade; the Pro-only back-office services (conditional submit-message
  resolution, PDF attachments, audit logging) pause gracefully and resume on
  returning to Pro.
- A CP **Dashboard** landing page (Simple Form's default screen): submission
  activity with a real time axis and value summary, a by-weekday breakdown
  shared with Analytics, a "needs attention" list (new submissions, failed
  integration dispatches), and per-form quick links (#255).
- Submissions now use a **native element index**: All/status/per-form sources,
  an explicit Trashed source for restore, bulk set-status/delete, CSV plus the
  native JSON/XML exporters, and deep links from the forms listing. Each form
  gains a **Stats** tab and a front-end **Preview** button (#255).
- **Conditional submit messages** (#265, #266): per-form ordered rules that
  swap the post-submit message based on the submitted values, with a CP rules
  editor, per-site translations, and a save-time guard rail that warns when a
  rule references a field that no longer exists (#267).
- **Text** layout block (#264): a static paragraph element for instructions
  between fields — value-less, skipped by validation, storage, and export.
- **Quiz mode** (#241): per-option scores, grade bands, and quiz results on the
  GraphQL submit payload; per-form **survey reporting** with per-question
  aggregates (#240).
- **Conversational render mode** (#239) with a built-in centered-card theme and
  progress bar (#243), and **logic jumps** to branch a multi-page form to a
  non-adjacent step (#245) — jump targets are validated at save.
- **Payment coupons / discount codes** (#246): percent or fixed discounts with
  usage limits and windows, a CP management screen, a live discount preview on
  the form, and case-insensitive code uniqueness (also on Postgres).
- **Address autocomplete** (#250): opt-in suggestions on the Address field via
  Photon, Nominatim, or Google Places.
- **Submission approval workflow** (#248): configurable statuses/transitions,
  CP transition buttons, and an `EVENT_SUBMISSION_TRANSITIONED` hook.
- **Passive partial capture** (#242, #244): debounced auto-save of in-progress
  forms with a CP "abandoned" view, a consent gate, its own retention setting,
  and an `EVENT_PARTIAL_CAPTURED` developer event.
- **UTM/referrer attribution capture** (#249): opt-in auto-capture stored with
  the submission and shown on the CP detail.
- **Embed & share modes** (#247): a shareable standalone form URL and an
  embeddable variant.
- A CP **notification log** for outbound form emails: per-send rows with
  sent/failed status, error messages, filters, and stat cards, plus a per-row
  **Resend** action that re-dispatches through the existing send path and
  cross-references the original send (#318).
- **No-JS submissions** (#287): a plain form POST (no JavaScript) now
  round-trips as HTML — errors are flashed per-form and rendered through the
  `errors.twig` theme seam with field labels, success shows the resolved
  message or follows the redirect action. Programmatic/JSON clients are
  unaffected.
- Builder: **keyboard and touch reordering** (#291) — per-card Move up/down
  buttons and Alt/Ctrl+Arrow on the focused card, with live-region
  announcements; reordering no longer requires drag-and-drop.
- Builder: deleting a field now asks for confirmation and names the other
  fields whose rules depend on it; saves that prune rules referencing a
  removed field say so in the save notice instead of dropping them silently
  (#288).
- Builder: multi-page forms show a **"Step N"** separator bracketing each
  page's fields in the canvas, with an inline note when the authored step
  number differs from the effective (compacted) step (#292).
- Builder: the field palette is grouped into Basic/Choice/Advanced/Layout with
  a search filter, and the field handle input warns on a live duplicate
  collision as you type — the save-time guard remains the backstop (#296).
- Privacy: IP capture is now a three-state policy — **full, anonymized** (the
  last IPv4 octet or low 80 bits of an IPv6 address are masked before storage),
  **or off** — superseding the old on/off "Collect IP addresses" toggle while
  staying backward-compatible with it (#293, #315). Rate limiting keeps working
  under any mode (nothing is stored beyond the window), and IP-based duplicate
  detection degrades to the other dedupe keys when IPs aren't fully captured.
- Immutable **per-submission field snapshots** (#312): a submission now
  captures its form's field handles, labels, option labels, and display order
  at submit time. The CP detail view and every CSV export render from the
  snapshot when present, so renaming, reordering, or deleting a field later no
  longer corrupts the labels/order shown for existing submissions.
- **CC/BCC address lists** on notifications, alongside the existing recipient
  and Reply-To, with the same header-injection-safe validation (#313).
- Two new submissions console subcommands for data-subject requests:
  `submissions/export-by-email` (CSV of every submission tied to an email) and
  `submissions/erase-by-email` (delete or anonymize matches, honoring
  `anonymizeInsteadOfDelete`, with `--dry-run` preview) (#314).
- Opt-in **query-string prefill**: a field can be set to read its default value
  from a URL query parameter (per-field On/Off/inherit, with a form-level
  default); prefilled values are still ordinary defaults, validated on submit
  and overridden by resume/edit or an actual submitted value (#316).
- **CSV export column selection**: operators can export a chosen subset of
  submission columns from the Submissions index instead of every column; the
  default stays every column, and formula-injection neutralization still runs
  on every emitted cell (#317).
- **URL** field type: a single validated, normalized URL (scheme-less entries
  are normalized to `https://`); participates in snapshots, CSV export,
  conditional logic, and GraphQL like the other scalar fields (#319).
- **Time** field type: a time-of-day (HH:MM, 24-hour) independent of a date
  (#320).
- **Date & Time** field type: a combined date + time value in one input,
  stored as `YYYY-MM-DDTHH:MM` (#321).
- **Editable submissions in the CP** (#294): an admin with *Manage
  submissions* can correct a submission's field values from an "Edit
  submission" screen, which re-validates, re-snapshots, and re-scores through
  the same core the front-end tokenized editor uses. File/signature/payment
  values are read-only.
- Notification authoring: friendly operator labels in the send-condition
  dropdown, a field-select autoresponder recipient (choose an email/text field
  instead of typing an address), a documented available-variables reference
  under the body editor, a **Send test** button, and a plain-text alternative
  part on every send for better deliverability (#290).
- Field **default values**: a field can carry an authored default that
  prefills the input the first time a form renders — a submitted or resumed
  value always takes precedence (#295).
- A **default upload volume** setting (Settings → General) that File fields
  fall back to when they don't specify their own volume (#296).
- Payment field inspector: Amount is now a select of the form's
  number/calculation field handles, and Currency is an ISO-4217 select instead
  of free text (#296).
- The Forms index empty state now offers "New from stencil" alongside a blank
  New Form (#296).
- Runs on both **MySQL 8** and **PostgreSQL 16**; the integration suite runs
  against both databases in CI.
- Auto-apply forms on deploy: a new `applyFormsConfigOnUp` setting (off by
  default) makes `craft up` run `simple-form/forms/apply` when it finishes, so
  code-defined forms deploy with the rest of the project. The automatic run never
  prunes. See [Forms as code](docs/forms-as-code.md).
- Full-fidelity form export (#226): the portable form document now carries **all
  form-level settings** — post-submit action, availability windows, submission
  limits, login-required, per-user limits, editing window, duplicate prevention,
  render template path — so import/export and forms-as-code round-trip a complete
  form, not just its structure. A post-submit "redirect to entry" travels as the
  entry's URI (resolved on import, falling back to the inline message with a
  warning if absent). The document is schema-versioned (v2); older (v1) files
  still import with the new settings keeping their defaults.
- Forms as code (#218, #225): keep form definitions in
  `config/simple-form/forms/<handle>.json` and deploy them with
  `simple-form/forms/apply` — creates missing forms and **updates existing ones
  in place, id-stably** (form + fields matched by handle keep their ids, so
  submissions and conditional references survive). An update reconciles the form's
  name, fields, and per-site content from the file (the file is authoritative).
  `--prune` removes fields no longer in the file, except any field that still
  holds submission data (always kept). `simple-form/forms/status` reports
  config-managed vs database-only forms. `forms/export --out=` creates the target
  directory if needed. See [Forms as code](docs/forms-as-code.md).
- Copy-paste `examples/` (#221): a working custom field type, outbound
  integration, captcha provider, and theme partial override, each with its
  registration snippet — the fastest path to a first custom extension.
- Scaffolding generators (#222): `simple-form/make/field-type`,
  `simple-form/make/integration`, and `simple-form/make/theme` console commands
  that generate a ready-to-edit stub (and print the registration one-liner) so a
  custom extension starts from working code.
- API stability contract (#223): documented which surfaces are public and
  backward-compatibility-guaranteed vs. internal, the semver policy
  (`docs/extending/api-stability.md`), and an upgrade guide (`docs/upgrading.md`).
  This changelog and that policy together define what a major/minor/patch means
  for the public API.
- IDE & headless ergonomics (#224): a committed GraphQL SDL of the Simple Form
  types at `docs/reference/schema.graphql` (regenerate with
  `php craft graphql/print-schema --full-schema=1`), and a `.phpstorm.meta.php`
  so `getPlugin('simple-form')` resolves to the concrete `Plugin` for service
  autocomplete.
- Front-end JavaScript hook API (#220): the bundled form script now dispatches
  namespaced `CustomEvent`s on the form element — `simpleform:beforeSubmit`
  (cancelable; `preventDefault()` aborts the send), `simpleform:afterSubmit`,
  `simpleform:validationFailed` and `simpleform:stepChange` — so host pages can
  observe and gate the form lifecycle. See
  [Custom Render Templates](docs/render-templates.md#front-end-javascript-events).
- Developer extension surface (#219): a `EVENT_REGISTER_FIELD_TYPES` event so
  custom field types register the same way as integrations, captcha providers and
  stencils, plus five lifecycle seam events — `EVENT_DEFINE_FIELD_SET`,
  `EVENT_MODIFY_RENDER_CONTEXT`, `EVENT_BEFORE_VALIDATE`,
  `EVENT_BEFORE_SEND_NOTIFICATION` and `EVENT_BEFORE_INTEGRATION_DISPATCH` — for
  modifying or cancelling rendering, validation, notifications and dispatch
  without forking the plugin. See [Developer API](docs/twig-and-api.md#events).
- **Payments via Craft Commerce** ([guide](docs/payments.md)): add a Payment
  field to a form to collect a fixed or field-driven amount through the
  configured gateway's embedded form (pay-to-submit — a decline saves
  nothing). On submit a pending Commerce order is created (a Donation line
  item for the amount); the submission records its order id + payment status.
  Notifications and integrations are withheld until the payment settles and
  released automatically once it does; abandoned offsite checkouts expire to a
  `canceled` status. Commerce is an optional/soft dependency — without it the
  field is inert. CP shows payment status, amount, and a link to the order.
- **18 new field types** ([guide](docs/field-types.md)): Phone (country code +
  validation), Hidden (dynamic defaults), Agree/Consent (GDPR), Name and Address
  (composite), Rating and Opinion Scale/NPS, Signature, Calculation (formula
  engine), Repeater, Entry/Category/Tag/User/Asset relations, and
  Heading/Divider/HTML layout blocks.
- **Spam denylists & review queue** ([guide](docs/spam-protection.md)): blocked
  keywords/emails/IPs, per-form duplicate-submission prevention, and a spam
  quarantine — flagged submissions are reviewable in the CP and approving a
  false-positive re-fires its withheld notification + integration dispatch.
- **Form availability** ([guide](docs/form-availability.md)): per-form open/close
  windows, a total submission quota, login-required, and per-user submission
  limits — all enforced server-side (AJAX, no-JS, and GraphQL).
- **Per-form post-submit behaviour**: override the success/error message per form
  and redirect to a URL or entry after submit, with submitted-value templating.
- **Multi-column row layout** for the form builder, and **stencils** (start a
  form from a built-in preset) plus a **duplicate-form** action.
- **Custom render templates** ([guide](docs/render-templates.md)): override how
  forms and fields render with your own Twig partials.
- **PDF notifications** ([guide](docs/notifications.md)): attach a generated PDF
  of the submission (optional dompdf dependency) and the submission's uploaded
  files to notification emails.
- **Google Sheets** integration and a **Create Craft Element** integration
  (build an Entry or User from a submission) ([guide](docs/integrations.md)).
- **Front-end submission editing** ([guide](docs/submissions.md)): let submitters
  edit a submission via a secure tokenized link or `craft.simpleForm.editForm()`,
  with a per-form toggle + edit window and a GraphQL `updateSubmission` mutation.
- Japanese, Dutch and Portuguese translation catalogs (now 8 locales: en/de/es/fr/it/ja/nl/pt).
- Audit log (Settings → Audit Log): an append-only trail of form, integration,
  notification and submission-status changes (actor, action, target, summary),
  filterable and pruned by a configurable retention window (Settings → Privacy).
- Submissions are now soft-deleted (trashable) and restorable, with a **Trashed**
  source on the element index. A permanent delete now cascades the plugin row
  (added the missing `simpleform_submissions` → `elements` foreign key), so trash
  GC and retention purges no longer orphan rows.
- Per-form email notifications (form → Notifications): add any number of
  notifications, each with its own recipient, subject, reply-to and body. A
  recipient can be a fixed address **or a form field**, enabling autoresponders
  to the submitter. Each notification can be gated by a send condition (reusing
  the conditional-logic engine). Existing single-recipient forms are migrated to
  a default notification automatically.
- Submissions analytics dashboard (Submissions → Analytics): submissions-over-time
  chart with a 7/30/90-day range selector, status breakdown, spam-vs-legitimate
  split, per-form totals, and integration dispatch health — backed by a
  ReportsService.
- `craft.simpleForm.*` template API: `.form(handleOrId)`, `.forms(criteria)`,
  `.submissions(criteria)` (element queries) and `.render(handle, options)`
  (rendered markup). The existing `simpleForm()` function is unchanged.
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

### Changed
- A freshly-installed plugin resolves to the Solo edition by default; run the
  Pro edition to unlock the full feature set.
- Fresh installs seed the default email sender from Craft's system mail
  settings, so settings tabs save out of the box (#280); failed settings saves
  now list every error and name the tab an offending field lives on.
- A failed form save now flashes every validation error (not just the first),
  and element errors open the Details tab selected and error-badged instead of
  leaving a seemingly clean Build tab (#289).
- The CP Overview screen was renamed **Dashboard** and rebuilt from native CP
  components.
- Edition assignment now covers the post-1.0 feature batch: logic jumps,
  conversational render mode, quiz scoring, partial capture, and the
  submission-approval workflow require **Pro** to newly enable (a form already
  using one of them on a downgraded install keeps working); attribution/UTM
  capture, address autocomplete, and analytics stay Solo-free (#283).
- The control panel is now fully translated: every CP screen (575 strings) and
  the form-builder's JavaScript UI (245 strings) route through the translation
  catalogs across all 8 locales (#285, #335).
- Large installations scale better: indexed duplicate detection with a
  configurable guest-email submission limit, cached Dashboard/Stats aggregates
  (invalidated on writes), CSV exports streamed in bounded batches, and
  additional indexes on the submissions table (#337–#341).
- Simple Form is now commercial software; the license changed from MIT to
  proprietary (see LICENSE.md).

### Fixed
- The Forms index row actions (Delete, Duplicate, "Start from a stencil") did
  nothing when clicked — they posted into a nonexistent form (#279). Delete now
  confirms through Craft's dialog and redirects with a notice.
- The signature field was registered, documented, and Pro-gated but missing
  from the builder palette, so it couldn't be added through the UI (#281).
- Form duplication (and third-party stencils) bypassed the edition gate,
  letting Solo mint new forms carrying Pro features; duplicated forms also
  lost their notifications' PDF/upload attachment flags (#282). Two further
  edition-gate bypasses in CP write paths were closed (#254).
- Duplicate-submission prevention wrongly reused the denylist's block/flag
  mode, silently dropping duplicates when the denylist was set to block — it
  now has its own mode setting (#273).
- The save-&-resume link discarded the page's query string, breaking
  `?handle=`-routed forms and UTM attribution on resume (#274).
- Required fields on steps skipped by logic jumps blocked the browser's native
  validation, leaving a dead submit button; skipped and jump-unreachable
  steps' controls are now disabled correctly (#275).
- Form export/apply silently reset `renderMode`, quiz settings, attribution
  capture, and partial capture to defaults (#276).
- The `submitForm` GraphQL mutation now returns the resolved (per-form,
  per-site, conditional) message instead of the global default (#263).
- The form editor presented Title as optional but a blank Title blocked save
  with "Title cannot be blank"; a blank Title now defaults to the form's Name
  (matching what's displayed elsewhere), and the field is hinted as optional
  (#428).
- Integration secrets (API keys/tokens/signing secrets) are no longer echoed
  back in cleartext on the integration edit screen; env-var references
  (`$VAR`) stay visible/editable, and leaving a masked secret field blank on
  save now keeps its stored value instead of wiping it (#429).
- The dispatch-failures screen crashed ("Variable failures does not exist")
  when reached via its `settings/` URL, which fell through to template
  routing instead of the controller action (#435).
- Duplicating a form now copies translated field labels for every site, not just
  the primary site.
- Calculation-field values that are whole numbers no longer compare unequal after
  the submission-data round-trip.
- Chat/notification "Label: value" lines no longer error on legacy submissions
  whose rows were stored as bare scalars.

### Security
- **Critical** — the Craft Element integration's entry-title template was
  rendered with the unsandboxed Twig environment, so an editor holding only the
  (non-admin) *Manage form integrations* permission could reach `craft.app`, the
  database, and secrets on the next submission (SSTI → site takeover, CWE-94). It
  now renders through the same forced-sandbox seam as every other editor
  template.
- **MCP tokens are admin-only.** Creating or revoking an MCP access token now
  requires an admin with an elevated session. Previously *Manage plugin
  settings* alone could mint a `submissions:export` token and read/export every
  submission, bypassing the separate *View submissions* permission (privilege
  escalation, CWE-269).
- **Privacy hashes are keyed.** The denormalized `ipHash`, `guestEmailHash`, and
  `dedupeHash` fingerprints were unsalted SHA-256 digests, reversible from a
  DB-only leak by precomputation — which defeated the IP-anonymization guarantee
  (CWE-759/916). They are now HMAC-keyed with the site security key. A migration
  re-keys the email/dedupe hashes from stored data and purges the now-reversible
  historical `ipHash` values (IP-based duplicate detection rebuilds from new
  submissions). Note: privacy hashes don't survive a `securityKey` rotation.
- **Submit rate limiting is on by default** (`submitRateLimitPerMinute` now
  defaults to **10**/visitor-IP/minute) so a fresh install isn't an open flood
  target; set `0` to disable (CWE-770). The Spam Protection tab warns when both
  rate limiting and CAPTCHA are off.
- **Elevated session for spam secrets.** Saving the Spam Protection tab (which
  holds the CAPTCHA/Akismet secret keys) now requires a re-verified password
  (CWE-306), and a save-time tip steers operators toward environment references
  so secrets stay out of the database and project config (CWE-312).
- **Field-length ceiling.** Text-bearing fields reject values over 64 KB even
  when no `maxLength` is configured, so an oversized POST can't inflate the
  submission store (CWE-770).
- **Rate limiter hardening.** The abuse throttle now initializes its window
  atomically and logs a warning when the app cache is a `DummyCache` (under which
  it can't accumulate), instead of silently disappearing (CWE-703).
- **SSRF DNS-pin hardening.** Integration dispatch is pinned to Guzzle's cURL
  handler so the DNS-rebinding guard's `CURLOPT_RESOLVE` can't be silently
  dropped by a stream-handler fallback (CWE-918).
