# Email Notifications

When a form is submitted, Simple Form can email one or more recipients — an
admin alert, an autoresponder back to the person who filled in the form, or a
set of conditional emails that only fire for certain answers. Composing and
sending happens **asynchronously on Craft's queue**, so a slow mailer (or a
rendered PDF / file attachment) never blocks or fails the visitor's submission.

## Two ways to configure notifications

Simple Form supports two coexisting models:

- **Multiple notifications (recommended).** Each form can have any number of
  notification rows, managed from **Simple Form → Forms → Notifications** on the
  form's row. This is where autoresponders, conditions, and attachments live.
- **Legacy single email (fallback).** Older forms configured through the form's
  own email columns (**Email To / Subject / Reply-To / Body**) keep working
  unchanged.

A form's notification rows take precedence. **Only when a form has no
notification rows** does Simple Form fall back to its legacy email columns. So
once you add a notification, the legacy email is no longer sent for that form.

## Adding a notification (Control Panel)

1. Go to **Simple Form → Forms** and click **Notifications** on the form's row.
2. Click **New notification**, give it a **Name**, and leave **Enabled** on.
3. Choose a **recipient** (see below), optionally set a **Subject**, **Reply-To**
   and a custom **Body**, then **Save**.

Managing notifications requires the **Manage forms and fields** permission
(`manageForms`).

### Notification settings

| Setting | Notes |
|---------|-------|
| **Name** | Internal label for the notification (required). |
| **Enabled** | Disabled notifications never fire. |
| **Recipient type** | **Fixed address(es)** or **From a form field** (autoresponder). |
| **Recipient** | For *fixed*, one or more addresses separated by comma, semicolon or whitespace. For *field*, the handle of the email field to read. (required) |
| **Subject** | Optional. Blank falls back to a default subject (see below). |
| **Reply-To** | Optional. Validated as an email address (a malformed value is rejected). |
| **Body** | Optional Twig template. Blank falls back to the default body. |
| **Attach PDF** | Attach a rendered PDF of the submission (requires dompdf — see below). |
| **Attach uploaded files** | Attach the submission's file-field uploads. |
| **Condition** | Optional send condition gated on a field value (see below). |

#### From and sender name

The **From** address is global, not per-notification. Simple Form uses the
**Default email sender** / **sender name** from the plugin settings, falling back
to Craft's own system email settings when those are blank. Both are env-aware
(e.g. `$FORM_FROM_EMAIL`).

> There are no separate CC/BCC fields. To copy several people, add their
> addresses to a single **fixed** recipient list, or create additional
> notifications.

## Autoresponders

Set **Recipient type** to **From a form field** and pick the field handle that
holds the submitter's email address (e.g. your `email` field). When the form is
submitted, that field's value is used as the recipient — so the submitter gets a
confirmation email. The value is validated as an email and the notification is
simply skipped when the field is blank or invalid.

Autoresponders are a natural place to embed a tokenized "edit your submission"
link — see [Front-end submission editing](submissions.md#front-end-submission-editing).

## Conditional / multiple notifications

Because each form can hold many notifications, you can route different submissions
to different people. Each notification can carry a **send condition** that gates
whether it fires, reusing the same conditional-logic engine as the form builder:

- Enable the condition, choose a **field**, an **operator**, and a **value**.
- The notification only sends when the submission's value for that field
  satisfies the rule.

A notification fires for a submission only when it is **enabled**, its
**condition passes**, and it resolves to **at least one valid recipient**.

Example: a "Support request" form with two notifications — one fixed to
`support@example.test` that always fires, and a second fixed to
`urgent@example.test` that only fires when the `priority` field equals `High`.

## Body templates

The **Body** field is a Twig template, rendered with these variables:

- `form` — the form element
- `submission` — the submission element
- `data` — the submission's field rows, each `{ label, type, value }`

```twig
<h2>New enquiry from {{ data.fullName.value ?? 'a visitor' }}</h2>
<p>Submitted {{ submission.dateCreated|date('Y-m-d H:i') }}.</p>
{% for row in data %}
  <p><strong>{{ row.label }}:</strong> {{ row.value }}</p>
{% endfor %}
```

Bodies are rendered through a **forced Twig sandbox**: because notification
bodies are editable by non-admin CP users (anyone with `manageForms`), the
template cannot reach `craft.app.*`, the database, the filesystem or arbitrary
classes. The `form`, `submission` and field models are allowed so the examples
above keep working. If a body errors or is left blank, Simple Form renders its
built-in default body — a titled table of every field label and value plus the
form name, date and (when present) the submitting user. The default subject is
`New Submission: <form title>`.

### Per-site / translatable bodies

Bodies render per the **submission's site**, so a multi-site install localises
naturally: write each site's wording into that site's notification (and use
`|t('simple-form')` for any shared strings).

## PDF generation

> **Standard edition.** PDF attachments require [Standard](editions.md) and the optional dompdf dependency. See [Editions](editions.md).


A notification can attach a **PDF of the submission**, and you can download the
same PDF from the CP submission detail screen.

### Requires dompdf (optional dependency)

PDF rendering uses **[dompdf](https://github.com/dompdf/dompdf)**, which is an
**optional** dependency. When it isn't installed the feature **degrades
gracefully**: the **Attach PDF** toggle is disabled in the CP (and rejected
server-side), the CP "Download PDF" button is hidden, and notifications still
send — just without the attachment. Install it to enable PDFs:

```bash
composer require dompdf/dompdf
```

### Attaching a PDF to a notification

Turn on **Attach PDF** on the notification. When the email is composed, Simple
Form renders the submission to a PDF and attaches it (filename like
`contact-42.pdf`).

### Overriding the PDF layout

The PDF is rendered from an overridable, sandboxed Twig template at
`simple-form/forms/notifications/pdf`. Copy the plugin's default into your
project to fully control the layout:

```
templates/simple-form/forms/notifications/pdf.twig
```

It receives the same `form`, `submission` and `data` variables as a body
template, renders per the submission's site (so it localises), and — like
bodies — runs sandboxed. For security, dompdf is configured with **remote
images disabled**, so a template can't make the worker fetch arbitrary URLs;
local assets still render.

### Downloading a PDF from the Control Panel

On a submission's detail screen (**Simple Form → Submissions → a submission**)
there's a **Download PDF** action (shown only when dompdf is installed). It
streams a freshly rendered PDF, or — when a storage volume is configured — the
stored Asset.

### Optionally storing the PDF as an Asset

Set the **PDF storage volume** plugin setting (`pdfStorageVolume`) to a volume
handle. When set, the rendered PDF is **persisted as an Asset** in that volume's
root folder; an existing Asset for the same submission is reused rather than
duplicated, and the CP detail screen serves the stored file instead of
re-rendering. Leave it empty to render on demand and never store.

## Attaching uploaded files

Turn on **Attach uploaded files** to attach the submission's file-field uploads
to the email. To protect deliverability, the combined attachment size (PDF +
uploads) is capped by the **Max attachment size (MB)** setting
(`maxAttachmentSizeMb`, default 10; 0 disables the cap). Uploads that would push
the email over the cap are **skipped from the attachment** (and logged) — they
remain available as in-body download links instead.

## The notification log

Every outbound form email — per-notification sends and the legacy single email
alike — is recorded in a **notification log**, so "did the confirmation go out?"
has a checkable answer. Open it from the top-level **Simple Form →
Notifications** nav item (requires the `viewSubmissions` permission).

- **Stat cards** — Total / Sent / Failed counts. Clicking a card filters the
  list by that status.
- **Per-send rows** — each row records when it was sent, the form, the
  notification's name (or *Legacy email*), a link to the submission, the
  recipients, the subject, and a Sent/Failed status with a failure message.
- **Filters** — a form dropdown plus the status cards; the list shows the 200
  most recent matching rows.

The log is **read-only** — there is no per-row resend or delete. (Failed
*integration* dispatches have their own log with resend, see
[Outbound integrations](integrations.md).) Logging is best-effort: a log-write
failure never blocks the email itself.

Rows are pruned on Craft's garbage-collection run after
**`retainNotificationLogsDays`** days (default **90**; `0` = keep forever) — the
*"Delete notification logs after (days)"* setting on the privacy settings tab.
A submission's detail screen also lists the log rows for that submission.

## Queued / async delivery

On a successful submission, notification sending is pushed to Craft's queue (a
`Sending form notifications` job). The job resolves recipients, renders bodies,
generates any PDF and reads any uploaded files **off-request**, so none of that
work runs in the visitor's submit request. The body/recipient/attachment logic is
identical whether it runs on the queue or inline.

For local debugging, the **Dispatch integrations synchronously** setting
(`dispatchIntegrationsSynchronously`) also forces notification sending to run
inline during the request, so any mailer errors surface immediately.
