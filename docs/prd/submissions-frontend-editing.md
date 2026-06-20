# PRD — Front-End Submission Editing (Token + Twig / GraphQL)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#144](https://github.com/fabianhaef/craft-simple-form/issues/144)

---

## 1. Problem Statement

Once a visitor submits a Simple Form, the submission is immutable from the front end. Real
workflows need the submitter to come back and **view or edit** what they sent: correct a typo
in an RSVP, update their address on a registration, revise a multi-step application before a
deadline, or simply review a confirmation. Today the only way to change a stored submission is a
CP editor doing it by hand.

Front-end submission editing is a feature lightweight competitors don't offer and the Pro tiers
charge for. The core machinery already exists — `SubmissionService::submit()` is a single,
transport-agnostic entry point shared by the Twig controller and the GraphQL mutation, and the
`craft.simpleForm.*` variable already renders forms and queries submissions. This PRD adds a
secure way to **re-render a form pre-filled with an existing submission** and **re-save** it
through the same validated path, with conditional logic, spam protection, and an edit-window
honored.

## 2. Goals

- Let an **authenticated user** edit their own submission (matched on `Submission::$userId`).
- Let an **anonymous submitter** edit via a **secure, expiring tokenized link** (no login).
- Re-render the form **pre-filled** with the submission's stored values, including respecting
  conditional logic against the existing values.
- **Re-validate + re-save** through `SubmissionService` — one code path for create and edit, so
  validation, conditional logic, and spam protection stay identical.
- A **Twig API** to render an edit form (`craft.simpleForm.editForm(...)`) and a **GraphQL
  `updateSubmission` mutation**.
- **Audit-log** every edit via `AuditService`.
- A **per-form toggle** gating whether editing is allowed at all, plus an **edit window**
  (how long after submission edits are accepted).

## 3. Non-Goals (v1)

- No partial / field-level edit permissions — edit-allowed forms allow editing the whole form.
- No edit history / diff viewer / versioning (only an audit-log line that an edit happened).
- No re-triggering of integrations or notifications on edit by default (Open Question — opt-in
  at most).
- No admin-issued "edit links" UI in v1 beyond the autoresponder/template exposing the token.
- No changing the *form structure* mid-edit (a field added/removed after submission is handled
  gracefully but not specially surfaced).
- No editing of soft-deleted / trashed submissions.

## 4. Users & Use Cases

- **Anonymous RSVP guest**: clicks an "Edit your RSVP" link in their confirmation email, the
  form re-renders pre-filled, they change "+1: no" to "+1: yes", save — no account needed.
- **Logged-in member**: visits a "My submission" page that renders their registration form
  pre-filled and editable.
- **Applicant**: revises an application up to the deadline (edit window), after which the link
  stops working.
- **Headless front end (GraphQL)**: a Next.js app fetches a submission, renders a form, and
  calls `updateSubmission` with the token to persist edits.
- **Admin / auditor**: sees in the audit log that submission #123 was edited from the front end
  at a given time.

## 5. Proposed Solution

### 5.1 Per-form gating (`Form` element)

Following the established per-form boolean precedent (`allowSaveResume` +
`m260620_000003_form_allow_save_resume`):

- `Form::$allowEditing` (bool, default false).
- `Form::$editWindowMinutes` (int, default 0 = unlimited while allowed).
- Migration `m26062x_000001_form_allow_editing` adds both columns. New columns only — no
  breaking change. Register in `codeception.yml` + reset `craft_test` (test-DB snapshot
  reference).

### 5.2 Edit tokens

A `SubmissionEditToken` mechanism (no need for a new element — store token state on/with the
submission):

- **Generation**: an HMAC/random token bound to the submission id + a per-submission secret +
  an expiry, issued by `SubmissionService`/a `SubmissionEditTokenService`. Exposed to templates
  and the autoresponder so an "edit link" can be embedded (e.g.
  `craft.simpleForm.editUrl(submission)`).
- **Storage/verification**: store a hash of the token (not the token itself) so a DB leak can't
  reissue links; verify by recomputation. Token carries an absolute expiry; verification also
  re-checks the form's `editWindowMinutes` against `Submission::dateCreated` so the window is
  authoritative even if a token's intrinsic expiry is longer.
- **Security**: constant-time comparison; single-purpose (edit only, scoped to one submission);
  rotates/invalidates on successful save if configured; never logged in plaintext; not exposed
  via GraphQL types or MCP output.
- **Authenticated path**: a logged-in user editing a submission whose `userId` matches the
  current user needs **no** token (still gated by `allowEditing` + window).

### 5.3 Authorization matrix

An edit request is authorized iff the form has `allowEditing` **and** the edit window is open
**and** one of:
- the request carries a valid, unexpired token for that submission, **or**
- the current user is logged in and `Submission::$userId === currentUser.id`.

Otherwise: 403 (Twig) / an authorization error payload (GraphQL). Anonymous submissions with no
`userId` are editable **only** via token.

### 5.4 Twig API

```twig
{# Render an edit form for a tokenized link #}
{{ craft.simpleForm.editForm(submission, { token: craft.app.request.getParam('t') }) }}

{# Or, for a logged-in user, resolve their submission and render editable #}
{{ craft.simpleForm.editForm(submission) }}
```

- `craft.simpleForm.editForm(submission|id, options)` re-renders the form's markup (reusing the
  existing render pipeline / `src/web/assets/form` asset bundle) with each field pre-filled from
  the submission's stored `data`, an `edit` mode flag, the submission id, and a CSRF-protected
  hidden token. Conditional logic evaluates against the existing values on first render.
- `craft.simpleForm.editUrl(submission)` returns a tokenized edit URL for emails/links.
- A new controller action `submissions/update` (front-end) receives the post, re-checks
  authorization, and routes through the shared save path.

### 5.5 Save path — reuse `SubmissionService`

Add `SubmissionService::update(Submission $submission, array $values, array $context): array`
that mirrors `submit()`:
- Resolves field values, evaluates conditional visibility, validates visible fields, rebuilds
  the `data` payload — **identical** logic to `submit()` (factor the shared core so create/edit
  can't drift).
- Re-runs **spam protection** on the edited content (an edit must not be a spam-laundering
  bypass): honeypot + captcha (with the existing GraphQL bypass rules) + denylists/Akismet as
  configured. A spam verdict on edit is handled like a new submission (block or flag).
- Honors the **edit window** and `allowEditing` server-side (never trust the client).
- Saves the **same** element (preserving id, `dateCreated`, `siteId`, `userId`), then fires an
  `EVENT_AFTER_SUBMISSION_SAVE` with an `isNew = false` flag so listeners (integrations) can
  distinguish edits and self-skip if they shouldn't re-dispatch.
- **Multi-site**: the submission is edited on its own `siteId`; the form/field resolution uses
  the submission's site.

### 5.6 GraphQL `updateSubmission` mutation

In `FormMutations`, add `updateSubmission(id, token, values, siteId)` mirroring `submitForm`:
- Gated by a schema component (e.g. `simpleFormSubmissions:edit`).
- Re-checks token/owner authorization and routes through `SubmissionService::update()` — same
  validation/spam/conditional logic, same rate limiting (`isRateLimited`).
- Returns the updated submission payload or a structured error (auth / validation / spam),
  never leaking the token or secret.

### 5.7 Audit logging

Every successful edit writes `AuditService::log('submission.edit', 'submission', $id, 'edited via front-end (token|user)')`,
including the actor (anonymous-via-token vs. user id) — consistent with the existing
status-change audit entries.

## 6. Acceptance Criteria

- [ ] Per-form `allowEditing` + `editWindowMinutes` render in the CP and persist; editing is
      refused entirely when `allowEditing` is off.
- [ ] `craft.simpleForm.editForm(submission, {token})` re-renders the form pre-filled with stored
      values and correct conditional-logic visibility.
- [ ] A valid token authorizes an anonymous edit; an expired/invalid/tampered token is refused
      (403); a token for a different submission is refused.
- [ ] A logged-in user can edit a submission whose `userId` matches them, with no token; another
      user (or anonymous, no token) cannot.
- [ ] Edits route through the shared save core: validation + conditional logic + spam protection
      behave identically to create; a spam edit is blocked/flagged per settings.
- [ ] The edit window is enforced server-side; an edit after the window is refused even with a
      valid token.
- [ ] A successful edit updates the same submission element (id/dateCreated preserved) and fires
      `EVENT_AFTER_SUBMISSION_SAVE` with `isNew = false`.
- [ ] The GraphQL `updateSubmission` mutation performs the same authorized, validated edit and
      is gated by its schema component.
- [ ] Every edit writes an `AuditService` entry recording the actor.
- [ ] Tokens are stored hashed, compared in constant time, never exposed via GraphQL/MCP, never
      logged in plaintext.
- [ ] Multi-site correct; PHPStan L7 + ECS clean; strings via `Craft::t('simple-form', …)`.

## 7. Testing

### Unit
- Token issue → verify (valid), expired, tampered, wrong-submission → all correctly accepted/
  rejected; constant-time comparison used; stored value is a hash.
- Authorization matrix: token-only, user-owner, mismatched user, anonymous-no-token,
  editing-disabled, outside-window — each returns the right allow/deny.
- `SubmissionService::update()` mirrors `submit()`: shared core asserted (same validation /
  conditional / spam outcomes for identical input); spam edit blocked/flagged.
- Save preserves id/dateCreated/siteId/userId and fires the after-save event with `isNew=false`.
- GraphQL `updateSubmission`: schema-component gating, auth rejection payload, validation error
  payload, success payload — none leak the token.

### craft-smoke-test scenarios
1. Enable editing on a form; submit anonymously; open the tokenized edit URL (from the
   autoresponder/template); verify the form is pre-filled; change a value; save; verify the
   stored submission updated and an audit entry exists.
2. Tamper with the token in the URL; verify a 403 and no change to the submission.
3. Set a 10-minute edit window; submit; verify edit works inside the window; fast-forward (or
   craft a stale token); verify the edit is refused after the window.
4. As a logged-in user, edit your own submission with no token; verify success; attempt to edit
   another user's submission; verify refusal.
5. Edit a submission so its new content trips a spam denylist; verify the edit is flagged/blocked
   per the spam settings (no laundering bypass).
6. Perform the same edit via the GraphQL `updateSubmission` mutation with a valid token; verify
   success and that the response contains no token/secret.

## 8. Open Questions

- On edit, should integrations **re-dispatch** and notifications **re-send**? Default: no
  (the `isNew=false` event flag lets listeners self-skip). Consider a per-form / per-integration
  "dispatch on edit" opt-in.
- Token lifetime model: a single long-lived token embedded in the confirmation email, vs. a
  short-lived token re-minted on each edit-page render? Leaning: email carries a token whose
  *effective* lifetime is bounded by `editWindowMinutes`, so the window is the real control.
- Should an edit bump `dateUpdated` only, or also record a `dateLastEdited` / edit count for
  display? Audit-log line covers the minimum; a counter is a small add.
- Interaction with **save & resume drafts** (`allowSaveResume`) — a submitted form that also had
  a draft: clarify that editing operates on the final submission, not the draft.
- Should an Assets/file field be re-uploadable on edit, or locked to the original upload? Leaning
  re-uploadable (runs through the same `AssetUploadService`), but confirm GC/orphan handling for
  the replaced asset.
