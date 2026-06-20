# PRD — Field type: Hidden (dynamic default values)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#124](https://github.com/fabianhaef/craft-simple-form/issues/124)

---

## 1. Problem Statement

Form creators routinely need to capture context the visitor never types: which
campaign brought them (`utm_source`, `utm_campaign`), the page they came from
(`referrer`), the landing page, the logged-in user's email, or a value stamped at
render time. Today Simple Form has no way to do this — every field is visible and
hand-filled. Creators resort to brittle custom Twig/JS to inject `<input
type="hidden">` markup outside the builder, which then **isn't captured into the
submission**, doesn't appear in exports, and isn't validated or trusted.

A **Hidden** field type — a non-visible field whose value is populated from a
configurable source at render time and captured into the submission — closes this
gap and is a staple of marketing/lead forms.

## 2. Goals

- Add a **Hidden** field type (`fabianhaef\simpleform\fields\HiddenFieldType`)
  registered in `FieldTypeRegistry`, configurable in the builder like any field.
- Render `<input type="hidden">` whose value is resolved at render time from a
  configurable **source**: static default, URL query param, logged-in user
  attribute (email / id / username), or cookie.
- Capture the resolved value into the submission `data` payload so it appears in
  the CP submission view and CSV/Excel exports, **without a visible label**.
- Make value resolution **secure**: for user/session-derived sources the server
  must **re-resolve** the value at submit time rather than trusting the
  client-posted hidden input.
- Stay multi-site safe and within the existing `config` + submission-data model —
  no new tables, no breaking changes.

## 3. Non-Goals (v1)

- Arbitrary computed/expression values (e.g. Twig snippets) as a source.
- Reading server-only secrets (env vars, tokens) into a form value.
- Persisting cookies/consent state — this field *reads* sources, it doesn't write.
- Visible-but-readonly "display" fields. Hidden is genuinely non-visible.

## 4. Users & Use Cases

- **Marketer**: adds Hidden fields `utm_source`, `utm_medium`, `utm_campaign`
  sourced from the URL query string, plus `referrer` from a cookie set by their
  analytics, so every lead carries attribution into the CRM via the
  outbound-integrations feature.
- **Membership site**: adds a Hidden field sourced from the **logged-in user's
  email**, so submissions are attributable even before the visitor fills anything,
  and the value can't be spoofed by editing the DOM.
- **Developer (headless/GraphQL)**: sets the value explicitly in the mutation
  payload for `static`/`query`/`cookie` sources; for `user` sources the server
  resolves from the authenticated identity regardless of what's posted.

---

## 5. Proposed Solution

### 5.1 New field type

`HiddenFieldType extends FieldType`:

```php
public static function getType(): string  { return 'hidden'; }
public static function getLabel(): string { return 'Hidden'; }
public function renderInput(string $name, mixed $value = null): string;
public function validate(mixed $value): array;
```

Registered in `FieldTypeRegistry::init()`. Not part of `OPTION_TYPES`. It opts out
of the visible-label rendering in the front-end form template (see §5.5).

### 5.2 Per-field config

```json
{
  "source": "query",            // "static" | "query" | "user" | "cookie"
  "default": "",                // fallback when the source yields nothing
  "queryParam": "utm_source",   // for source=query
  "userAttribute": "email",     // for source=user: "email" | "id" | "username"
  "cookieName": "referrer",     // for source=cookie
  "maxLength": 255              // optional sanity bound
}
```

The field still has a **label** in config (translatable), used only as the
column/row label in the CP and exports — it is **never rendered to the visitor**.

### 5.3 Source resolution (render time)

A resolver — `HiddenFieldType::resolveDefault()` or a small
`src/helpers/HiddenValueResolver.php` — computes the render-time value:

| `source` | Resolution                                                            |
|----------|-----------------------------------------------------------------------|
| `static` | `config.default` verbatim.                                            |
| `query`  | `Craft::$app->getRequest()->getQueryParam(queryParam)`, else default. |
| `user`   | The logged-in user's `email`/`id`/`username`; empty if guest.         |
| `cookie` | `Craft::$app->getRequest()->getCookies()->getValue(cookieName)`.      |

Resolved values are `htmlspecialchars`-escaped into the hidden input. For `query`
and `cookie`, the value is **client-supplied data** and is treated as untrusted
text (escaped, length-bounded, never interpreted).

### 5.4 Rendering

```html
<input type="hidden" id="field_<id>" name="field_<id>"
       value="<resolved + escaped>">
```

`renderInput()` uses the base `getInputAttributes()` for `id`/`name` and injects
the resolved value. No `<label>`, no wrapper, no `required` marker is rendered
(the front-end template skips the visible label/wrapper for `type === 'hidden'`).

### 5.5 Front-end template integration

The form-render template (`TwigExtension::renderForm()` path) must **not** wrap a
Hidden field in the standard labelled field group. A type check (`field.getType()
=== 'hidden'`) emits the bare input only — consistent with how the conditionals
PRD already special-cases field rendering by type.

### 5.6 Server-side capture & the security re-resolve

This is the load-bearing part. At submit time, inside
`SubmissionService::submit()` (the single path for AJAX + GraphQL):

1. **Trusted (re-resolved) sources — `source === 'user'`:** the server **ignores
   the posted value** and re-resolves the attribute from the authenticated user
   (`$context['userId']` / `Craft::$app->getUser()`). A guest yields empty (or the
   `default`). This prevents a crafted POST from claiming another user's email/id.
2. **Pass-through sources — `static` / `query` / `cookie`:** the value is inherently
   client-influenced, so the posted value is accepted but **sanitized**: trimmed,
   length-bounded to `maxLength`, stored as plain text. (For `static`, the server
   may also re-stamp `config.default` to guarantee integrity; see Open Questions.)

The resolved value is written into the existing payload shape:

```json
"field_77": { "label": "UTM Source", "type": "hidden", "value": "spring-sale" }
```

This needs a small hook in `submit()`: when a field's type is `hidden`, route
through `HiddenFieldType::resolveForSubmit(mixed $posted, array $context)` instead
of taking the raw `valuesByHandle` value. The method returns the trusted value for
`user` and the sanitized posted value otherwise. No new table; same `data` JSON.

### 5.7 Validation

`validate()` is permissive (hidden fields are not visitor-facing):

- A Hidden field is **never `required`** in the visitor sense; the builder hides
  the "Required" toggle for this type.
- `maxLength` is enforced (truncate or reject — proposed: reject overly long values
  as a tamper signal) via the shared length-check pattern.
- For `source === 'user'` with a guest and no `default`, an empty value is valid.

### 5.8 Builder UI

- Hidden appears in the add-field palette.
- The per-field inspector shows: **Internal label** (for exports/CP), **Source**
  dropdown, and the source-specific input (Query param / User attribute / Cookie
  name / Static value). The "Required", "Placeholder", and "Visible label"
  controls are suppressed for this type.
- Config serializes into the existing `#sf-fields-data` JSON — no new endpoint.
- Because it's invisible on the front end, the builder shows a clear
  *"Hidden — captured silently"* badge on the field card so creators don't expect
  it to appear in the rendered form.

### 5.9 Interaction with conditional logic

Hidden fields can be **referenced by** other fields' conditional rules (e.g. show a
field only when `utm_source eq spring-sale`), since rules read the resolved
submission value. A Hidden field being itself conditionally hidden is a no-op
(it's already invisible) but is allowed and harmless.

---

## 6. Acceptance Criteria

- [ ] A **Hidden** field type is registered and selectable in the builder, with a
      Source dropdown (static / query / user / cookie) and source-specific config.
- [ ] The rendered field is a bare `<input type="hidden">` with **no visible
      label or wrapper**.
- [ ] Render-time resolution works for all four sources (static default; URL query
      param; logged-in user email/id/username; cookie), falling back to `default`.
- [ ] The resolved value is **captured into the submission `data`** and surfaces in
      the CP submission view and CSV/element export (column header = internal
      label).
- [ ] **Security:** for `source === 'user'`, the server re-resolves from the
      authenticated identity and ignores the posted value — verified by a test that
      posts a spoofed email and asserts the stored value matches the real user.
- [ ] `query`/`cookie`/`static` values are sanitized (trim + `maxLength`) and never
      interpreted as markup.
- [ ] No new DB tables; existing forms unaffected; PHPStan L7 + ECS clean.
- [ ] Both AJAX and GraphQL submit paths produce identical capture behaviour.

## 7. Testing

### Unit (PHPUnit)

- `HiddenValueResolver` per source: query param present/absent; user email/id/
  username for an authenticated user; guest → empty/default; cookie present/absent;
  static verbatim.
- `resolveForSubmit()` security: `user` source ignores a spoofed posted value and
  returns the authenticated attribute; guest returns default.
- `validate()`: `maxLength` enforced; never required; user-guest-empty is valid.
- `renderInput()`: emits `type="hidden"`, no label markup, value escaped.

### craft-smoke-test scenarios

- Build a form with a Hidden `utm_source` field (source = query). Visit the
  rendered form with `?utm_source=spring-sale`, submit, and assert the stored
  submission has `field_<id>.value === 'spring-sale'`.
- Build a Hidden field sourced from the logged-in user's email. Submit while logged
  in **with a tampered hidden input value** and assert the stored value is the real
  user's email, not the tampered one.
- Assert the Hidden field renders with no visible label on the front end.
- Export submissions and assert the Hidden field's internal label is a column whose
  cell holds the captured value.

## 8. Open Questions

- **Static integrity:** for `source === 'static'`, do we accept the posted value or
  always re-stamp `config.default` server-side? Re-stamping is safer but means a
  static Hidden field can't be a per-render token set by Twig. Proposed:
  re-stamp `default` (predictable), document the limitation.
- **Allowed user attributes:** restrict to `email`/`id`/`username`, or also allow
  `fullName` and selected custom user fields? v1 = the three safe ones.
- **Multiple query params into one field:** out of scope, or support a simple
  concatenation? Likely separate fields per param in v1.
- **GDPR/retention:** captured attribution (referrer, utm) is personal-data-adjacent
  — does it need its own redaction handling in the retention story, or is the
  existing per-submission retention sufficient? Likely sufficient.
