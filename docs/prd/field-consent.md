# PRD — Field type: Agree / Consent (GDPR)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#125](https://github.com/fabianhaef/craft-simple-form/issues/125)

---

## 1. Problem Statement

Any form that collects personal data in the EU/EEA needs an explicit, auditable
**consent** checkbox — "I agree to the privacy policy", "I consent to being
contacted" — that the visitor must actively tick before submitting. Simple Form's
existing **Checkbox** field type is a multi-option group; it can't express a single
mandatory consent box with a rich, linked label, and it doesn't record **what** the
visitor agreed to (the policy text/version) or **when**.

For GDPR/ePrivacy compliance, "they ticked a box" is not enough — you need the
consent text shown at the time, the timestamp, and proof the box was checked. The
plugin already has a GDPR/retention story (retention GC, audit log); a dedicated
**Consent** field completes the consent-capture half of that story.

## 2. Goals

- Add a **Consent** field type
  (`fabianhaef\simpleform\fields\ConsentFieldType`) registered in
  `FieldTypeRegistry`: a single checkbox that **must be checked to submit**,
  enforced **server-side**.
- Support a **translatable rich label** that may contain a link (e.g.
  *"I agree to the [privacy policy](…)"*), rendered safely.
- **Store, per submission, the consent record**: the boolean, a timestamp, and the
  **version/snapshot of the consent text** shown — so you can prove what was agreed
  to even if the policy text later changes.
- Integrate with the existing **conditional logic** so consent can be made
  conditionally required (e.g. only when "Subscribe to newsletter" is checked).
- Stay within the existing `config` + submission-`data` model (no new tables),
  multi-site safe and translatable.

## 3. Non-Goals (v1)

- A full consent-preferences center / per-purpose granular consent matrix. v1 is
  one consent box per field (creators can add several Consent fields for several
  purposes).
- Withdrawal-of-consent workflows / re-consent campaigns.
- Versioned policy-document management (the plugin stores the **text shown**, it
  doesn't manage a CMS of policy versions).
- Cookie-banner / tracking-consent (that's site-level, not form-level).

## 4. Users & Use Cases

- **DPO / marketer** building a signup form: adds a Consent field with the label
  *"I agree to the [privacy policy]"* linking to the policy entry; the form can't be
  submitted unless it's ticked, and every submission records the agreement.
- **Compliance/auditor**: opens a submission in the CP and sees *"Consented: yes —
  2026-06-20 14:05 — text version: 'I agree to the privacy policy' (v hash …)"*,
  giving defensible proof.
- **Developer (headless/GraphQL)**: gets the consent requirement enforced by the
  server regardless of channel and receives the consent record in the submission
  payload.

---

## 5. Proposed Solution

### 5.1 New field type

`ConsentFieldType extends FieldType`:

```php
public static function getType(): string  { return 'consent'; }
public static function getLabel(): string { return 'Agree / Consent'; }
public function renderInput(string $name, mixed $value = null): string;
public function validate(mixed $value): array;
public function isChoiceGroup(): bool { return false; } // single control
```

Registered in `FieldTypeRegistry::init()`. It is a single checkbox, **not** an
option group, so it is **not** added to `OPTION_TYPES`.

### 5.2 Per-field config

```json
{
  "required": true,             // a consent field is effectively always required…
  "consentText": "I agree to the [privacy policy](https://…/privacy)",
  "linkUrl": "https://example.com/privacy",
  "requiredMessage": "You must agree before submitting."
}
```

- `consentText` is the **translatable rich label**, per-site translatable like all
  user-facing strings. v1 supports a single inline link, expressed either as a
  lightweight markdown-style `[label](url)` token **or** a separate `linkUrl` +
  placeholder token (see §5.4 for the safe-render decision).
- `requiredMessage` overrides the default translatable error.
- The "Required" toggle in the builder defaults to **on** and is the normal path;
  conditional-required (§5.7) can scope it.

### 5.3 Rendering

A single checkbox with an associated rich label:

```html
<div class="sf-consent">
  <input type="checkbox" id="field_<id>" name="field_<id>" value="1"<checked> required>
  <label for="field_<id>"><!-- rendered rich consent text with link --></label>
</div>
```

- `id`/`name`/`required` come from the base helpers (consistent a11y `<label
  for>` association, per the codebase's existing checkbox pattern). Note the known
  **double-input gotcha** does not apply here because there is no hidden companion
  input — value is `"1"` when checked, absent when not.
- The value posts as `"1"` (checked) or is absent (unchecked).

### 5.4 Safe rich-label rendering (the link)

The consent text is **author-controlled CP content**, but must be rendered as
safe, link-only HTML. v1 renders the label via a tiny, **allowlist** transform:

- Parse the `[label](url)` token(s) and emit `<a href="…" target="_blank"
  rel="noopener noreferrer">label</a>` with the **URL validated** (http/https only,
  `htmlspecialchars`'d).
- All surrounding text is `htmlspecialchars`-escaped — no arbitrary HTML/Twig is
  interpreted. This avoids the Twig-sandbox no-op pitfall: we do **not** render
  user Twig; we do a fixed, audited link transform.

This keeps XSS off the table while letting non-technical creators link the policy.

### 5.5 Server-side enforcement & the consent record

Inside the single `SubmissionService::submit()` path:

- `validate()` returns the `requiredMessage` (translatable) when the field is
  required (statically or conditionally) and the posted value is not truthy
  (`"1"`/`true`). This is the authoritative gate — a headless/GraphQL submit that
  omits consent is rejected exactly like the browser path.
- On a passing submission, the persisted value is **not** a bare boolean but a
  consent record, stored in the existing `data` JSON shape:

```json
"field_88": {
  "label": "Consent",
  "type": "consent",
  "value": {
    "consented": true,
    "consentedAt": "2026-06-20T14:05:00+00:00",
    "textVersion": "I agree to the privacy policy (https://…/privacy)",
    "textHash": "sha256:…"
  }
}
```

- `consentedAt` is stamped **server-side** at submit time (not trusted from the
  client).
- `textVersion` is the **resolved, localized consent text** that was in effect for
  the form's site at submission time; `textHash` is a stable hash of it so two
  submissions can be compared and a later policy edit is detectable. This gives the
  "what did they agree to, and when" audit property GDPR expects — within the
  existing submission-data model, **no new table**.

### 5.6 CP display & export

- The CP submission detail renders the consent record as
  *"Consented: Yes — 2026-06-20 14:05 — text: '…'"* rather than a bare `1`.
- In CSV/element export (`SubmissionCsv`), the consent column flattens to a clear
  scalar (e.g. `Yes (2026-06-20 14:05)`); the full `textVersion`/`textHash` stay in
  the JSON. The exporter already calls a `scalar()` flattener, so this is a typed
  formatting branch, not a schema change.

### 5.7 Conditional logic

Consent is **conditionally-requireable** via the existing conditionals feature: a
creator can require the consent box only when another field's value matches (e.g.
require marketing consent only when "Sign me up for the newsletter" is checked).
Because the required-ness is resolved against the submitted snapshot in
`submit()`, the server enforces it correctly for all channels — the consent
field's `required` is the OR of its static flag and its conditional-required rule,
exactly as the conditionals PRD specifies.

### 5.8 Builder UI

- Consent appears in the add-field palette.
- The inspector shows: **Consent text** (rich, with a "Add link" affordance that
  inserts a `[label](url)` token), **Required** (default on), **Required message**,
  and the standard **Conditions** section (so conditional-required works).
- The "Placeholder", "Default", and multi-option controls are suppressed for this
  type.
- Config serializes into the existing `#sf-fields-data` JSON — no new endpoint.

---

## 6. Acceptance Criteria

- [ ] A **Consent** field type is registered and selectable in the builder, with a
      translatable rich consent-text and an optional inline link.
- [ ] The rendered field is a single `<input type="checkbox">` with a safely
      rendered linked label and a proper `<label for>` association.
- [ ] Submission is **blocked server-side** when a required consent box is
      unticked — verified on both AJAX and GraphQL paths.
- [ ] A passing submission stores a consent record
      `{consented, consentedAt, textVersion, textHash}` under `field_<id>.value`,
      with `consentedAt` stamped server-side.
- [ ] The consent text and error message are **per-site translatable**; the
      timestamp is timezone-correct.
- [ ] The rich label renders **no arbitrary HTML/Twig** — only an allowlisted,
      validated link (no XSS).
- [ ] Consent can be made **conditionally required** via existing conditional logic.
- [ ] CP detail and CSV export show a human-readable consent state; no new DB
      tables; existing forms unaffected; PHPStan L7 + ECS clean.

## 7. Testing

### Unit (PHPUnit)

- `validate()`: required + unchecked → translatable error; checked (`"1"`) → no
  error; conditionally-required interplay (required only when rule matches).
- Consent-record build: `consentedAt` server-stamped (not from client);
  `textVersion`/`textHash` derived from the resolved localized text; hash stable
  for identical text, different for edited text.
- Rich-label render: `[label](url)` → safe `<a rel="noopener">`; `javascript:` and
  other non-http(s) URLs rejected; surrounding text escaped; a label containing
  `<script>` is neutralized.
- `renderInput()`: single checkbox, value `"1"`, `required` present, `<label for>`
  matches the input id.

### craft-smoke-test scenarios

- Build a form with a required Consent field linking to a policy URL. Render it,
  submit **without** ticking the box, and assert the form re-renders with the
  translatable error and **no** submission row is created.
- Submit **with** the box ticked and assert the stored submission carries
  `consented: true`, a non-empty `consentedAt`, and the `textVersion` matching the
  shown label.
- Switch to a second site, confirm the consent label is translated, submit, and
  assert the stored `textVersion` reflects the localized text.
- Open the submission in the CP and assert the consent state renders human-readably;
  export and assert the consent column shows `Yes (…)`.

## 8. Open Questions

- **Link syntax:** markdown-style `[label](url)` token vs. a structured
  `linkUrl` + `{policy}` placeholder in the text. Proposed: the `[label](url)`
  token (one link, validated) for authoring simplicity; revisit if multiple links
  are needed.
- **Text-version granularity:** hash the localized text only, or also pin the form
  revision / a creator-set "consent version" string? Proposed: hash the localized
  text shown; allow an optional explicit `consentVersion` label later.
- **Retention/redaction:** when a submission is GC'd or redacted by the retention
  feature, should the consent record (proof) be retained longer than the rest of
  the PII for legal defensibility? Needs a call with the retention story owner.
- **Multiple consents:** confirm several Consent fields per form (separate
  purposes) is the intended granularity for v1 rather than a single multi-purpose
  field.
