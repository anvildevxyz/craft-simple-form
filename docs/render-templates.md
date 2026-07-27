# Custom Render Templates (Theming & Overrides)

Simple Form renders its front-end markup through a set of overridable Twig
partials (a "theme"). You can restyle a form, replace its wrappers, or hand-author
the markup entirely — without forking the plugin and without re-implementing
CSRF, the honeypot, captcha, multi-step, conditional logic or save-&-resume.

With **no** custom template path configured, the output is identical to the
plugin's built-in default.

## Quick start

1. Create a folder of partials in your site's `templates/` directory, e.g.
   `templates/_simple-form/`.
2. Drop in only the partials you want to override (see the contract below). Any
   partial you don't ship falls through to the plugin's built-in.
3. Point a form at the folder, either globally or per form:
   - **Globally:** Settings → General → *Default render template path* =
     `_simple-form`.
   - **Per form:** the form's edit screen → *Custom template path* =
     `_simple-form/landing`.

## Resolution order

For each partial, Simple Form looks it up most-specific first:

1. **Per-render** — the `theme` render option (see below), if passed.
2. **Per-form** — the form's `templatePath`.
3. **Global** — the `templatePath` plugin setting.
4. **Plugin built-in** — `src/templates/_form/` (registered as the
   `simple-form/*` site-template root).

Resolution is **per partial**, not all-or-nothing. A theme that ships only
`field.twig` keeps the built-in `form.twig`, which includes your overridden
`field.twig`. An unset or invalid path logs a `simple-form` warning and falls
back to the built-in — it never throws on a public page.

The lookup runs against the **current site's** templates root, so it is
multi-site safe. The template path itself is shared across sites (it is
structural, not translatable content).

## The partial contract

| Partial | Role |
| --- | --- |
| `form.twig` | The `<form>` wrapper: attributes, CSRF/honeypot/handle, the field/step loop, submit/next/back/save-resume buttons, captcha + asset slots. |
| `field.twig` | One field group: label/required marker, help text, `data-sf-handle` / `data-sf-conditional`, the `input-wrapper`, choice-group `role="group"`/`aria-labelledby`. Includes `input.twig`. |
| `input.twig` | The control wrap point. The control HTML itself is produced by the field type's PHP and exposed as `field.input` (Markup). |
| `errors.twig` | Per-field / form-level error list (the no-JS POST round-trip and hand-authored layouts). |
| `step-nav.twig` | The multi-step Back / Next / progress / save-resume controls. |
| `captcha.twig` | The captcha slot. `captcha` is pre-rendered Markup, or `''` when disabled. |
| `assets.twig` | The CSS/JS slot. Override with an **empty file** to suppress the plugin's assets entirely. |
| `form-start.twig` | Opening `<form>` + CSRF + honeypot + hidden handle (for `formStart()`). |
| `form-end.twig` | Captcha + submit + assets + `</form>` (for `formEnd()`). |

### Variables passed to `form.twig`

```
form           Form element (handle, title, description, allowSaveResume, …)
handle         the form handle (string)
fields         resolved field rows (see "field row" below)
steps          list of field groups (FormSteps grouping; >1 = multi-step)
options        the caller's render options (submitText, class, id, attributes, theme)
submitText     translated submit-button text
formClass      space-joined class string ("simple-form …")
formId         extra id on <form>, or null
extraAttributes  map of extra attributes for <form>
action         the submit endpoint URL
hasFileField   whether a multipart enctype is needed
errorMessage   the configured (localized) error message
csrfInput      Markup — place verbatim, do not modify
honeypot       Markup|'' — pre-rendered hidden input, or '' when disabled
captcha        Markup|'' — the provider widget
assets         Markup|'' — inline CSS/JS, or '' when registered as a bundle
resume         { enabled, values, token, url, labels{…} } or null
partials       map of resolved partial paths (used by the built-in includes)
```

The security-sensitive values (`csrfInput`, `honeypot`, `captcha`) are
pre-rendered Markup: place them where you like, but don't try to rebuild them.

### The "field row" passed to `field.twig`

```
id            field id
type          field type handle (text, email, select, …)
name          the field handle (used for data-sf-handle + conditional matching)
label         resolved (per-site) label
helpText      resolved (per-site) help text
required      bool
isChoice      bool — radio/checkbox group (role="group") vs. single control
fieldName     the input name/id ("field_<id>")
labelId       the id for the choice-group label span
conditional   decoded conditional config, or null
input         Markup — the rendered control (already carrying any resume value)
```

## Render-granularity API

```twig
{# Whole form. Options: submitText, class, id, attributes, theme #}
{{ craft.simpleForm.form('contact', { class: 'my-form', submitText: 'Send' }) }}

{# Force the built-in for this one render, ignoring per-form/global paths #}
{{ craft.simpleForm.form('contact', { theme: '' }) }}

{# Hand-authored single-step form — the plugin still emits the plumbing #}
{{ craft.simpleForm.formStart('contact') }}
    {{ craft.simpleForm.field('contact', 'name') }}
    {{ craft.simpleForm.field('contact', 'email') }}
{{ craft.simpleForm.formEnd('contact') }}
```

- `formStart()` emits the opening `<form>` tag, CSRF, honeypot and the hidden
  `formHandle`.
- `field(handle, fieldHandle)` renders one field group via `field.twig`, keeping
  its required/conditional `data-*` attributes.
- `formEnd()` emits the captcha, submit button, assets and the closing `</form>`.

`formStart`/`formEnd` are **single-step only**. For multi-step forms use the
whole-form `craft.simpleForm.form()`, which renders the step navigation through
`step-nav.twig`.

## Assets

By default the form CSS/JS load as a versioned, cache-bustable
[`FormAsset`](../src/web/assets/form/FormAsset.php) bundle (only on pages that
render a form). The `inlineFormAssets` setting switches to inline output. Either
way, a fully-themed build can suppress the plugin assets by shipping an **empty**
`assets.twig` override and providing its own CSS/JS.

## Front-end JavaScript events

The bundled script dispatches namespaced `CustomEvent`s on the `<form>` element
at each lifecycle moment, so a host page can observe (and, where cancelable,
veto) the form without forking the script. All events bubble.

| Event | Cancelable | `detail` |
| --- | --- | --- |
| `simpleform:beforeSubmit` | **yes** — `preventDefault()` aborts the AJAX send | `{ form, formData }` |
| `simpleform:afterSubmit` | no | `{ form, success, data }` (the parsed JSON response; fires on success *and* validation failure) |
| `simpleform:validationFailed` | no | `{ form, errors }` (server error map, keyed by `field_<id>` / `form`) |
| `simpleform:stepChange` | no | `{ form, from, to, total }` (multi-step navigation) |

```js
const form = document.querySelector('.simple-form');

// Veto / gate submission (e.g. a custom client-side check or analytics consent).
form.addEventListener('simpleform:beforeSubmit', (e) => {
    if (!window.myConsentGiven) { e.preventDefault(); }
});

// React to the outcome.
form.addEventListener('simpleform:afterSubmit', (e) => {
    if (e.detail.success) { dataLayer.push({ event: 'form_submit' }); }
});

form.addEventListener('simpleform:stepChange', (e) => {
    console.log(`step ${e.detail.from} → ${e.detail.to} of ${e.detail.total}`);
});
```

These fire only when the bundled script runs; a theme that ships an empty
`assets.twig` and its own JS is responsible for its own events.
