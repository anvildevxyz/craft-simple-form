# Simple Form

A lightweight, translatable form builder for Craft CMS. Create forms in the Control Panel, render them in your templates, and manage submissions—with full multi-site translation support.

## Features

- **Form Builder** — drag-and-drop field builder in the Control Panel, with multi-step pages, multi-column layouts, conditional logic, save-and-continue-later, stencils, and duplication ([guide](docs/building-forms.md))
- **28 Field Types** — text, email, textarea, number, phone, date, hidden; select, checkbox, radio, rating, opinion scale, consent (GDPR); name & address; file upload, signature, calculation, repeater, payment; entry/category/tag/user/asset relations; and heading/divider/HTML layout blocks ([guide](docs/field-types.md))
- **Conditional Logic** — show/hide fields and make them required based on other fields' values ([guide](docs/conditional-logic.md))
- **Spam Protection** — honeypot, rate limiting, reCAPTCHA / hCaptcha / Turnstile, Akismet, keyword/email/IP denylists, duplicate prevention, and a spam-review queue ([guide](docs/spam-protection.md))
- **Availability & Limits** — open/close windows, submission quotas, login-required, and per-user submission limits ([guide](docs/form-availability.md))
- **Email Notifications** — admin notifications, autoresponders, conditional/multiple notifications, translatable templates, and optional PDF + file attachments ([guide](docs/notifications.md))
- **Outbound Integrations** — webhooks, Slack/Discord, Mailchimp/ActiveCampaign, HubSpot/Pipedrive, Google Sheets, or create Craft entries/users — async, with retries, dispatch logs, and resend ([guide](docs/integrations.md))
- **Submissions** — native Craft elements with CP management, CSV/Excel export, trash/restore, retention/GDPR controls, analytics, an audit log, and secure front-end editing ([guide](docs/submissions.md))
- **Translatable** — form titles, descriptions, field labels, options, and messages translate per site
- **Developer API** — Twig + PHP rendering, GraphQL queries/mutations, lifecycle events, console commands, and an MCP server ([guide](docs/twig-and-api.md)), with a documented [public-API stability contract](docs/extending/api-stability.md)

## Requirements

Craft CMS 5.x and PHP 8.2 or later.

## Installation

1. Install the plugin via Composer:
   ```bash
   composer require anvildev/craft-simple-form
   ```

2. Install the plugin in Craft:
   ```bash
   php craft plugin/install simple-form
   ```

3. Run migrations:
   ```bash
   php craft migrate/all
   ```

## Quick Start

### Create a Form in CP

1. Navigate to **Simple Form > Forms**
2. Click **New Form**
3. Enter a name, handle, and email recipient
4. Add fields (Text, Email, etc.)
5. Save

### Render in Twig

```twig
{{ craft.simpleForm.form('contact') }}
```

### Build Custom Forms (PHP API)

```php
$form = \anvildev\simpleform\elements\Form::find()
    ->handle('contact')
    ->one();

$fields = $form->getFields();
// Render fields however you like...
```

## Translations

The control-panel UI ships with English plus machine-translated **German,
Spanish, French, Italian, Japanese, Dutch, and Portuguese** catalogs
(`src/translations/`). These use the English source string as the key, so any
untranslated string degrades gracefully to English. The non-English catalogs are
machine-translated and **pending native review** — corrections welcome. A unit
test enforces key parity and non-empty values across all catalogs so they can't
silently drift.

## Documentation

Full documentation lives in **[`docs/`](docs/README.md)**. Feature guides:

- [Building forms](docs/building-forms.md) · [Field types](docs/field-types.md) · [Conditional logic](docs/conditional-logic.md)
- [Spam protection](docs/spam-protection.md) · [Availability & limits](docs/form-availability.md)
- [Notifications](docs/notifications.md) · [Integrations](docs/integrations.md) · [Submissions](docs/submissions.md)
- [Theming / render templates](docs/render-templates.md) · [Import / export](docs/import-export.md) · [Twig & developer API](docs/twig-and-api.md)
- [API stability](docs/extending/api-stability.md) · [Upgrade guide](docs/upgrading.md) · copy-paste [`examples/`](examples/) (custom field type, integration, captcha, theme)
- [Forms as code](docs/forms-as-code.md) — version-controlled form definitions deployed with `forms/apply`

## License & Pricing

Simple Form is commercial software, licensed as a single **Pro** edition. See
[LICENSE.md](LICENSE.md) for the full terms. Licensing and updates are handled
through the Craft Plugin Store.
