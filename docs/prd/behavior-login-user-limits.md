# PRD — Login-required + per-user submission limits + user association

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#135](https://github.com/fabianhaef/craft-simple-form/issues/135)

---

## 1. Problem Statement

Submissions already *can* carry a `userId` — `SubmissionService::createFromRequest()` reads
`Craft::$app->getUser()->getId()` and `submit()` persists it on `$submission->userId`. But
that is the extent of it: there is no way for an author to

1. **require a logged-in user** to view or submit a form,
2. **limit submissions per user** (e.g. "vote once", "one application per account"), or
3. rely on the user association being enforced/queried.

Membership sites, internal tools, "one entry per account" promos, and gated downloads all
need these. Today an author can only approximate "logged-in only" with template guards that
the server doesn't enforce — a crafted POST submits anyway. No Craft form plugin offers
per-user limits cleanly; this is a differentiator.

## 2. Proposed Solution scope sits on top of the existing single submit path
(`SubmissionService::submit()`), so AJAX, no-JS POST, and GraphQL are all covered by one
enforcement point — the same pattern as scheduling/quotas.

## 2. Goals

- Per-form **require login** toggle: when on, anonymous visitors cannot view or submit the
  form. The render shows a translatable message with an optional login link; the server
  rejects anonymous submissions.
- Per-form **per-user submission limit**: cap how many times a single user may submit
  (e.g. `1` = once per user). Enforced server-side.
- **Always associate** the resulting `Submission` with the current user when one is logged
  in (already partly true — make it consistent and covered by tests).
- Enforce all of the above in `SubmissionService::submit()` so AJAX + no-JS + GraphQL
  share one code path.
- Multi-site safe, translatable, no behavior change for existing forms (defaults off).

## 3. Non-Goals (v1)

- Group/permission-based gating ("only users in group X"). Login-required is binary in v1.
- Rate-limiting guests by IP for the *per-user* feature — the existing global
  `submitRateLimitPerMinute` (IP throttle in `SubmissionService::isRateLimited`) stays the
  abuse control for anonymous forms.
- Editing/replacing a prior submission ("update my response"). The limit blocks; it does
  not open an edit flow.
- A full guest-dedup system. Guest keying (email/IP) is discussed but kept optional/minimal
  (see 5.4 + Open Questions).

## 4. Users & Use Cases

- **Membership site**: gated "request a quote" form — must be logged in; one open request
  per account.
- **Internal tool**: staff-only feedback form behind login; submissions attributed to the
  staff member for follow-up.
- **Promo**: "one entry per account" giveaway; logged-in users vote once.
- **Visitor not logged in**: sees "Please log in to submit this form" with a link to the
  Craft login, returning to the form afterward.

## 5. Proposed Solution

### 5.1 New Form fields (element + storage)

All on the **shared** `simpleform_forms` row except the two messages (per-site content).

| Property | Type | Storage | Translatable |
| --- | --- | --- | --- |
| `requireLogin` | `bool` (default false) | `simpleform_forms` | no |
| `loginRequiredMessage` | `?string` | `simpleform_forms_sites` | yes |
| `submissionsPerUser` | `?int` | `simpleform_forms` | no |
| `userLimitMessage` | `?string` | `simpleform_forms_sites` | yes |
| `guestLimitKey` | `string` (`none`\|`email`\|`ip`) | `simpleform_forms` | no |

`submissionsPerUser` null = unlimited. `guestLimitKey` defaults to `none` (the limit only
applies to logged-in users unless explicitly extended to guests — see 5.4). Both messages
fall back to translatable defaults when blank.

Migration `mYYMMDD_000001_form_user_limits` adds the columns (booleans like
`m260620_000003_form_allow_save_resume`; `[[...]]`-quote camelCase in raw SQL). Register in
`codeception.yml`; reset `craft_test`. Extend `Form::afterSave()` (`$shared` for toggles +
limits + key, `$siteRow` for the two messages) keeping the propagation guards.

`defineRules()`:

```php
$rules[] = [['requireLogin'], 'boolean'];
$rules[] = [['submissionsPerUser'], 'integer', 'min' => 1];
$rules[] = [['loginRequiredMessage', 'userLimitMessage'], 'string'];
$rules[] = [['guestLimitKey'], 'in', 'range' => ['none', 'email', 'ip']];
```

### 5.2 Server-side enforcement in `SubmissionService::submit()`

Add a guard block **after** the honeypot drop (bots get no signal) and the
closed/availability check, **before** captcha/validation. Use the `userId` already present
in `$context`:

```php
$userId = isset($context['userId']) ? (int) $context['userId'] : null;

// (a) Require login.
if ($form->requireLogin && $userId === null) {
    return ['submission' => null, 'errors' => ['form' => [$this->loginMessageFor($form)]]];
}

// (b) Per-user limit.
if ($form->submissionsPerUser !== null) {
    $count = $this->userSubmissionCount($form, $userId, $context);
    if ($count >= $form->submissionsPerUser) {
        return ['submission' => null, 'errors' => ['form' => [$this->userLimitMessageFor($form)]]];
    }
}
```

Because both transports route through `submit()`, AJAX, no-JS, and GraphQL are all enforced
without duplicated logic. The `userId` already arrives correctly from both:
`createFromRequest()` and `FormMutations::resolveSubmit` both pass
`Craft::$app->getUser()->getId()`.

`userSubmissionCount()` is a count-only query:

```php
$q = Submission::find()->formId($form->id)->siteId('*')->status(null);
if ($userId !== null) {
    $q->userId($userId);
} elseif ($form->guestLimitKey === 'email') {
    $q->/* match on the stored email field value */;
} elseif ($form->guestLimitKey === 'ip') {
    $q->/* match on a stored submitter IP */;
} else {
    return 0; // guests not limited
}
return (int) $q->count();
```

The `Submission` element already supports `userId()` filtering (it stores `userId`). Spam
rows are excluded from the count (a spam submission shouldn't burn a user's allowance).

### 5.3 Always associate the user

`submit()` already sets `$submission->userId = $context['userId']`. This PRD makes it a
hard, tested guarantee: whenever a user is logged in, the persisted submission's `userId`
is their id, for every transport. No new code beyond confirming both call sites pass it
(they do) and adding coverage. The CP submission view already can show the user; ensure the
association is surfaced there.

### 5.4 Guest keying (discussion → minimal v1)

The per-user limit is **trivial and reliable for logged-in users** (stable `userId`). For
guests it is inherently weak:

- **email** — match on a stored email-field value. Easy to bypass (use another address) but
  meaningful for honest "one response per person" surveys. Requires the form to *have* an
  email field; pick the first `email`-type field's value.
- **ip** — match on a stored submitter IP. Catches casual repeats; breaks behind shared
  NAT/proxies and is defeated trivially. Requires persisting the submitter IP on the
  submission (a small additive column or reuse of an existing audit field).

Proposed v1: ship `guestLimitKey` with `none` as default and **`email`** as a supported
option (no new IP storage needed). Treat `ip` as a follow-up gated on persisting the IP.
Document clearly that guest keys are best-effort, not security.

### 5.5 Public render (TwigExtension + macro)

`TwigExtension::renderForm`:

```php
if ($form->requireLogin && Craft::$app->getUser()->getIsGuest()) {
    $loginUrl = UrlHelper::siteUrl(
        Craft::$app->getConfig()->getGeneral()->getLoginPath(),
        ['return' => Craft::$app->getRequest()->getAbsoluteUrl()]
    );
    return '<div class="simple-form simple-form--login-required" role="status">'
         . htmlspecialchars($this->loginMessageFor($form))
         . ' <a href="' . htmlspecialchars($loginUrl) . '">'
         . htmlspecialchars(Craft::t('simple-form', 'Log in')) . '</a></div>';
}
```

For a logged-in user who has hit their per-user limit, render the `userLimitMessage`
instead of the form (so they don't fill it out only to be rejected). A page cached before
login still posts to a server that rejects it (5.2) — both correct.

### 5.6 GraphQL

No schema change. `FormMutations::resolveSubmit` already passes `userId`; the shared guard
in `submit()` returns the `form`-keyed message which `formatErrors()` already maps into the
payload `errors` list. Note: a headless caller authenticates via a Craft user session/token
for `userId` to be non-null; document that anonymous GraphQL tokens count as guests.

### 5.7 CP UI (`src/templates/forms/edit.html`)

A new "Access & limits" section:

- `forms.lightswitchField` — Require login to view/submit.
- `forms.textareaField` — Login-required message (per-site).
- `forms.textField` (number) — Submissions per user (blank = unlimited).
- `forms.selectField` — Limit guests by: Don't limit guests / Email field / IP (if shipped).
- `forms.textareaField` — Limit-reached message (per-site).

`FormsController::actionSave` maps the new body params (mirroring `$form->allowSaveResume`
and the email lines).

## 6. Acceptance Criteria

- [ ] Migration adds `requireLogin`, `submissionsPerUser`, `guestLimitKey` (shared) and
      `loginRequiredMessage`, `userLimitMessage` (per-site); propagation-safe `afterSave()`.
- [ ] Defaults off → existing forms render and behave exactly as today.
- [ ] `requireLogin` rejects anonymous submissions server-side (AJAX + no-JS + GraphQL) and
      hides the form behind a translatable message + login link on render.
- [ ] `submissionsPerUser` blocks a logged-in user at/over their cap with the limit message;
      a fresh user can still submit; spam rows don't count toward the cap.
- [ ] Every submission by a logged-in user persists their `userId` (all transports).
- [ ] Guest `email` keying matches on the form's email field value; `none` never limits
      guests.
- [ ] CP exposes all settings; save round-trips them; multi-site/translatable.
- [ ] PHPStan L7 + ECS clean; all strings via `Craft::t('simple-form', ...)`.

## 7. Testing

### Unit (PHPUnit)

- `submit()` rejects anonymous submission when `requireLogin` is on (no row persisted);
  allows it when a `userId` is in context.
- Per-user limit: `submissionsPerUser = 1` — first submit by user A succeeds, second by A is
  rejected with the limit message; user B still succeeds; spam row by A is not counted.
- `userId` is persisted on the submission for every logged-in submit (both `createFromRequest`
  and direct `submit`).
- Guest `email` keying counts prior submissions sharing the same email; `none` returns 0.
- GraphQL `submitForm` returns the login/limit error in `errors` for an anonymous/over-limit
  caller.

### craft-smoke-test scenarios

- Toggle Require login on a form; as a guest, load the page → assert the login-required
  message + a working login link, no `<form>`; attempt a crafted POST → assert rejected,
  no submission created.
- Log in; submit once successfully (assert submission row has the user's `userId` in the CP
  view); set Submissions-per-user = 1; reload the form → assert the limit-reached message
  shows instead of the form; attempt a second POST → assert rejected.
- Log in as a different user → assert that user can still submit once.
- Set guest keying = Email, submit twice with the same email as a guest → second rejected.

## 8. Open Questions

- **Guest keying default option set.** Ship `email` in v1, defer `ip` (needs IP storage)?
  Proposing yes.
- **Counting window.** Is the per-user limit lifetime or resettable (per day/per window)?
  Proposing lifetime in v1; a `perUserPeriod` could be a follow-up.
- **Interaction with save-&-resume drafts.** Does an unfinished draft count toward the
  per-user limit? Proposing no — only persisted submissions count.
- **Headless `userId`.** Confirm the intended auth model for GraphQL submitters (session vs
  token-as-user) so "associate the user" is well-defined for that transport.
- **Soft race on the cap.** Same non-atomic count→save caveat as the quota PRD; accept as a
  soft limit and document.
