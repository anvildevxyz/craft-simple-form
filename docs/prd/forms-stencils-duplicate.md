# PRD — Form Stencils/Presets + Duplicate-Form Action

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#138](https://github.com/fabianhaef/craft-simple-form/issues/138)

---

## 1. Problem Statement

Every new form starts from a blank slate. Building even a basic contact form means
manually adding Name, Email, Message fields, marking required, wiring an email
notification, and setting the recipient — every time, on every site. And there is no way
to base a new form on an existing one: a team that has a polished "support request" form
and wants a near-identical "sales request" form must rebuild it field by field.

Two adjacent gaps:

- **No duplicate.** The submission index has element actions
  (`src/elements/actions/SetSubmissionStatus.php`), but the *form* index has none. There
  is no "duplicate this form" anywhere.
- **No starting points.** Formie ships templates/stencils; Freeform ships demo templates.
  A first-run Simple Form gives the user an empty form and a builder, with no guided
  starting point.

Both are about the same primitive: **deep-copy a form definition into a new form.**
Duplicate copies from an existing form; a stencil copies from a built-in data template.

## 2. Goals

- **Duplicate form**: an element action (`src/elements/actions/`) + a button on the form
  edit screen that deep-copies a form — all fields, per-field `config` (JSON),
  conditional logic, per-site labels/help text/option labels, and its notification +
  integration *attachments* — into a brand-new form with a fresh, unique handle.
- **Stencils/presets**: a small set of built-in starters ("Contact", "Newsletter
  signup", "Event registration", "Support request") defined as data templates, and a
  "New form from stencil" CP flow that instantiates one.
- Correct **copy semantics**: new element id, new handle (collision-safe), new field ids
  (so conditional rules re-target the *copy's* fields), submission count/stats reset to
  zero, per-site content carried for every supported site.
- Multi-site safe and translatable; no breaking changes; PHPStan L7 + ECS clean.

## 3. Non-Goals (v1)

- Copying **submissions** — a duplicate/stencil is always empty of data.
- Copying **integration *secrets*** — duplicates reference the same global
  `IntegrationModel` rows (which live in `simpleform_integrations`, shared); no secrets
  are cloned because none live on the form.
- A user-authored "save this form as a custom stencil" library. Stencils are built-in
  (code) in v1; user-defined stencils are an open question.
- Cross-install stencil sharing — that is the job of the separate import/export PRD.
- Project-config representation of stencils.

## 4. Users & Use Cases

- **First-time user:** lands on an empty form index, clicks "New form from stencil →
  Contact", gets a ready-to-use Name/Email/Message form with an admin-notification
  pre-wired, and edits the recipient.
- **Power user iterating:** has a working event-registration form, duplicates it to build
  next quarter's event without disturbing the original or its submissions.
- **Multi-site editor:** duplicates a form on a multi-lingual install and gets all
  supported sites' labels/option labels carried into the copy.

## 5. Proposed Solution

### 5.1 A shared deep-copy service method

Add `FormStructureService::duplicate(Form $source, array $overrides = []): Form` (or a new
`FormCloneService` if `FormStructureService` gets heavy). It runs in one transaction and:

1. Creates a new `Form` element copying shared attrs (`name`, `propagationMethod`,
   `allowSaveResume`, `templatePath` if that PRD lands) and per-site attrs
   (`title`, `description`, `emailTo`, `emailSubject`, `emailReplyTo`, `emailBody`) for
   **every supported site** — reusing the same propagation rules as a normal save.
2. Generates a unique handle via a `uniqueHandle()` helper: `"{handle}-copy"`, then
   `-copy-2`, `-copy-3`, … checking `simpleform_forms.handle` until free. `name` gets a
   `" (copy)"` suffix (translated for stencils' display only; handle stays ASCII-safe).
3. Re-creates fields via the existing `FieldSyncService::sync()` path (not raw inserts):
   reads the source field rows (`FieldQueryHelper`/`FormStructureService::getFieldSet()`
   per site), maps each into the sync item shape (`type`, `handle`, `label`, `required`,
   `helpText`, `errorMessage`, `config`, option `siteLabel`s), and syncs them into the
   new form. New field rows get new ids; `sync()` already prunes/re-resolves conditional
   rules by **handle**, so as long as field handles are copied verbatim the conditional
   logic re-targets the copy's own fields correctly.
4. Copies **notification** rows: each source `NotificationModel`
   (`src/models/NotificationModel.php`) is re-saved with the new `formId`, new `uid`,
   preserved `sortOrder`, `recipientType`/`recipient`/`subject`/`replyTo`/`body`/
   `conditional`.
5. Copies **integration attachments**: rows in `simpleform_form_integrations` linking the
   source form to global `IntegrationModel`s are re-inserted for the new `formId`. The
   global integration definitions (and their encrypted secrets) are **not** cloned — both
   forms point at the same shared integration.
6. Invalidates the structure cache for the new form
   (`FormStructureService::invalidate()`), which the sync path already triggers.

Returns the saved new `Form`. Stats are inherently zero — submission count is derived from
`simpleform_submissions.formId`, and no submissions are copied.

### 5.2 Duplicate — element action + edit-screen button

- **Element action**: `src/elements/actions/DuplicateForm.php` extends
  `craft\base\ElementAction`, returned from `Form::defineActions()`. `performAction()`
  iterates the selected forms' ids and calls the duplicate service, setting a
  `Craft::t('simple-form', '{count} form(s) duplicated.')` message. For the common
  single-selection case it can redirect to the new form's edit screen.
- **Edit-screen button**: a "Save as a new form" action in the form edit screen
  (`src/templates/forms/edit.html`) hitting a new `FormsController::actionDuplicate()`
  that calls the service and redirects to the copy. CSRF-protected, permission-gated by
  the existing form-edit permission (`SimpleFormPermissions`).

### 5.3 Stencils — built-in data templates

Define stencils as plain PHP data (no element rows) under `src/stencils/` (or a
`StencilLibrary` provider class), each describing: a translated display `name` +
`description`, the field list in sync-item shape, and optional default notification(s).
Built-in set for v1:

| Handle | Fields | Default notification |
| --- | --- | --- |
| `contact` | Name (text, req), Email (email, req), Message (textarea, req) | admin alert, recipient = `{{ defaultEmail }}` |
| `newsletter` | Email (email, req), Consent (checkbox, req) | none |
| `event-registration` | Name, Email, Number of guests (number), Dietary notes (textarea), Attending? (select) | admin alert |
| `support-request` | Name, Email, Priority (select), Subject (text), Details (textarea) | admin alert + autoresponder (recipient = the Email field, via `NotificationModel::RECIPIENT_FIELD`) |

A `RegisterStencilsEvent` lets other code (or future user-defined stencils) contribute
entries — same pattern as `RegisterIntegrationTypesEvent`
(`src/events/RegisterIntegrationTypesEvent.php`).

### 5.4 "New form from stencil" CP flow

- On the form index (`src/templates/forms/index.html`), the "New form" button becomes a
  menu: **Blank form** (today's behaviour) + one item per registered stencil, with the
  stencil's translated name/description shown in the menu or a small picker modal.
- Selecting a stencil POSTs to `FormsController::actionNewFromStencil(handle)`, which
  instantiates via a `StencilService::create(stencilHandle): Form` that reuses the same
  field-sync + notification-copy plumbing as duplicate (the data source differs; the write
  path is shared). It generates a unique handle from the stencil handle and redirects to
  the new form's edit screen so the user can immediately tweak it.

### 5.5 Standards

- All stencil/UI strings via `Craft::t('simple-form', …)`; new keys added to all eight
  catalogs under `src/translations/`.
- Multi-site: copies carry every supported site's content; `getSupportedSites()` /
  `HasPropagation` rules from the source are honoured. Field option labels are copied
  per-site.
- No new tables required — duplicate/stencil reuse existing schema. No raw camelCase SQL
  introduced; any `Query` against `simpleform_form_integrations` uses `[[...]]`-quoted
  columns if hand-written.
- PHPStan L7 + ECS clean.

## 6. Acceptance Criteria

- [ ] `FormStructureService::duplicate()` (or `FormCloneService`) deep-copies a form in one
      transaction: new element id, unique handle, copied per-site content, copied fields
      with **new ids**, copied notifications, copied integration attachments.
- [ ] Conditional logic in the copy references the copy's own fields (re-targeted by
      handle), never the source's.
- [ ] The copy has **zero** submissions and a reset count; no source submissions are
      touched.
- [ ] No integration secrets are cloned; both forms reference the same global integration
      rows.
- [ ] `DuplicateForm` element action appears on the form index and duplicates each selected
      form; a "Save as a new form" button on the edit screen duplicates + redirects.
- [ ] Handle collisions resolve deterministically (`-copy`, `-copy-2`, …) and never
      collide with an existing handle.
- [ ] At least the four built-in stencils are registered and instantiable; a
      `RegisterStencilsEvent` allows contributing more.
- [ ] "New form" on the index offers Blank + each stencil; choosing one creates a working
      form (fields + default notification) and redirects to its edit screen.
- [ ] On a multi-site install, a duplicate carries every supported site's labels/option
      labels.
- [ ] PHPStan L7 + ECS clean; new translation keys in all catalogs.

## 7. Testing

### Unit / functional

- `DuplicateFormTest` — duplicate a fixture form (text + select with per-site option
  labels + a conditional rule + a file field): assert new ids throughout, unique handle,
  per-site content carried, conditional rule points at the copy's field handle, zero
  submissions.
- `UniqueHandleTest` — `-copy`/`-copy-N` generation, including when `-copy` already
  exists.
- `NotificationCopyTest` — fixed + field-recipient notifications copied with new
  `formId`/`uid` and preserved `conditional`/`sortOrder`.
- `IntegrationAttachmentCopyTest` — `simpleform_form_integrations` rows re-pointed to the
  new form; the global `IntegrationModel` (and its encrypted settings) untouched.
- `StencilLibraryTest` — every built-in stencil's field list is valid (known types,
  unique handles) and instantiates without error; `RegisterStencilsEvent` adds an entry.

### craft-smoke-test scenarios

- "Duplicate from index": select a form, run the Duplicate action, assert a new form
  appears with `-copy` handle, identical fields, and 0 submissions, while the original
  keeps its submissions.
- "Duplicate from edit screen": open a form, click "Save as a new form", assert redirect
  to the copy's edit screen and that conditional logic still works when previewed.
- "New from Contact stencil": click New form → Contact, assert Name/Email/Message fields
  exist and an admin notification is pre-wired; submit the rendered form and assert a
  submission + email.
- "Stencil handle collision": create a stencil-based form twice; assert the second gets a
  distinct handle and both work.

## 8. Open Questions

- **User-defined stencils** (save an existing form as a reusable stencil): defer to a
  follow-up, or fold in now via a `simpleform_stencils` table? Lean: built-in only for
  v1; user stencils are better served by the import/export PRD.
- Should duplicate copy **integration attachments** by default, or prompt? A copied form
  silently firing the same webhook/CRM push may surprise. Lean: copy attachments but with
  the form's master `enabled` honoured; revisit a "copy without integrations" toggle.
- For autoresponder stencils (`RECIPIENT_FIELD`), how do we reference "the email field"
  before its handle is known? Lean: stencils declare the recipient by the *stencil's*
  field handle, resolved after the fields are created.
- Does the stencil picker warrant a modal with previews, or is a menu enough for v1? Lean:
  menu in v1, modal if the set grows.
- Should the Duplicate action also be exposed as a console command (`simple-form/forms`)
  for scripted setup? Lean: nice-to-have, defer to the import/export console surface.
