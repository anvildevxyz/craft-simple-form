# Managing Submissions

Every form submission is a **native Craft element**, so it lives in a familiar
element index with sources, statuses, search, bulk actions and exporters — plus
soft-delete/restore, data-retention housekeeping, an analytics dashboard,
dashboard widgets, an audit log, and optional front-end editing.

Viewing submissions requires the **View submissions** permission
(`viewSubmissions`); changing them (status, delete, approve-from-spam) also
requires **Manage submissions** (`manageSubmissions`).

## The submissions index

Open **Simple Form → Submissions**. The index lists submissions for the current
site, newest first.

### Sources and filtering

- **All Submissions**, plus one source **per form**.
- A **Trash** heading with a **Trashed** source for soft-deleted submissions.
- Filters: **form**, **status**, full-text **search**, and a **date range**
  (`dateFrom` / `dateTo`, validated as `YYYY-MM-DD`).
- Paginated (default 50 per page; capped at 500).

### Statuses

Each submission carries a read status:

| Status | Meaning |
|--------|---------|
| **New** | Default for a fresh submission. |
| **Read** | Reviewed. |
| **Archived** | Kept but cleared from the working list. |
| **Spam** | Flagged by the spam check (Akismet, a denylist hit, a duplicate, or set manually). |

`New → Read → Archived` form a cycle you can advance with the inline status
toggle (Spam is excluded from the cycle — it's set by the spam check). The
filter additionally offers **all**. A spam submission can be **approved** back to
**New** (clearing its spam reason) from its detail screen.

## Submission approval workflow

Beyond the read statuses above, submissions can move through an **owner-defined
approval pipeline** — e.g. *Submitted → In review → Approved / Rejected*. Off by
default; configure it under **Simple Form → Settings → Workflow** (needs
`manageSettings`):

1. Toggle **Enable Workflow**.
2. Add ordered **Stages** — each has a label, a slugified handle, and a color.
   New (non-spam) submissions automatically enter the **first** stage.
3. Add **Transitions** — each allows a move *From* one stage *To* another, with
   an optional **button label** and optional **allowed user groups** (leave all
   groups unchecked to allow any submission manager; admins always may).

The pipeline lives in the plugin settings (project config), so it deploys with
the project: `enableWorkflow`, `workflowStatuses`, `workflowTransitions` — see
the [Settings reference](reference/SETTINGS.md).

### Working the pipeline

- A submission's detail screen shows a **Workflow** block with its current stage
  and **one button per transition** allowed from that stage for the current user
  (needs `manageSubmissions`; per-transition group limits are re-checked
  server-side).
- The submissions screen can filter by stage (`?workflow=<handle>`).
- Every transition is **audit-logged** (`workflow: <from> → <to>`).

The workflow stage is **independent of the read status** (new/read/archived/
spam): a submission can be *read* and *In review* at once, and the spam queue is
unaffected. Submissions created before the workflow was enabled simply have no
stage.

The plugin sends nothing on a transition by itself — hook
`EVENT_SUBMISSION_TRANSITIONED` to notify people or dispatch integrations; see
[Twig & developer API](twig-and-api.md#events).

## Bulk actions

Select submissions in the index and apply a bulk **element action**:

- **Mark as read**
- **Archive**
- **Mark as spam**
- **Delete** (Craft's native soft-delete)

## Export

Two export paths, both producing one column per field label plus submission
metadata:

- **Native element exporter.** From the index **Export** menu, choose
  **Submissions (with field columns)**. Craft renders the rows to the format you
  pick (CSV/JSON/XML) and honors the current selection/criteria.
- **Filtered CSV.** The submissions screen's own CSV export streams a
  `submissions.csv` honoring the active form/status/search/date filters.

There's also a console-driven import/export for whole forms — see
[Import / Export](import-export.md).

## Trash and restore (soft-delete)

Deleting a submission is a **recoverable soft-delete**. Deleted submissions move
to the **Trashed** source, where Craft offers **Restore** and
**Delete permanently**. They remain recoverable until they age out of the
retention window (below) or are permanently deleted.

## Data retention & GDPR

Simple Form prunes old data on Craft's **garbage-collection** run. Every
threshold is **opt-in** — `0` days means *keep forever*. Configure these in the
plugin settings:

| Setting | Default | Purpose |
|---------|---------|---------|
| `retainSubmissionsDays` | `0` (keep) | Prune submissions older than N days. |
| `retainSpamDays` | `30` | Prune spam-flagged submissions older than N days, independently of `retainSubmissionsDays`. `0` = keep forever. |
| `retainIntegrationLogsDays` | `90` | Prune integration dispatch-log rows. |
| `retainNotificationLogsDays` | `90` | Prune [notification-log](notifications.md#the-notification-log) rows. |
| `retainAuditLogDays` | `365` | Prune audit-log rows. |
| `draftRetentionDays` | `30` | Drop unfinished save-&-resume drafts (each save refreshes the expiry). |
| `partialRetentionDays` | `7` | Drop [passively-captured partials](building-forms.md#passive-partial-capture-abandoned-attempts). |
| `anonymizeInsteadOfDelete` | `false` | Anonymize submissions instead of deleting them. |

### Answering a subject request

Retention handles the routine pruning. A named individual asking for their data —
or asking you to delete it — is a separate job, and there are two commands for it.
Both match on the submitted email address across every form and site.

```bash
# Subject access: everything this person has ever submitted, as CSV
php craft simple-form/submissions/export-by-email --email=person@example.com --out=subject.csv

# Right to erasure: see what would go first…
php craft simple-form/submissions/erase-by-email --email=person@example.com --dryRun

# …then do it
php craft simple-form/submissions/erase-by-email --email=person@example.com
```

Erasure follows the `anonymizeInsteadOfDelete` setting: on, the rows are scrubbed
in place so counts and analytics stay meaningful; off, they are deleted. Pass
`--anonymize` to force scrubbing for one request regardless of the setting.

Omitting `--out` on the export writes the CSV to stdout, so it can be piped.

### How much of an IP address to store

For GDPR data minimization, **IP capture** (`ipCapturePolicy`) is a three-state
choice rather than an on/off switch:

| Policy | What is stored |
|--------|----------------|
| `full` (default) | The visitor's IP address as received. |
| `anonymized` | A masked address — the last IPv4 octet, or the low 80 bits of an IPv6 address, are zeroed **before** storage, so the stored value can't identify a single host. |
| `off` | Nothing. The IP is never written to the submission. |

Rate limiting keeps working under every policy: it reads the request IP
transiently and persists nothing beyond the window. IP-based duplicate detection
degrades to its other keys whenever IPs aren't captured in full.

The older boolean **Collect IP addresses** setting (`collectIpAddresses`) is
still honored for backward compatibility — `false` maps to `off` and `true` to
`full` — but `ipCapturePolicy` supersedes it.

### Anonymize instead of delete

With **Anonymize instead of delete** on, an expiring submission is **scrubbed in
place** rather than removed: its PII-bearing `data` and submitting `userId` are
nulled, but the row, its read status and the element survive — so aggregate
counts and analytics stay meaningful. Whether deleting or anonymizing, any
**assets** referenced by file/signature fields are also deleted, so an image
never outlives the submission it belonged to.

## The Dashboard

**Simple Form → Dashboard** is the plugin's landing page (clicking the
top-level *Simple Form* nav item lands here). It gives a one-glance answer to
"what happened, and what needs me?", scoped to the **current CP site**:

- **Stat cards** — Forms, total Submissions, Last 7 days, and Spam blocked;
  each card links to the matching screen/filter.
- **Needs attention** — a warning note listing *new submissions to review* and
  *failed integration dispatches*, each linking to the filtered list. Shown
  only when there is something to act on.
- **Submissions over time** — a daily bar chart over a fixed trailing 30-day
  window with a real date axis, plus a **By weekday** breakdown of the same
  window.
- **Recent submissions** — the latest 8 (spam excluded), each linking to its
  detail screen.
- **Top forms** — the five busiest forms, linking to their filtered submissions.

Viewing the Dashboard requires `viewSubmissions` (users without it are
redirected to the screen they *can* use). The numbers come from the same
reporting service as the [Analytics dashboard](#analytics-dashboard) below, so
the two always agree; use Analytics for selectable ranges and per-field detail.

## Analytics dashboard

**Simple Form → Submissions → Analytics** (read-only; needs `viewSubmissions`)
shows, for the current site and optionally one form:

- **Status breakdown** — counts of total / new / read / archived / spam.
- **Spam rate** — spam vs. ham split.
- **Submissions per day** — a zero-filled daily trend over a selectable range
  (7 / 30 / 90 days).
- **Per-form totals** — submission counts grouped by form, highest first.
- **Integration dispatch health** — success / failed / pending counts across the
  dispatch log.
- **Rating/opinion scales** — when a single form is selected, per-field response
  count, average, and distribution for rating/opinion-scale fields.

## Dashboard widgets

Two Craft dashboard widgets (both honor `viewSubmissions`, scoped to the current
site, optionally to one form):

- **Form Submissions** — a single count over a selectable range
  (Today / Last 7 days / Last 30 days / All time).
- **Recent Submissions** — a small table of the most recent submissions
  (configurable count, default 5), each linking to its detail screen.

## Audit log

> **Standard edition.** The audit log requires [Standard](editions.md). See [Editions](editions.md).


Simple Form keeps an **append-only audit trail** of who changed what — forms,
fields, integrations, notifications and submission statuses. Each entry records
the acting user, an action, a target, a short summary and a timestamp. Logging
is best-effort: an audit-write failure never breaks the operation being recorded.
Audit rows are pruned per `retainAuditLogDays`.

## Front-end submission editing

A submitter can re-open and edit their own submission from the front end, when
the form allows it. Edits route through the **same validation, conditional-logic
and spam-protection path as a create**, and are **audit-logged**.

### Enabling editing on a form

On the form, turn on **Allow editing** (`allowEditing`) and optionally set an
**edit window** in minutes (`editWindowMinutes`; `0` = unlimited while editing is
allowed). The window is always enforced **server-side** against the submission's
creation time — the client is never trusted.

### Secure tokenized edit links

For anonymous submitters, editing is authorized by a **secure tokenized link**:

- The token is a high-entropy random string; only its **SHA-256 hash** is stored
  (on the submission row). The plaintext token lives **only in the edit URL**, so
  a database read alone can't reissue a working link.
- Verification is **constant-time** and the token is **bound to one submission**.
- The token's expiry tracks the form's edit window; re-issuing **rotates** the
  token, invalidating any prior link.
- A logged-in user who **owns** the submission can edit without a token.

The token is never logged in plaintext and never exposed via GraphQL or MCP.

### Twig API

Build the edit page with `craft.simpleForm.*`:

```twig
{# Render an editable, pre-filled copy of the submission's form. #}
{# Pass the token from the URL for the anonymous path; an owner needs none. #}
{{ craft.simpleForm.editForm(submission, { token: craft.app.request.getParam('t') }) }}
```

```twig
{# Build a tokenized edit URL, e.g. for an autoresponder email. #}
{# Issues (or rotates) the token and appends `id` + `t` query params. #}
{% set url = craft.simpleForm.editUrl(submission) %}
```

`editUrl()` returns `null` when the form doesn't allow editing or no edit path is
configured. The path comes from the argument or the **Edit path** setting
(`editPath`), e.g. `forms/edit-submission` — the site path of the template that
renders `editForm()`.

### GraphQL

Headless clients edit via the **`updateSubmission`** mutation, gated by the
`simpleFormSubmissions:edit` schema component. It takes the submission `id`,
the secure `token` (omit it only for an authenticated owner), the edited
`values`, plus optional `honeypot` / `captchaToken`. Like the Twig path, it
re-validates through the same core and never leaks the token in its payload.

```graphql
mutation Edit {
  updateSubmission(
    id: 42
    token: "…"
    values: [{ fieldId: 100, value: "Updated answer" }]
  ) {
    success
    errors { key messages }
  }
}
```
