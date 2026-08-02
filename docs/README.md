# Simple Form — Documentation

The single entry point for Simple Form's documentation. (The short overview and
installation live in the [project README](../README.md).)

**New here? Start with [Getting started](getting-started.md)** — install, your
first form, and what to configure first.

**[Editions](editions.md)** — what Solo includes, what Standard adds, and exactly
what changes on a downgrade. Standard-only features are marked in the guides below.

## Feature guides

- **[Building forms](building-forms.md)** — the form builder, multi-step pages,
  multi-column layouts, conversational (one-question-per-screen) mode,
  save-and-continue-later, passive partial capture, UTM/attribution capture,
  post-submit behaviour, sharing & embedding, stencils, and duplicating forms.
- **[Field types](field-types.md)** — reference for all 33 field types and their
  configuration options, including address autocomplete.
- **[Conditional logic](conditional-logic.md)** — show/hide fields and make them
  conditionally required based on other fields' values, with live client-side
  evaluation and authoritative server-side enforcement — plus logic jumps to
  branch a multi-page form.
- **[Quizzes & surveys](quiz-and-surveys.md)** — score submissions against an
  answer key with grade bands, and aggregate per-question survey reports.
- **[Spam protection](spam-protection.md)** — honeypot, rate limiting, captcha
  providers (reCAPTCHA / hCaptcha / Turnstile), Akismet, denylists, duplicate
  prevention, and the spam-review queue.
- **[Availability & limits](form-availability.md)** — open/close windows,
  submission quotas, login-required, and per-user submission limits.
- **[Notifications](notifications.md)** — admin notifications, autoresponders,
  conditional/multiple notifications, translatable templates, PDF + file
  attachments, and the CP notification log.
- **[Outbound integrations](integrations.md)** — push submissions to webhooks and
  pluggable connectors (Slack/Discord, email marketing, CRMs, Google Sheets,
  Craft elements) asynchronously, with retries, dispatch logs, resend, and a
  read-only GraphQL/MCP surface. Includes how to write a custom connector.
- **[Submissions](submissions.md)** — the CP Dashboard, submission management,
  the approval workflow, export, trash/restore, retention/GDPR, analytics,
  audit log, and front-end editing.
- **[Payments](payments.md)** — collect a payment on submit via Craft Commerce:
  the embedded gateway form, pay-to-submit flow, coupons/discount codes,
  offsite/3-D-Secure handling, payment status + abandoned-checkout expiry, and
  the CP surfaces.
- **[Theming / render templates](render-templates.md)** — override how forms and
  fields render with your own Twig partials.
- **[Import / export](import-export.md)** — move a form's full definition between
  installs as a portable, versioned, secret-free JSON file, via console or the CP.
- **[Forms as code](forms-as-code.md)** — keep form definitions in
  `config/simple-form/forms/` and deploy them across environments with
  `forms/apply`.
- **[Twig & developer API](twig-and-api.md)** — Twig rendering, the PHP API,
  GraphQL, events, the MCP server, and console commands.

## Testing

- **[Running tests](testing/RUNNING_TESTS.md)** — the canonical guide:
  `composer check` (the gate), `test`, `test:js`, `test:integration`, `test:smoke`.

## Extending & API stability

- **[Twig & developer API](twig-and-api.md)** — the programmatic surface (Twig,
  PHP, GraphQL, events, MCP, console).
- **[API stability & backward compatibility](extending/api-stability.md)** — what
  counts as public API, the semver policy, and what's internal.
- **[Upgrade guide](upgrading.md)** — breaking changes and how to adopt them.

## Reference

- [Settings](reference/SETTINGS.md) — every plugin setting in one place: default,
  purpose, and a link to the guide for each.
- [PERMISSIONS.md](reference/PERMISSIONS.md) — user permissions the plugin registers.
- [GraphQL schema (SDL)](reference/schema.graphql) — the SimpleForm GraphQL types,
  queries, and mutations for headless clients.
