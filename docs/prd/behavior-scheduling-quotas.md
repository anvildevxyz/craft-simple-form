# PRD — Form scheduling (open/close dates) + submission quotas

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#134](https://github.com/fabianhaef/craft-simple-form/issues/134)

---

## 1. Problem Statement

A Simple Form is always open. There is no way to say "this form accepts submissions only
between two dates" or "stop after N submissions". Event signups, limited-seat workshops,
giveaways, and time-boxed surveys all need this, and authors currently bolt it on with
template `{% if %}` logic that the **server never enforces** — a crafted POST or a stale
cached page still submits.

This is a genuine differentiator: among Craft form plugins, only Freeform ships
scheduling + quotas. Building it cleanly (enforced in the shared submit path, honored by
AJAX **and** GraphQL, with a configurable translatable closed message) is a clear win.

## 2. Goals

- Per-form optional **open date** and **close date**; the form accepts submissions only
  within `[open, close]` (either bound optional → open-ended on that side).
- Per-form optional **total submission cap** (`submissionLimit`); once reached, the form is
  closed.
- When closed (out of window, over quota, or manually disabled), the public render shows a
  **configurable, translatable "closed" message** instead of the form.
- Reject out-of-window / over-quota submissions **server-side** in
  `SubmissionService::submit()`, so the AJAX path, the no-JS POST path, and the GraphQL
  `submitForm` mutation are all covered by one check.
- Efficient quota counting (no full table scan per submit) with a documented race-safety
  note.

## 3. Non-Goals (v1)

- Per-field or per-option quotas (e.g. "max 10 for the Saturday slot"). Total cap only.
- Waitlists / overflow handling.
- Recurring schedules (open every Monday). Single window only.
- Per-user quotas — that is the **login & per-user limits** PRD; this PRD is the
  form-wide total.
- Timezone pickers — dates use Craft's configured timezone like every other Craft date.

## 4. Users & Use Cases

- **Event organizer**: "Registration opens 1 July, closes 31 July, max 200 seats."
- **Marketer**: "Giveaway entries close at midnight on the 15th."
- **Researcher**: "Survey accepts the first 500 responses then closes automatically."
- **Visitor** arriving after close / when full: sees a clear message, not a broken or
  silently-failing form.

## 5. Proposed Solution

### 5.1 New Form fields (element + storage)

All on the **shared** `simpleform_forms` row (structural, not translatable) **except** the
closed message, which is per-site content.

| Property | Type | Storage | Translatable |
| --- | --- | --- | --- |
| `openDate` | `?DateTime` | `simpleform_forms` (datetime) | no |
| `closeDate` | `?DateTime` | `simpleform_forms` (datetime) | no |
| `submissionLimit` | `?int` | `simpleform_forms` | no |
| `closedMessage` | `?string` | `simpleform_forms_sites` | yes (per-site) |

`closedMessage` falls back to a translatable default
(`Craft::t('simple-form', 'This form is no longer accepting submissions.')`) when blank.

Migration `mYYMMDD_000001_form_scheduling` adds the columns (`->after('allowSaveResume')`
for the shared ones). Use `$this->dateTime()` for the date columns; `[[...]]`-quote any
camelCase identifier in raw SQL. Register in `codeception.yml`, reset `craft_test`.

`defineRules()`:

```php
$rules[] = [['openDate', 'closeDate'], 'datetime'];
$rules[] = [['submissionLimit'], 'integer', 'min' => 1];
$rules[] = [['closedMessage'], 'string'];
$rules[] = ['closeDate', 'compare', 'compareAttribute' => 'openDate', 'operator' => '>=',
            'when' => fn() => $this->openDate && $this->closeDate];
```

Extend `afterSave()`: dates + limit into `$shared`, `closedMessage` into `$siteRow`,
keeping the existing propagation guards.

### 5.2 Open-state helper on the Form element

```php
/** Form-level availability, independent of the visitor. */
public function isAcceptingSubmissions(): bool
{
    $now = new \DateTime();
    if ($this->openDate && $now < $this->openDate)  return false;
    if ($this->closeDate && $now > $this->closeDate) return false;
    if ($this->submissionLimit !== null
        && $this->getSubmissionCount() >= $this->submissionLimit) return false;
    return true;
}

/** Reason the form is closed, for the right message/telemetry. */
public function getClosedReason(): ?string // 'not_yet'|'ended'|'full'|null
```

`getSubmissionCount()` returns a cheap, **count-only** query:

```php
return (int) Submission::find()
    ->formId($this->id)
    ->siteId('*')                 // count across all sites (forms are localized)
    ->status(null)                // count everything except spam (see below)
    ->count();
```

Count semantics: **exclude spam** submissions from the quota (a spam row should not burn a
seat). Implement as a count on `readStatus != SPAM`. Decide whether disabled/trashed rows
count (proposed: exclude trashed, include all live non-spam). Cache the count for the
duration of the request only.

### 5.3 Server-side enforcement (the differentiator)

Add a guard near the top of `SubmissionService::submit()`, **after** the honeypot drop
(so bots still get no signal) but **before** captcha/validation:

```php
if (!$form->isAcceptingSubmissions()) {
    $msg = $this->closedMessageFor($form); // per-site or default
    return ['submission' => null, 'errors' => ['form' => [$msg]]];
}
```

Because both `SubmitController` and `FormMutations::resolveSubmit` route through
`submit()`, the AJAX path, no-JS POST, and GraphQL are all covered by this single check —
no duplicated logic.

- **SubmitController**: the existing `!empty($result['errors'])` branch already returns the
  error envelope; the JS surfaces the `form` key as a general banner (it has no matching
  input). Optionally return HTTP 403 for an over-quota/closed submit for cleaner semantics.
- **GraphQL**: `formatErrors()` already maps `form => [...]` into the payload `errors`
  list; no change needed beyond the shared guard firing.

### 5.4 Quota race-safety note

The count → save is not atomic; under concurrent submits a form could slightly exceed
`submissionLimit` (e.g. two requests both read N-1 then both save). For v1 this is
**accepted and documented** — the cap is a soft business limit, not a hard inventory lock.
Mitigations considered for a later iteration:

- A post-save re-check that soft-disables the form once the count is reached.
- A DB-level advisory lock / `INSERT ... SELECT count` guard keyed on `formId`.

Documented explicitly so reviewers don't treat the soft cap as a bug.

### 5.5 Public render (TwigExtension + macro)

`TwigExtension::renderForm` checks availability before emitting the `<form>`:

```php
if (!$form->isAcceptingSubmissions()) {
    return '<div class="simple-form simple-form--closed" role="status">'
         . htmlspecialchars($this->closedMessageFor($form)) . '</div>';
}
```

This means a page cached *before* the close date still posts to a server that rejects it
(5.3), and a fresh render shows the closed message — both correct. The closed node carries
a class so authors can style it.

### 5.6 CP UI (`src/templates/forms/edit.html`)

A new "Availability" section:

- `forms.dateTimeField` — Open date (optional).
- `forms.dateTimeField` — Close date (optional).
- `forms.textField` (number) — Submission limit (optional; blank = unlimited).
- `forms.textareaField` — Closed message (per-site; shown when any of the above is set,
  but always editable).

A small read-only hint shows the **current submission count** vs the limit (e.g.
"143 / 200 submissions") so the author sees headroom at a glance. `FormsController::actionSave`
maps the new params (parse the date fields with `craft\helpers\DateTimeHelper::toDateTime`,
mirroring how Craft core handles `forms.dateTimeField` POST data).

## 6. Acceptance Criteria

- [ ] Migration adds `openDate`, `closeDate`, `submissionLimit` (shared) and
      `closedMessage` (per-site); propagation-safe `afterSave()`.
- [ ] All new fields are optional; a form with none set behaves exactly as today.
- [ ] `Form::isAcceptingSubmissions()` returns false before open, after close, and at/over
      the limit; `getClosedReason()` distinguishes the three.
- [ ] `getSubmissionCount()` is a count-only query across all sites, excluding spam.
- [ ] `SubmissionService::submit()` rejects closed/over-quota submissions with a `form`
      error carrying the resolved (per-site or default) closed message — honeypot still
      drops silently before this check.
- [ ] AJAX, no-JS POST, and GraphQL `submitForm` are all rejected when closed/full (shared
      guard, no duplicated logic).
- [ ] Public render shows the closed message instead of the form when closed/full.
- [ ] CP exposes the four settings + a live count/limit indicator; save round-trips them;
      `closeDate >= openDate` is validated.
- [ ] Race-safety behavior (soft cap) is documented in code comments.
- [ ] PHPStan L7 + ECS clean; all strings translatable.

## 7. Testing

### Unit (PHPUnit)

- `isAcceptingSubmissions()` truth table: before open, within window, after close,
  open-ended bounds, at limit, over limit, spam-excluded count.
- `getClosedReason()` returns the right token for each closed cause.
- `getSubmissionCount()` ignores spam rows and counts across sites.
- `SubmissionService::submit()` returns the `form`-keyed closed error when closed/full and
  persists **no** row; honeypot still drops before the availability check.
- `closeDate < openDate` fails validation.

### craft-smoke-test scenarios

- Create a form with `openDate` in the future; render the page → assert the closed message
  ("not yet open") shows and no `<form>` is present; submit via crafted POST → assert
  rejected (no submission row created).
- Set `closeDate` in the past; render → closed message; assert GraphQL `submitForm`
  returns `success:false` with the closed message in `errors`.
- Set `submissionLimit = 2`; submit twice successfully; render → assert form still shows
  (count = limit boundary handled per spec) then on the 3rd attempt assert rejection and
  the closed message; verify the CP index shows the count indicator.
- Mark a submission as spam; confirm it does **not** count toward the limit.

## 8. Open Questions

- **Boundary semantics for the limit.** Is the form closed *at* `count == limit` or only
  *after*? Proposing closed when `count >= limit` (i.e. the limit is the max accepted).
- **Do trashed/soft-deleted submissions count?** Proposing no (only live non-spam).
- **HTTP status for a closed submit.** 403 vs 200-with-error envelope. Proposing 200 +
  error to match the existing validation-error contract the JS already handles, with the
  rate-limit 429 as precedent for a stricter code if we choose one.
- **Closed-message placeholders.** Should it support the `{handle}`/date tokens from the
  post-submit PRD (e.g. "Opens {openDate}")? Proposing a minimal `{openDate}`/`{closeDate}`
  token set, deferred if it complicates v1.
