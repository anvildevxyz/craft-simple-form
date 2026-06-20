# PRD — Custom Render Templates (Theming & Overrides)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#137](https://github.com/fabianhaef/craft-simple-form/issues/137)

---

## 1. Problem Statement

The front-end form HTML is built as a hard-coded string in `TwigExtension::renderForm()`
and `TwigExtension::renderFieldGroup()`. Every wrapper, class name, label and input is
concatenated in PHP (`src/TwigExtension.php`). A developer who wants the form to match
their design system today has exactly two options, both bad:

- Override CSS against the fixed `.simple-form`, `.simple-form-group`, `.input-wrapper`
  class hooks — fine for colours, useless when the markup itself is wrong (e.g. a CSS
  framework that needs `<div class="field">` wrappers, floating labels, or grouped
  rows).
- Fork the plugin or stop using `{{ craft.simpleForm.form() }}` entirely and hand-build
  the `<form>` from the PHP API, re-implementing CSRF, honeypot, captcha, multi-step,
  conditional-logic data attributes and save-&-resume by hand.

For headless and design-led builds this is a dealbreaker. Formie and Freeform both let
you point a form at a template directory and override individual partials. Simple Form
must offer the same without forcing a fork.

## 2. Goals

- Let a developer override how a form (and any individual field) renders by dropping
  Twig partials into their own `templates/` directory — no plugin fork, no loss of
  CSRF/honeypot/captcha/multi-step/conditional/save-resume behaviour.
- A documented **template resolution order**: per-form custom path → global default
  path (plugin setting) → plugin built-in partials.
- A documented **partial contract**: the exact set of partials a theme may override and
  the variables each receives.
- Render-granularity options on the Twig API: whole form, a single field, or just the
  opening / closing form tags (for fully hand-authored field markup that still wants the
  plugin's plumbing).
- Coexist cleanly with the existing `FormAsset` bundle / `inlineFormAssets` escape hatch
  (`src/web/assets/form/FormAsset.php`): a theme can keep, replace, or suppress the
  bundled CSS/JS.
- 100% backward compatible: a form with no custom template path renders byte-compatibly
  with today's output.

## 3. Non-Goals (v1)

- A visual / drag-and-drop theme editor in the CP. Themes are Twig files on disk.
- Shipping more than the existing default theme. We refactor the current markup into
  partials; we do not add alternate built-in themes (Bootstrap/Tailwind presets) — that
  is a follow-up.
- Per-field-type template overrides keyed by individual field *handle* via the CP UI.
  Resolution is by field *type* and by convention on disk, set in code/templates.
- Changing the CP form-builder preview rendering.
- Project-config syncing of the per-form template path (covered as an open question).

## 4. Users & Use Cases

- **Agency front-end dev (headless/Twig site):** wants the contact form wrapped in their
  design-system field component. Sets `templates/_simple-form/` as the global theme path
  and overrides `form.twig` + `field.twig`.
- **Single-form custom layout:** one marketing landing page needs a two-column form with
  bespoke markup; sets a per-form template path on just that form and overrides nothing
  else.
- **Mostly-hand-authored form:** developer wants total control over field markup but
  still wants the plugin to emit CSRF, honeypot, the hidden `formHandle`, captcha and the
  closing tag. Uses `craft.simpleForm.formStart(handle)` / `formEnd(handle)` and
  `craft.simpleForm.field(handle, fieldHandle)` between them.

## 5. Proposed Solution

### 5.1 Refactor the PHP string builder into Twig partials

Extract the markup currently inlined in `TwigExtension::renderForm()` /
`renderFieldGroup()` into a default theme shipped under `src/templates/_form/`:

| Partial | Role |
| --- | --- |
| `form.twig` | The `<form>` wrapper: enctype, data-attrs, CSRF/honeypot/handle, the step loop, submit/next/back/save-resume buttons, captcha slot, asset slot. Loops `field.twig`. |
| `field.twig` | One field group: label/required marker, help text, `data-sf-handle`/`data-sf-conditional`, the `input-wrapper`, choice-group `role="group"`/`aria-labelledby`. Includes `input.twig`. |
| `input.twig` | Dispatches to the field type's own `renderInput()` (still PHP for now) — the seam where a theme can wrap/replace the control. |
| `errors.twig` | Per-field and form-level error rendering (used on the no-JS POST round-trip). |
| `step-nav.twig` | The multi-step Back / Next / progress / save-resume controls. |
| `captcha.twig`, `assets.twig` | Slots so a theme can move or suppress them. |

`TwigExtension::renderForm()` becomes: resolve the field set + per-request bits exactly
as today (CSRF, honeypot, captcha nonce, resume token, steps via
`FormSteps::group()`), assemble a **render context** array, then
`Craft::$app->getView()->renderTemplate('_form/form', $context)` using the resolved
theme root (see 5.2). The per-request, security-sensitive values (CSRF input, honeypot,
captcha markup, resume token) are passed in as pre-built `Markup` so a theme can place
but not tamper with them.

The structure cache (`FormStructureService::getFieldSet()`) is unaffected — only the
*structure* is cached; rendering still happens per request, now via templates.

### 5.2 Template resolution order

A `resolveThemeRoot(Form $form): string` helper resolves, most-specific first:

1. **Per-form path** — `Form::$templatePath` (new nullable shared column,
   `simpleform_forms`), e.g. `_simple-form/landing`. If set and the partial exists in
   the site's `templates/` root, use it.
2. **Global default path** — `Settings::$templatePath` (new property on
   `src/models/Settings.php`), e.g. `_simple-form`. Applies to every form.
3. **Plugin built-in** — `_form/` under the plugin's own template root (registered the
   way `src/templates` already is).

Resolution is **per partial**, not all-or-nothing: a theme that only ships `field.twig`
falls through to the plugin's `form.twig`, which `{% include %}`s the overridden
`field.twig` because the site templates root is searched first. We implement this by
rendering through the site View (so the front-end templates root wins) and registering
the plugin `_form/` directory as a fallback root, mirroring how Craft resolves
`{{ block }}` overrides. A `templatePath` that points nowhere logs a `Craft::warning`
(category `simple-form`) and falls back to the default — never a hard error on a public
page.

### 5.3 The partial contract (variables)

Documented in `docs/` and in a doc-comment on the render context builder. `form.twig`
receives:

```
form          Form element (handle, title, description, allowSaveResume, …)
fields        list of resolved field rows (id, type, name/handle, label, helpText,
              config, optionLabels) — same shape FormStructureService returns
steps         list of field groups (FormSteps::group result)
options       the caller's render options (submitText, class, id, attributes, theme)
csrfInput     Markup — pre-rendered, place verbatim
honeypot      Markup|'' — pre-rendered hidden input or empty when disabled
captcha       Markup|'' — provider widget
assets        Markup|'' — '' when registered as a bundle, inline <style>/<script>
              when inlineFormAssets
action        the submit endpoint URL
resume        { enabled, url, token, labels{…} } or null
```

`field.twig` receives a single resolved field row plus `values` (resume prefill),
`isChoice`, `labelId`, `fieldName` (`field_<id>`), and the decoded `conditional` config.

### 5.4 Render-granularity API (Twig)

Extend `SimpleFormVariable` (`src/web/twig/variables/SimpleFormVariable.php`) and the
`simpleForm()` function with documented `options`:

- `craft.simpleForm.form(handle, options)` — whole form. New options:
  `theme` (override template path for this render), `class` / `id` /
  `attributes` (extra attrs on `<form>`), `submitText`.
- `craft.simpleForm.formStart(handle, options)` → `Markup` — opening `<form …>` + CSRF +
  honeypot + hidden `formHandle` only.
- `craft.simpleForm.formEnd(handle)` → `Markup` — captcha + submit (+ step nav for
  multi-step) + assets + `</form>`.
- `craft.simpleForm.field(handle, fieldHandle, options)` → `Markup` — one field group via
  `field.twig`, so hand-authored forms keep conditional/required data-attrs.

`formStart`/`formEnd` reuse the exact per-request builders so security plumbing is never
re-implemented by hand. Multi-step + hand-authored layout is explicitly **unsupported**
in v1 (formStart/formEnd assume single-step) and documented as such.

### 5.5 CP surface

- **Global default template path:** a text field on Settings → General
  (`src/templates/settings/_tabs/general.html`), bound to `Settings::$templatePath`,
  with help text and a "leave blank for built-in" note. Autosuggest is nice-to-have.
- **Per-form template path:** a text field in the form edit screen
  (`src/templates/forms/edit.html`), bound to `Form::$templatePath`. Shared (not
  translatable) — markup is structural, not content.

### 5.6 Standards

- New columns: `simpleform_forms.templatePath` (nullable string) via a new migration;
  `Settings::$templatePath` is config-only (no column). Migration registered in
  `codeception.yml` and the test DB reset per the test-DB-snapshot reference.
- All new strings via `Craft::t('simple-form', …)`; default partials emit no
  user-visible English outside translated buttons/labels already in the catalogs.
- PHPStan L7 + ECS clean. No raw SQL added; the new column uses Craft schema builder.
- Multi-site safe: resolution uses the current site's templates root; `templatePath` is
  shared across sites by design.

## 6. Acceptance Criteria

- [ ] The default markup is refactored into `src/templates/_form/*.twig` and a form with
      no custom path renders byte-identical (or trivially-whitespace-equivalent) output
      to the pre-change `TwigExtension`.
- [ ] A site partial at `templates/_simple-form/field.twig` overrides only the field
      group; `form.twig` falls through to the plugin default and still includes the
      override.
- [ ] `Settings::$templatePath` is honoured globally; `Form::$templatePath` overrides it
      per form; an unset/invalid path falls back without erroring on a public page (logs
      a warning).
- [ ] `craft.simpleForm.formStart()` + fields + `craft.simpleForm.formEnd()` produces a
      working single-step form: CSRF, honeypot, hidden `formHandle`, captcha and submit
      all present; a real submission succeeds.
- [ ] `craft.simpleForm.field(handle, fieldHandle)` renders a single field group carrying
      its `data-sf-handle` and (when configured) `data-sf-conditional`.
- [ ] `theme` render option overrides the template path for that one render only.
- [ ] `FormAsset` registration / `inlineFormAssets` behaviour is unchanged; a theme can
      suppress assets via an empty `assets.twig` override.
- [ ] Multi-step, conditional logic and save-&-resume continue to work through the
      default partials.
- [ ] Per-form + global template-path fields appear in the CP and persist.
- [ ] PHPStan L7 + ECS clean; new migration registered in `codeception.yml`.

## 7. Testing

### Unit / functional

- `ThemeResolutionTest` — `resolveThemeRoot()` precedence (per-form → global → built-in),
  per-partial fallthrough, and warning-on-missing behaviour.
- `RenderContextTest` — the context array built by `renderForm()` contains the documented
  keys with the right types (`csrfInput`/`captcha`/`assets` are `Markup`).
- `RenderParityTest` — render a representative form (text + select + checkbox + file +
  conditional + multi-step) through the partials and assert structural parity with a
  golden snapshot of the legacy string output.
- `FormStartEndTest` — `formStart`/`formEnd`/`field` emit CSRF, honeypot, hidden handle,
  captcha and the closing tag; conditional data-attrs survive on `field()`.

### craft-smoke-test scenarios

- "Override the field partial": create `templates/_simple-form/field.twig` that wraps the
  group in `<div class="my-field">`, set the global template path, render a form on a
  test entry, assert the custom wrapper is in the DOM and a submission still saves.
- "Per-form theme beats global": two forms, only one with a `templatePath`; assert each
  renders from the expected theme.
- "Hand-authored form still submits": a Twig template using `formStart`/`field`/`formEnd`;
  submit it and assert a `Submission` row is created and the notification fires.
- "Invalid path degrades gracefully": set a bogus `templatePath`, assert the page renders
  (built-in fallback) and a `simple-form` warning is logged, not a 500.

## 8. Open Questions

- Should `Form::$templatePath` sync via **project config**? Forms are elements (content),
  not currently project-config-managed; the template path is structural and a candidate
  for PC, but mixing one PC column into an otherwise DB-managed element is awkward. Lean:
  keep it on the element for v1, revisit if forms move to PC wholesale.
- Do we expose a `RegisterFormTemplateRootsEvent` so other plugins can contribute theme
  roots, or is the two-level (site → plugin) resolution enough for v1? Lean: defer.
- Should `formStart`/`formEnd` support multi-step in v1, or is single-step-only an
  acceptable limitation given the whole-form path already handles steps? Lean:
  single-step only, documented.
- Field-type input markup is still PHP (`renderInput()`). Do we move per-type inputs to
  Twig partials too (`input/text.twig`, `input/select.twig`, …) in v1, or keep the PHP
  seam and defer? Lean: keep the PHP seam, expose `input.twig` as the wrap point only.
