# PRD — Per-form post-submit actions (success message + redirect)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#133](https://github.com/fabianhaef/craft-simple-form/issues/133)

---

## 1. Problem Statement

Today the success and error messages shown after a submission are **global only**.
`fabianhaef\simpleform\models\Settings` holds `submitMessage` and `errorMessage`, and
both submit transports read them directly:

- `SubmitController::actionIndex` returns `$settings->submitMessage` in its JSON envelope.
- `TwigExtension::renderForm` exposes `$settings->errorMessage` via the `data-sf-error`
  attribute for the JS catch-handler.

That has two gaps:

1. **No per-form override.** A newsletter form and a job application share the same
   "Thank you!" text. Authors expect to word the confirmation per form.
2. **No post-submit behavior beyond an inline message.** Every form can only show a
   message in place — there is no way to send the visitor to a thank-you page, a
   downloadable resource, or a Craft entry. The front-end JS even uses `alert()` today
   (`simple-form.js`), which no real site wants.

There is also no way to template the submitted values into the message or a redirect URL
(e.g. "Thanks, {firstName}" or `/thanks?ref={email}`).

## 2. Goals

- Add **per-form overrides** for the success message and error message, falling back to
  the global `Settings` values when blank (no behavior change for existing forms).
- Add a per-form **post-submit action**:
  - `message` (default) — show the success message inline, replacing the form.
  - `url` — redirect to an absolute/relative URL.
  - `entry` — redirect to a Craft entry's URL.
- Support **templating submitted values** into the success message and the redirect URL
  via a safe `{handle}` placeholder syntax (field handles), plus a small set of
  built-ins (`{submissionId}`).
- Signal a redirect cleanly through the **AJAX JSON envelope** so the front-end JS
  performs a real `window.location` navigation (no more `alert()`).
- Keep everything **multi-site safe and translatable** — the success/error message
  overrides are per-site (translatable) content, like the existing `emailBody`.

## 3. Non-Goals (v1)

- Rendering arbitrary Twig in the success message (placeholders only — no Twig sandbox).
- Per-form-per-field conditional redirects (one action per form).
- A WYSIWYG/rich-text confirmation editor — plain text + placeholders only.
- Changing the GraphQL payload shape beyond an additive `redirectUrl` field.
- POST-redirect-GET / server-issued 302 from `SubmitController` (the controller stays
  `asJson`; the JS performs the navigation). A no-JS fallback is covered in Open Questions.

## 4. Users & Use Cases

- **Marketer**: wants "Thanks {firstName}, check your inbox." per campaign form.
- **Site builder**: wants the contact form to redirect to `/contact/thank-you` so an
  analytics goal fires on a distinct URL.
- **Editor**: wants the form to redirect to a chosen Craft entry (e.g. a "What happens
  next" page) without hard-coding a URL that breaks when the slug changes.

## 5. Proposed Solution

### 5.1 New Form fields (element + storage)

Add to `fabianhaef\simpleform\elements\Form`:

| Property | Type | Storage | Translatable |
| --- | --- | --- | --- |
| `submitMessage` | `?string` | `simpleform_forms_sites` | yes (per-site) |
| `errorMessage` | `?string` | `simpleform_forms_sites` | yes (per-site) |
| `postSubmitAction` | `string` (`message`\|`url`\|`entry`) | `simpleform_forms` (shared) | no |
| `redirectUrl` | `?string` | `simpleform_forms_sites` | yes (per-site — slugs/paths differ) |
| `redirectEntryId` | `?int` | `simpleform_forms` (shared element id) | no |

Rationale: the **action choice** and the **entry id** are structural (shared, like
`allowSaveResume`), while the **message text and URL string** are localized content and
join the existing per-site row in `simpleform_forms_sites` (alongside `emailBody`).
`postSubmitAction` defaults to `'message'`, so untouched forms keep inline-message
behavior.

Migration `mYYMMDD_000001_form_post_submit` adds the columns. Per the house rule,
`[[...]]`-quote any camelCase identifier in raw SQL; use `$this->addColumn()` with
`->after(...)` like `m260620_000003_form_allow_save_resume`. Register the migration in
`codeception.yml` and reset `craft_test` (see the test-DB snapshot reference).

`defineRules()` additions:

```php
$rules[] = [['submitMessage', 'errorMessage', 'redirectUrl'], 'string'];
$rules[] = [['postSubmitAction'], 'in', 'range' => ['message', 'url', 'entry']];
$rules[] = [['redirectEntryId'], 'integer'];
$rules[] = [['redirectUrl'], 'required', 'when' => fn() => $this->postSubmitAction === 'url'];
$rules[] = [['redirectEntryId'], 'required', 'when' => fn() => $this->postSubmitAction === 'entry'];
```

`afterSave()` already splits shared vs per-site writes — extend both `$shared`
(`postSubmitAction`, `redirectEntryId`) and `$siteRow` (`submitMessage`, `errorMessage`,
`redirectUrl`) blocks. The propagation guards stay exactly as written so a propagating
save never clobbers a sibling site's translated message.

### 5.2 Resolution helper (single source of truth)

Add a small resolver so every transport agrees on the final message/redirect.
Put it on `SubmissionService` (it already holds the shared submit logic):

```php
/**
 * @return array{message: string, redirectUrl: ?string}
 */
public function resolvePostSubmit(Form $form, Submission $submission, array $data): array
```

- `message` = `$form->submitMessage ?: $settings->submitMessage`, with placeholders
  interpolated from `$data`.
- `redirectUrl`:
  - `message` action → `null`.
  - `url` action → interpolate placeholders, then **url-encode** each substituted value.
  - `entry` action → resolve `$form->redirectEntryId` to an `Entry` for the submission's
    site (`->siteId($submission->siteId)`), use `->getUrl()`; `null` if the entry is
    missing/disabled.

Placeholder interpolation is a private helper: `{handle}` → the submitted scalar value
for that field handle (arrays join with ", "); `{submissionId}` → the id. Unknown
placeholders resolve to empty string. For `redirectUrl`, each substituted value is passed
through `rawurlencode()`; for the message, through `htmlspecialchars()` at render time
(the JS sets `textContent`, so no markup injection).

### 5.3 AJAX flow → front-end JS

`SubmitController::actionIndex` success branch becomes:

```php
$post = $service->resolvePostSubmit($form, $result['submission'], $result['data']);
return $this->asJson([
    'success'     => true,
    'message'     => $post['message'],
    'redirectUrl' => $post['redirectUrl'], // null = show inline message
]);
```

(`submit()` must return the built `$data` alongside `submission` so the controller can
interpolate; widen its return array, or re-read `$submission->data`.)

`simple-form.js` success handler (replacing the `alert()`):

```js
if (data.success) {
    if (data.redirectUrl) { window.location.assign(data.redirectUrl); return; }
    showSuccess(data.message);   // replaces the form with an inline success node
    form.reset();
}
```

`showSuccess()` renders a focusable `role="status"` success node via `textContent`
(a11y parity with the error path added in #105) and hides the `<form>`.

The error path keeps reading `data-sf-error`, but `TwigExtension::renderForm` now sets it
from the **per-form** value first: `$form->errorMessage ?: $settings->errorMessage`.

### 5.4 Public Twig render macro

`TwigExtension::renderForm` honors the new behavior for the **no-JS** path too: the
inline success node and `data-sf-error` already flow through the rendered markup. The
redirect itself is JS-driven; the no-JS fallback shows the inline success message (see
Open Questions for an optional server-redirect path).

### 5.5 GraphQL

Add `redirectUrl: String` to `SubmitFormPayloadType` (additive, non-breaking). In
`FormMutations::resolveSubmit`, populate it on success via `resolvePostSubmit()`. Headless
clients decide what to do with it; `submissionId`/`success`/`errors` are unchanged.

### 5.6 CP UI (`src/templates/forms/edit.html`)

A new "After submit" section (after the email block):

- `forms.textareaField` — Success message (per-site; placeholder hint listing available
  `{handle}` tokens for this form's fields).
- `forms.selectField` — On submit: Show message / Redirect to URL / Redirect to entry.
- `forms.textField` — Redirect URL (shown when action = url; supports `{handle}`).
- `forms.elementSelectField` with `elementType: craft\elements\Entry` — Redirect entry
  (shown when action = entry; single).
- `forms.textareaField` — Error message override (per-site).

Toggle visibility of the URL/entry rows with the same small `data-*`/JS pattern already
used in the builder inspector. `FormsController::actionSave` maps the new body params onto
the element (mirroring the existing `$form->emailBody = ...` lines).

## 6. Acceptance Criteria

- [ ] New columns added via migration; `Form` exposes the 5 new properties with correct
      shared/per-site placement and propagation-safe `afterSave()`.
- [ ] Blank per-form `submitMessage`/`errorMessage` fall back to global `Settings`.
- [ ] `postSubmitAction` defaults to `message`; existing forms render and behave exactly
      as before (inline message; no redirect).
- [ ] `SubmissionService::resolvePostSubmit()` interpolates `{handle}` and `{submissionId}`
      placeholders into both message and URL; URL values are `rawurlencode()`d.
- [ ] `entry` action resolves the entry on the submission's site and returns `getUrl()`;
      a missing/disabled entry yields a `null` redirect and the inline message is shown.
- [ ] `SubmitController` returns `redirectUrl` in its JSON; `simple-form.js` navigates via
      `window.location.assign` when present, else shows an accessible inline success node
      (no `alert()`).
- [ ] `errorMessage` override flows into `data-sf-error`.
- [ ] GraphQL `SimpleFormSubmitPayload` gains `redirectUrl`; populated on success.
- [ ] CP edit screen exposes all new settings, multi-site aware; save round-trips them.
- [ ] PHPStan L7 + ECS clean; all `Craft::t('simple-form', ...)`.

## 7. Testing

### Unit (PHPUnit)

- `Form` save/read round-trip for all 5 properties; propagation does not clobber a sibling
  site's `submitMessage`/`redirectUrl`.
- `resolvePostSubmit()`:
  - fallback to global when per-form blank;
  - `{handle}` interpolation (scalar, array, missing field → empty);
  - URL encoding of substituted values;
  - `entry` action with present vs missing/disabled entry;
  - `redirectUrl` is `null` for `message` action.
- JS parity test (node) for `showSuccess`/redirect branch selection if extracted as a pure
  function, following the existing `tests/js/conditional-evaluator.test.js` pattern.

### craft-smoke-test scenarios

- Create a form with a per-form success message "Thanks {firstName}!"; submit with
  firstName=Ada; assert the rendered inline success node reads "Thanks Ada!" and the form
  is hidden; no submission email override regressions.
- Set action = Redirect to URL `/thanks?e={email}`; submit; assert the browser navigates
  to `/thanks?e=ada%40example.com` (encoded).
- Set action = Redirect to entry (pick an entry); submit; assert navigation to that entry's
  URL; disable the entry and re-submit → inline message shown instead.
- Leave per-form message blank; submit; assert the global `Settings.submitMessage` shows.

## 8. Open Questions

- **No-JS redirect.** Should `SubmitController` issue a real 302 when the request is not
  XHR (no `X-Requested-With`) for the `url`/`entry` actions? This gives a true
  POST-redirect-GET for progressive enhancement. Leaning yes as a follow-up; v1 shows the
  inline message on the no-JS path.
- **Placeholder syntax.** `{handle}` vs `{{ handle }}`. Proposing single-brace to avoid
  confusion with Twig and to keep it obviously non-Twig.
- **Open-redirect safety.** For `url`, do we restrict to same-origin / relative paths, or
  allow external absolute URLs (author-controlled, so arguably fine)? Proposing: allow as
  authored, but document that the value is author-trusted and substituted values are
  encoded.
- **File/asset field placeholders.** What does `{resume}` resolve to for a file field —
  the asset filename, URL, or empty? Proposing empty in v1.
