# Front-end Accessibility Audit — Simple Form (WCAG 2.1 AA)

Scope: the **public, rendered** form output only — `src/templates/_form/*.twig` + each field type's `renderInput()` in `src/fields/*FieldType.php` + the front-end runtime `src/web/assets/form/dist/js/simple-form.js`. Research-only; no source was changed.

Audited against WCAG 2.1 AA, focused on the criteria that actually decide whether a form is usable with a keyboard and a screen reader.

## Verdict

The baseline is genuinely good — far better than most form builders. `field.twig` already emits `<label for>`, a `role="group"` + `aria-labelledby` wrapper for choice groups, `required` on controls, and `role="alert"` error nodes; the submit-time JS already wires `aria-invalid` + `aria-describedby` and moves focus to the first invalid control. The gaps below are real but mostly narrow.

**No blockers found.** The most impactful items are two **serious** gaps: the File field control has no `id` (so its visible label is not programmatically associated), and the composite (Name/Address) `<fieldset>` references a `<legend>` id that is never rendered.

---

## Findings

| # | File:line | Issue | WCAG | Severity | Confidence | Behaviour-safe fix? |
|---|-----------|-------|------|----------|-----------|---------------------|
| 1 | `src/fields/FileFieldType.php:164-180` | `renderInput()` builds `name="…"` by hand and **omits `id`**. The field group emits `<label for="field_<id>">`, but the `<input type="file">` has no matching `id`, so the label is not programmatically associated. | 1.3.1, 4.1.2, 3.3.2 | serious | HIGH | yes |
| 2 | `src/fields/CompositeFieldType.php:147-149` | The composite renders `<fieldset … aria-labelledby="<name>-legend">` but **no element ever has id `<name>-legend`** (no `<legend>` is emitted). The `aria-labelledby` dangles → the fieldset has no accessible name from it; AT falls back to the outer group label only by luck. | 1.3.1, 4.1.2 | serious | HIGH | yes |
| 3 | `src/web/assets/form/dist/js/simple-form.js:432-438` | Multi-step Next/Back toggles `hidden` and updates the progress text, but **focus is never moved into the newly shown step**. On Next the focused control (the Next button) can become `hidden` (last step), leaving focus on a hidden/again-irrelevant element; keyboard/SR users get no signal that the step changed beyond the polite live region. | 2.4.3, 3.2.2 (best practice) | serious | HIGH | yes |
| 4 | `src/fields/CalculationFieldType.php:99-125` | The live `<output>` total has no programmatic label and is not associated with the inputs it derives from (`for=`). A sighted user sees the running total; a SR user gets an unlabelled live value. (It is read-only and never required, so low stakes.) | 1.3.1, 4.1.2 | minor | MED | yes |
| 5 | `src/templates/_form/field.twig:32` + JS error path | Help text renders as `<small class="help-text">` but is **not tied to the input via `aria-describedby`** — and when a field error is added, the JS sets `aria-describedby` to the *error id only*, which would also overwrite a help-text association if one existed. Help text is announced only if the SR user navigates to it. | 1.3.1, 3.3.2 | minor | HIGH | yes (additive) |
| 6 | `src/web/assets/form/dist/js/simple-form.js:775-784` | For radio/checkbox **groups**, the error is matched to `[name=field_X]` = the *first* option input, so `aria-invalid`/`aria-describedby` land on one radio rather than the group. The error text is still announced (`role="alert"`), and focus reaches the group, so impact is limited. | 3.3.1, 4.1.2 | minor | MED | yes |
| 7 | `src/fields/PhoneFieldType.php:247-256` | The dial-code `<select>` (id `<name>-country`) has **no label** — neither `<label for>` nor `aria-label`. The group label points at the number input; the country selector is an unlabelled combobox. | 1.3.1, 4.1.2, 3.3.2 | minor | HIGH | yes |
| 8 | `src/templates/_form/field.twig:27,29` | Required is conveyed by `required`/`aria-required` on the control (good) **and** a `<span class="required" aria-hidden="true">*</span>`. The asterisk is correctly hidden from AT — no failure — but there is no visible legend explaining "* = required". Non-blocking; noted for completeness (3.3.2 understanding). | 3.3.2 | minor | LOW | n/a |

### Already correct — not re-flagged
- Text / Textarea / Email / Number / Date / Select / Phone(number) / Hidden / element-relation single-select all route through `controlAttributes()` → carry `id` + `required`. `<label for="field_<id>">` matches. ✓
- Radio, Checkbox, Rating, OpinionScale, element-relation multi-select: each option has a unique `id` + explicit `<label for>`; the group is wrapped by `field.twig` in `role="group"` + `aria-labelledby` (the `<span class="simple-form-label" id="…-label">`). ✓
- Consent: `rendersOwnLabel()` true; its rich text **is** the `<label for>` of the single checkbox; `required` present. ✓
- Signature: `<canvas role="img" aria-label="…">`, a real `<button>` Clear control, and a `<noscript>` fallback. Hidden input carries `id`. Keyboard-operability of the *drawing* is inherently mouse/touch — acceptable given the noscript path; no AA failure for a drawn signature. ✓
- Submit-time errors: `role="alert"` error nodes (implicit `aria-live="assertive"`), `aria-invalid="true"` + `aria-describedby` on the control, focus moved to first invalid (or to a focusable general banner). Re-submit clears prior state. Messages added as text nodes (no injection). ✓ (`simple-form.js:701-799`)
- Multi-step progress region: `step-nav.twig:18` `<span role="status" aria-live="polite">` updated to "Step N of M". ✓
- Captcha: reCAPTCHA / hCaptcha / Turnstile are the vendors' own widgets — their a11y (audio challenge etc.) is provider-supplied and out of plugin scope. ✓
- Buttons (Back/Next/submit/clear/copy) are real `<button type=…>` with text content → accessible names. ✓

---

## HIGH-CONFIDENCE FIXES

All edits stay inside existing markup conventions and are behaviour-safe.

### Fix 1 — File field: add `id` (serious)
`src/fields/FileFieldType.php:166`

```php
// before
$attrs = sprintf('name="%s"', htmlspecialchars($this->isMultiple() ? $name . '[]' : $name));

// after  (id is always the field name; the [] only affects the posted name)
$attrs = sprintf(
    'id="%s" name="%s"',
    htmlspecialchars($name),
    htmlspecialchars($this->isMultiple() ? $name . '[]' : $name)
);
```
The group's `<label for="field_<id>">` now targets the input. The `name` (incl. `[]`) is unchanged, so posting/validation is identical.

### Fix 2 — Composite: emit the referenced `<legend>` (serious)
`src/fields/CompositeFieldType.php:147-149`

The fieldset already references `<name>-legend`, but never renders it. The field group's outer `<span class="simple-form-label" id="field_<id>-label">` is the human label. Point the fieldset at *that* existing id instead of a phantom one (no new visible markup, no duplicate label):

```php
// before
$legendId = htmlspecialchars($name) . '-legend';
$html = sprintf('<fieldset class="sf-composite" aria-labelledby="%s">', $legendId);

// after — reuse the group label id the renderer already emits (field.twig: field.labelId = "<name>-label")
$labelId = htmlspecialchars($name) . '-label';
$html = sprintf('<fieldset class="sf-composite" aria-labelledby="%s">', $labelId);
```
Now the fieldset's accessible name resolves to the real field label. (Confirmed: `FormRenderService::_resolveFieldRow()` sets `labelId = fieldName . '-label'`, and `field.twig` renders the span with that id for choice/composite fields since `isChoiceGroup()` is true.)

### Fix 3 — Multi-step: move focus into the new step (serious)
`src/web/assets/form/dist/js/simple-form.js` — make `render()` (or the Next/Back handlers) focus the step. Add `tabindex="-1"` so a non-interactive container is focusable, then focus the first control (or the step) after each transition.

```js
// in render(), after toggling visibility:
steps.forEach(function (s, i) { s.hidden = i !== current; });
// …existing button/progress updates…
```
Add, in the Next and Back click handlers, *after* `render()` (so focus only moves on user navigation, not on initial paint):
```js
var step = steps[current];
var firstControl = step.querySelector("input, select, textarea, button");
if (firstControl) {
    firstControl.focus();
} else {
    step.setAttribute("tabindex", "-1");
    step.focus();
}
```
Behaviour-safe: only fires on explicit Back/Next clicks; the polite progress region still announces "Step N of M".

### Fix 7 — Phone country selector: label it (minor)
`src/fields/PhoneFieldType.php:248-249` — add an `aria-label` to the dial-code `<select>` (a visible label would change the layout; an `aria-label` is the minimal, convention-matching fix):

```php
// before
. '<select name="%s" id="%s" class="sf-phone-country">%s</select>'

// after
. '<select name="%s" id="%s" class="sf-phone-country" aria-label="%s">%s</select>'
```
and add the label arg to the sprintf:
```php
htmlspecialchars($name . '[country]'),
htmlspecialchars($name . '-country'),
htmlspecialchars(Craft::t('simple-form', 'Country calling code')),  // new
$options,
```
(Requires `use Craft;` — already imported in this file.)

### Fix 5 — Help text association (minor, additive)
Two coordinated touches so help text is programmatically tied to the control without breaking the error wiring:

1. `field.twig:32` — give the help node a stable id and (for non-choice fields) reference it from the control. Since the control markup is produced in PHP, the simplest convention-preserving approach is to render the help id and let `controlAttributes()` opt in. Minimal template change:
```twig
{%- if field.helpText %}<small class="help-text" id="{{ field.fieldName }}-help">{{ field.helpText }}</small>{% endif -%}
```
2. In the JS error path (`simple-form.js:783`), preserve any existing `aria-describedby` (help id) instead of overwriting it:
```js
// before
fieldElement.setAttribute("aria-describedby", errorId);
// after
var prior = fieldElement.getAttribute("aria-describedby");
fieldElement.setAttribute("aria-describedby", prior ? prior + " " + errorId : errorId);
```
And `clearErrors()` (`simple-form.js:693-696`) must remove **only** the error id, not the whole attribute, if a help id is present. Given the added complexity, this one is reasonable to defer past launch — it is a minor (help text is still reachable via SR navigation). Listed for completeness.

### Fix 4 / Fix 6 — defer-able minors
- **Calc `<output>`**: add `aria-live="polite"` and label it, e.g. `<output … aria-label="<field label>">`. The field label isn't passed into `renderInput()`, so a clean fix needs the label threaded through — defer.
- **Choice-group error target**: to tie the error to the *group*, the JS could set `aria-invalid`/`aria-describedby` on the `[role="group"]` wrapper when the matched control sits inside one. Low impact (error is already announced via `role="alert"` and focus reaches the group) — defer.

---

## Notes for the implementer
- Fixes 1, 2, 3, 7 are the launch-relevant set (1 serious-label + 1 serious-name + 1 serious-focus + 1 minor-label) and are mechanical.
- The JS lives in `dist/` (built output). Confirm whether a source file feeds it (build step) before editing the dist artifact directly; the same change must go to source if one exists.
- Per project convention, every fix should ship with a smoke test in the same PR (e.g. a render assertion that `type="file"` carries `id`, that the composite fieldset's `aria-labelledby` resolves, and a Playwright check that focus lands inside the next step).
</content>
</invoke>
