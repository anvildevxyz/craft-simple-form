# Editions

Simple Form ships in two editions, **Solo** and **Standard**.

- **Solo is free.** A freshly installed plugin runs as Solo, with no license and
  no payment, in as many projects as you like.
- **Standard is $39** per project (plus $19/year after the first year for continued
  updates and support — renewal is optional; an expired license keeps working).
  Switch to Standard from **Settings → Plugins** once you hold a license.

Solo is the "better contact form": unlimited forms, stored submissions, and
everything most sites need to collect and act on enquiries. Standard adds the
advanced field types, multi-step and conditional forms, the third-party
integrations, payments, and the governance/developer tooling.

Upgrading is a price difference, not a repurchase, and it's non-destructive —
everything you built on Solo carries straight over.

## What each edition includes

| | Solo | Standard |
|---|---|---|
| Unlimited forms and stored submissions | ✅ | ✅ |
| Field types | 22 of 33 | all 33 |
| Multi-site, per-site translated forms | ✅ | ✅ |
| Email notifications + autoresponders, notification log | ✅ | ✅ |
| Honeypot, rate limiting, CAPTCHA (reCAPTCHA / hCaptcha / Turnstile) | ✅ | ✅ |
| Availability windows, quotas, login-required, per-user limits | ✅ | ✅ |
| CSV / JSON / XML export, trash & restore, submission editing | ✅ | ✅ |
| Dashboard, analytics, survey reports | ✅ | ✅ |
| Attribution / UTM capture, address autocomplete | ✅ | ✅ |
| Submission approval workflow | ✅ | ✅ |
| Webhook + Create Craft Element integrations | ✅ | ✅ |
| Import / export and forms-as-code | ✅ | ✅ |
| Advanced field types (see below) | — | ✅ |
| Conditional logic | — | ✅ |
| Multi-page forms | — | ✅ |
| Save & continue later | — | ✅ |
| Conversational render mode | — | ✅ |
| Quiz scoring | — | ✅ |
| Passive partial capture | — | ✅ |
| Akismet + keyword/email/IP denylists | — | ✅ |
| Automated submission & audit-log retention | — | ✅ |
| PDF attachments on notifications | — | ✅ |
| Audit log | — | ✅ |
| Commerce payments + coupons | — | ✅ |
| Slack, Discord, Mailchimp, ActiveCampaign, HubSpot, Pipedrive, Google Sheets | — | ✅ |
| MCP server | — | ✅ |

### Standard field types

Eleven of the 33 [field types](field-types.md) are Standard:

**Signature**, **Payment**, **Rating**, **Opinion Scale**, **Calculation**,
**Repeater**, and the five element relations — **Entry**, **Category**, **Tag**,
**User**, **Asset**.

The other 22 — including file upload, name, address, consent, and every text,
choice, date, and layout type — are available on Solo.

### Standard integrations

Solo can use the **Webhook** and **Create Craft Element** connectors. Every
other connector (Slack, Discord, Mailchimp, ActiveCampaign, HubSpot, Pipedrive,
Google Sheets) is Standard.

## The gate covers authoring, never the visitor-facing runtime

This is the important part of the contract: **the edition gate only governs what
you can author.** It is never consulted when rendering a form, validating a
submission, or storing one.

A form built on Standard keeps rendering and accepting submissions after a downgrade
to Solo. Its conditional logic still evaluates, its multi-page navigation still
works, its Standard fields still render and validate. Visitors see no difference.

## What happens on a downgrade

Going from Standard to Solo, a form that already uses Standard features stays intact and
stays editable. What you lose is the ability to *add more*:

- **Forms keep their Standard features.** Saving a form that already has a signature
  field, conditional logic, or multiple pages preserves all of it. The form
  editor shows a non-blocking "Standard features in use" banner.
- **No new escalation.** You can't add a Standard field type, turn on conditional
  logic, split a form across pages, or enable save & continue / conversational
  mode / quiz scoring / partial capture where it wasn't on before. Adding a
  *second* field of an already-present Standard type counts as an escalation and is
  blocked too.
- **Data hygiene keeps running.** Spam protection, denylists, and retention
  continue to operate — a downgrade never quietly starts letting spam through or
  stops honoring a retention window.
- **Standard back-office services pause, then resume.** Conditional submit-message
  resolution falls back to the default message, notifications send without their
  PDF, and no audit-log rows are written. Returning to Standard resumes all three;
  nothing is lost but the rows that would have been written while on Solo.

### Standard settings on Solo

Settings behind Standard features stay visible so a downgraded site keeps control of
what's already running, but they only accept changes that can't escalate:

- **Off switches and thresholds** (`enableAkismet`, `enableDenylists`,
  `retainSubmissionsDays`, `retainAuditLogDays`) can be turned off or left
  alone. Turning one on, or changing a still-on threshold — including shortening
  a retention window so it deletes more aggressively — is rejected.
- **Spam verdict modes** (`akismetMode`, `denylistMode`) may only move toward
  the non-destructive `flag` value. A downgraded site can stop a still-running
  filter from silently dropping submissions, but can't escalate it to `block`.
- **Companion configuration** (`akismetApiKey`, `blockedKeywords`,
  `blockedEmails`, `blockedIps`, `anonymizeInsteadOfDelete`) is frozen and
  rendered read-only, so a still-running Standard feature can't be reconfigured.

### Import, export, and forms-as-code on Solo

[Import/export](import-export.md) and [forms-as-code](forms-as-code.md) are
available on both editions, and they enforce exactly the same rule as the CP: a
document that would introduce a new Standard capability is rejected on Solo with the
blocked features named. A document that merely reproduces Standard features an
existing form already has applies cleanly.

## Checking the edition in your own code

```twig
{% if craft.simpleForm.isStandard() %}…{% endif %}
{% if craft.simpleForm.can('conditionalLogic') %}…{% endif %}
```

Capability handles: `conditionalLogic`, `multiPage`, `saveAndContinue`,
`conversational`, `quiz`, `partialCapture`, `pdf`, `governance`, `devTools`.

```php
use anvildev\simpleform\Editions;

Editions::isStandard();
Editions::can(Editions::CAP_CONDITIONAL_LOGIC);
Editions::fieldTypeAllowed('signature');
Editions::integrationAllowed('slack');
```

`Editions` is **default-open**: anything other than an explicitly Solo license
resolves to Standard, so an unresolvable edition never silently restricts a site.

## A note on logic jumps

[Logic jumps](conditional-logic.md#logic-jumps) themselves aren't edition-gated,
but they only do anything on a multi-page form — and multi-page is Standard. In
practice that means Solo can keep and edit jumps on a form that already has
pages, but can't create a new multi-page form to use them on.
