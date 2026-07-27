# Form Availability

Control **when** a form accepts submissions, **how many** it accepts, and **who**
may submit it — all per form, all **enforced server-side**. The AJAX submit path,
the no-JS POST path, and the GraphQL `submitForm` mutation share the same checks,
so a closed or gated form can't be bypassed by a crafted POST or a stale cached
page. The rendered form already shows the right notice; the server makes the
actual decision.

These settings live on the form edit screen under two sections:

- **Availability** — the submission window and total quota.
- **Access & limits** — login requirement, per-user limits, and duplicate
  prevention (covered in [Spam protection](spam-protection.md)).

Open a form at **Simple Form → Forms → (your form)** to find them. Leave any field
blank for "no limit".

---

## Submission window (open / close dates)

> **Availability → Open date / Close date**

- **Open date** (`openDate`) — submissions are **rejected before** this date.
  Leave blank to open immediately.
- **Close date** (`closeDate`) — submissions are **rejected after** this date.
  Leave blank to stay open indefinitely.

If you set both, the close date must be after the open date (validated on save).

When the form is outside its window, visitors see the **closed message** in place
of the form instead of the inputs.

### Closed message

> **Availability → Closed message** (`closedMessage`, translatable)

Shown in place of the form when it is closed (not yet open, ended, or full). Leave
it blank to use a built-in default that adapts to *why* the form is closed:

| Reason | Default message |
|--------|-----------------|
| Not open yet | "This form is not open for submissions yet." |
| Ended / closed | "This form is no longer accepting submissions." |
| Quota reached | "This form has reached its submission limit." |

Because the message is **translatable**, you can localize it per site.

---

## Submission quota (total cap)

> **Availability → Submission limit** (`submissionLimit`)

Stop accepting submissions once this many have been received. The form closes when
the count **reaches** the limit (the Nth submission is accepted, the N+1th is
rejected). Spam-flagged submissions don't count toward the cap. Leave blank for
unlimited.

The edit screen shows a live tally (`12 / 100 submissions`, or just
`12 submissions so far` when there's no limit) so you can see how close you are.

> **Note — soft cap.** The count → save step is not atomic, so under heavy
> concurrent traffic a form may slightly exceed its limit (two simultaneous
> requests can both read N−1 and both save). The quota is a **soft business
> limit**, not a hard inventory lock — a small over-count is accepted. If you need
> a strict cap (e.g. limited tickets), don't rely on this alone.

---

## Login required

> **Access & limits → Require login to view & submit** (`requireLogin`)

When enabled, only logged-in users can view or submit the form:

- **Guests** see a notice with a **login link** instead of the form.
- **Anonymous submissions are rejected server-side**, even if a guest crafts the
  POST directly.

### Login-required message

> **Access & limits → Login-required message** (`loginRequiredMessage`,
> translatable)

Shown (with a login link) in place of the form when login is required. Leave blank
for the default ("Please log in to submit this form.").

---

## Per-user submission limit

> **Access & limits → Submissions per user** (`submissionsPerUser`)

The maximum number of times a single user may submit this form (e.g. `1` = once
per user). Leave blank for unlimited. Spam-flagged rows never count toward a user's
allowance.

### How "per user" is keyed

- **Logged-in users** are keyed on their stable **user id**. This is reliable: the
  same account can't exceed its allowance regardless of browser or session.
- **Guests** are keyed by the **Limit guests by** (`guestLimitKey`) option:
  - **Don't limit guests** (`none`, default) — the per-user limit simply doesn't
    apply to logged-out visitors.
  - **Email field value** (`email`) — matches the form's email field value against
    prior submissions. This is **best-effort, not a security control** (a visitor
    can type a different email), so treat it as a convenience deterrent rather than
    a hard gate.

When a user is at or over their limit, the form is replaced by the limit-reached
message.

### Limit-reached message

> **Access & limits → Limit-reached message** (`userLimitMessage`, translatable)

Shown instead of the form when a user has reached their limit. Leave blank for the
default ("You have already submitted this form.").

### Submissions are associated with the logged-in user

Every submission stores the **id of the logged-in user** who made it (guests store
none). This is what powers the per-user limit, and it also lets you trace a
submission back to an account in the Control Panel and via GraphQL/MCP.

---

## Duplicate prevention

Also under **Access & limits**, the **Prevent duplicate submissions** controls let
you flag or block repeat submissions within a time window. Because duplicate
handling is part of the anti-spam stack (it reuses the global flag/block mode and
the spam quarantine), it's documented in
[Spam protection → Duplicate-submission prevention](spam-protection.md#duplicate-submission-prevention-per-form).

---

## Everything is server-enforced

Each availability and access check runs in one place on the server, *before*
CAPTCHA and validation, so it applies identically to:

- the default **AJAX** submit,
- the **no-JS POST** fallback, and
- the **GraphQL** `submitForm` mutation.

The template-level notices (closed message, login prompt, limit message) are a
courtesy for visitors. A submission that gets past them — through a cached page, a
hand-built request, or a headless client — is still rejected by the server. The
gate is authoritative.
