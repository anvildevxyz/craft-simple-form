# Getting started

From a fresh install to a working, spam-protected form — and what to configure
first. Each step links to the in-depth guide for that area.

## 1. Install

```bash
composer require anvildev/craft-simple-form
php craft plugin/install simple-form
```

Simple Form works out of the box with sensible defaults; everything below is
about tuning it for your site. Craft Commerce, an MCP client, and the captcha
providers are all optional.

## 2. Build your first form

In the Control Panel, go to **Simple Form → Forms → New Form**:

1. Give it a **name** and **handle**, and set the **Email To** recipient for
   admin notifications.
2. Add fields from the builder palette (start with **Email** and **Textarea**),
   and configure each in the right-hand inspector.
3. Save.

See **[Building forms](building-forms.md)** for multi-step pages, multi-column
layouts, conditional logic, save-and-continue-later, and stencils, and
**[Field types](field-types.md)** for the full field reference.

## 3. Render it

Drop the form into any Twig template by handle:

```twig
{{ craft.simpleForm.form('contact') }}
```

That renders the form, its assets, validation, and the AJAX submit handling.
To theme the markup with your own partials, see
**[Render templates](render-templates.md)**. For the PHP/GraphQL ways to render
and submit, see **[Twig & developer API](twig-and-api.md)**.

## 4. Configure the essentials

Most of these are plugin **Settings** (CP → *Simple Form → Settings*, or
`config/simple-form.php`). The **[Settings reference](reference/SETTINGS.md)**
lists every option; the high-value first steps:

1. **Sender address** — set `defaultEmailSender` / `defaultEmailSenderName` so
   notifications come from your domain. → [Notifications](notifications.md)
2. **Spam protection** — the honeypot is **on by default**. For any *public*
   form, also set a **rate limit** (`submitRateLimitPerMinute`, e.g. `10`) and
   turn on a **captcha** provider (reCAPTCHA / hCaptcha / Turnstile). Keep keys
   in `.env`. → [Spam protection](spam-protection.md)
3. **Notifications & autoresponders** — confirm the admin notification, and add a
   visitor autoresponder if wanted. → [Notifications](notifications.md)
4. **Data retention / GDPR** — decide how long submissions are kept
   (`retainSubmissionsDays`) and whether to anonymize instead of delete. Off by
   default (keep forever). → [Submissions](submissions.md)
5. **Permissions** — grant non-admins the right access with the plugin's
   user-group permissions. → [Permissions](reference/PERMISSIONS.md)

## 5. Going further

- **[Availability & limits](form-availability.md)** — open/close windows,
  quotas, login-required, per-user limits.
- **[Outbound integrations](integrations.md)** — push submissions to webhooks,
  Slack/Discord, email-marketing/CRM connectors, Google Sheets, or Craft
  elements.
- **[Payments](payments.md)** — collect a payment on submit via Craft Commerce.
- **[Import / export](import-export.md)** — move a form definition between
  installs as portable JSON.
- **[Twig & developer API](twig-and-api.md)** — PHP API, GraphQL, events, the MCP
  server, and console commands.

The full index lives in **[docs/README.md](README.md)**.
