# PRD — Field types: Content & layout blocks (Heading, Section divider, HTML)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#127](https://github.com/fabianhaef/craft-simple-form/issues/127)

---

## 1. Problem Statement

Every field type registered today (`TextFieldType`, `SelectFieldType`,
`FileFieldType`, …) collects and persists a submission value. There is no way for
a form creator to add **presentational content** to a form — a section heading to
break a long form into logical groups, a visual divider, or a paragraph of
explanatory copy / rich HTML between fields.

Creators currently abuse input fields (e.g. an empty-labelled textarea) or wrap
the whole form in their own Twig, which defeats the point of a builder. Long
forms read as an undifferentiated wall of inputs, hurting completion rates and
accessibility. Every mature form builder ships non-input "layout" blocks; Simple
Form has none.

The complication is that the entire pipeline assumes a field *has a value*:
`SubmissionService::submit()` validates each field and writes
`data['field_<id>'] = ['label' => …, 'type' => …, 'value' => …]`; the CSV/JSON
exporter (`helpers\SubmissionCsv`) emits one column per stored field; analytics
group on `OPTION_TYPES`. A presentational block must thread through all of this
*without* contributing a value, a column, or a validation error.

## 2. Goals

- Add three **non-input** field types that render content on the public form but
  capture **no submission value**:
  - **Heading** — a configurable heading level (`h2`–`h4`) with translatable text.
  - **Section / Divider** — a visual separator (`<hr>`) with an optional
    translatable label.
  - **HTML block** — author-supplied HTML/Twig, entered in the CP, rendered
    **safely** on the front end.
- Make these blocks first-class citizens of the drag-drop builder: they appear in
  the field-type palette, drag onto the canvas, and have their own property
  editor — but the property editor omits "required", validation, and any
  value-bearing settings.
- Guarantee they are **skipped by validation, storage, and export** — a layout
  block never appears in `submission.data`, never produces a column, never blocks
  submission.
- Keep the HTML block **safe by default**: non-admin authors cannot inject
  arbitrary unsafe Twig or `<script>`; the plugin's existing safe-render approach
  governs what runs.
- Per-site translatable text/labels, consistent with every other field type.
- No new tables; no breaking changes; PHPStan L7 + ECS clean.

## 3. Non-Goals (v1)

- Rich-text WYSIWYG editing for the HTML block — v1 is a raw HTML/Twig textarea
  (Redactor/CKEditor integration can come later).
- Columns / multi-column layout grids. These blocks stack vertically like every
  other field.
- Repeating/group containers. A divider is a marker, not a wrapping container.
- Conditional show/hide of layout blocks. (They *may* inherit conditionals for
  free via `FieldModel::isVisible()`; if so, it's a bonus, not a v1 requirement —
  see Open Questions.)
- Image/asset embeds beyond what an author writes in the HTML block themselves.

## 4. Users & Use Cases

- **Form creator (CP author):** splits a 20-field application form into
  "Personal details", "Employment", "References" sections with Heading blocks;
  adds a divider before the consent checkbox; drops an HTML block with a link to
  the privacy policy and a short instruction paragraph.
- **Marketer:** adds a styled call-to-action / reassurance paragraph above the
  submit button via the HTML block.
- **Form filler (public visitor):** reads clear section headings and guidance,
  improving comprehension and completion — but is never asked to "fill in" a
  heading, and screen readers announce headings as landmarks, not form controls.

## 5. Proposed Solution

### 5.1 A `isInput()` marker on `FieldType`

Add a single non-abstract method to `fabianhaef\simpleform\fields\FieldType`:

```php
/**
 * Whether this field collects a submission value. Presentational/layout
 * blocks (heading, divider, html) return false: they render but are never
 * validated, stored, or exported.
 */
public function isInput(): bool
{
    return true;
}
```

All existing field types inherit `true` (no behaviour change). The three new
types override it to `false`. This is the one seam the rest of the pipeline keys
off, so the change stays surgical.

### 5.2 The three field types

New classes under `src/fields/`, each extending `FieldType`, registered in
`FieldTypeRegistry::init()`:

- `HeadingFieldType` (`getType() => 'heading'`)
  - Config: `level` (`h2`|`h3`|`h4`, default `h3`), `text` (translatable).
  - `renderInput()` returns `"<{level}>…</{level}>"` with `htmlspecialchars($text)`.
  - `isInput()` → `false`. `validate()` → `[]` (never reached, but defensive).
- `DividerFieldType` (`getType() => 'divider'`)
  - Config: `label` (optional, translatable).
  - `renderInput()` returns `<hr class="sf-divider">` plus an optional
    `<span class="sf-divider__label">` when a label is set.
- `HtmlFieldType` (`getType() => 'html'`)
  - Config: `html` (raw HTML/Twig string, translatable).
  - `renderInput()` returns the **safely-rendered** result (see 5.4).

`renderInput(string $name, mixed $value = null)` ignores `$name`/`$value` for all
three — there is no input. They do **not** override `controlAttributes()` and emit
no `name=` attribute, so nothing posts.

### 5.3 Pipeline must skip non-input fields

The `isInput()` seam is honoured in exactly the places that assume a value:

- **`SubmissionService::submit()`** — in the per-field loop that builds `$data`,
  `continue` when `!$field->isInputType()`. (`FieldModel` gains a thin
  `isInputType(): bool` that resolves the field type via the registry and calls
  `isInput()`, mirroring how `isVisible()`/`isRequired()` already delegate.) A
  layout block therefore never lands in `submission.data`, is never validated,
  and can never produce a `field_<id>` error.
- **`SubmissionService::createFromRequest()`** — the value-collection loop skips
  non-input fields too, so a crafted POST of `field_<id>` for a heading is
  ignored.
- **Builder render / public form** — the field still renders (it's
  presentational); only the *data path* skips it.
- **Export** (`helpers\SubmissionCsv`, `SubmissionExporter`) needs **no change**:
  it iterates `submission.data`, and layout blocks were never written there, so
  no phantom columns appear.
- **Analytics** (`ReportsService`) keys off `OPTION_TYPES`; the new types are not
  option types, so they are naturally excluded.

### 5.4 Safe rendering of the HTML block (security)

The HTML block is the only sensitive surface. Requirements:

- **Trust tiers.** Authoring the `html` config requires a dedicated permission
  (`simpleForm:editHtmlBlocks`, see Open Questions) so that only trusted CP users
  can place raw markup. Lower-privileged form editors can move/delete an existing
  HTML block but not edit its body.
- **Rendering.** Twig in the block is rendered through the plugin's **existing
  safe-render approach** — the sandboxed Twig path (`renderSandboxedString` with
  the plugin's `SandboxExtension` + custom `SecurityPolicy`, per the project's
  Twig-sandbox reference). The sandbox is a real no-op unless explicitly forced,
  so this PRD requires the block to go through the forced-sandbox helper, never a
  plain `renderString`/`renderTemplate`.
- **Output filtering.** After Twig render, the resulting HTML is passed through
  an allowlist HTML purifier (Craft ships `craft\helpers\Html`/HTMLPurifier) that
  strips `<script>`, inline event handlers (`onclick=…`), `javascript:` URLs, and
  `<style>`. The allowlist (tags + attributes) is a documented constant on
  `HtmlFieldType`.
- **No element/template traversal.** The sandbox `SecurityPolicy` denies access
  to `craft.app`, file-system functions, and arbitrary method calls, so a block
  cannot read other elements, run queries, or reach the database.
- **Front-end caching note.** Because the block can contain Twig, its output is
  rendered at form-render time within the form's existing render path; it is not
  pre-baked into a static column.

### 5.5 Builder UX

In `src/web/assets/cp` (the `form-builder.js` bundle) and `templates/forms/edit.html`:

- A **"Layout"** group in the field-type palette holds Heading, Divider, HTML.
- Dragging one onto the canvas opens the per-field property editor, which is
  **driven by the field type's declared settings** — for layout blocks it shows
  only the relevant inputs (level/text, label, or the HTML textarea) and
  **omits** the Required toggle, validation message, and placeholder.
- On the canvas a layout block renders a non-interactive preview (the heading
  text, an `<hr>`, or a "HTML block" chip) so it is visually distinct from input
  fields.
- The add-field modal labels them clearly (`Craft::t('simple-form', 'Heading')`,
  etc.) and the HTML option is hidden/disabled for users lacking the
  `editHtmlBlocks` permission.

### 5.6 Multi-site / translatable

`text`, `label`, and `html` are stored in the per-site translatable config
exactly like field labels and option labels are today (resolved per-site by
`FormStructureService`). A French site can carry French heading text and a
localized HTML block.

## 6. Acceptance Criteria

- [ ] `FieldType::isInput()` exists, defaults to `true`, and all existing types
      inherit it unchanged.
- [ ] `HeadingFieldType`, `DividerFieldType`, `HtmlFieldType` exist, extend
      `FieldType`, override `isInput()` → `false`, and are registered in
      `FieldTypeRegistry::init()`.
- [ ] Heading renders `<h2>`/`<h3>`/`<h4>` per config with escaped translatable
      text; level is constrained to the allowed set.
- [ ] Divider renders `<hr>` plus an optional translatable label.
- [ ] HTML block renders author content through the forced-sandbox Twig path +
      HTML allowlist purifier; `<script>`, inline handlers, and `javascript:`
      URLs are stripped; `craft.app`/queries are inaccessible from the sandbox.
- [ ] Editing an HTML block's body requires the `editHtmlBlocks` permission;
      users without it cannot create or edit one (but can reorder/delete).
- [ ] A submission to a form containing all three blocks writes **no**
      `field_<id>` entry for any of them in `submission.data`.
- [ ] CSV/JSON export of such submissions contains **no** column for any layout
      block; existing exports are byte-for-byte unchanged.
- [ ] Layout blocks never produce a validation error and never block submission,
      even if a `required`-looking config is forged in the POST.
- [ ] All three appear in a "Layout" palette group; their property editor omits
      Required/validation/placeholder.
- [ ] Translatable text/label/html resolve per-site.
- [ ] PHPStan L7 + ECS clean; no new DB tables; no breaking change to existing
      forms or submissions.

## 7. Testing

**Unit (PHPUnit):**
- `HeadingFieldType`: escapes text; clamps `level` to `h2`–`h4` (invalid →
  default); `isInput()` false; `validate()` returns `[]` regardless of input.
- `DividerFieldType`: renders `<hr>`; label optional and escaped.
- `HtmlFieldType`: a payload with `<script>`, `onerror=`, and `{{ craft.app.… }}`
  renders with the script/handler stripped and the `craft.app` access neutralised
  (sandbox denies it); a benign `<p>{{ name }}</p>`-style block renders allowed
  tags.
- `FieldModel::isInputType()` returns false for the three types, true for inputs.
- A `submit()` over a form mixing inputs + layout blocks: assert `submission.data`
  has keys only for the input fields.
- `SubmissionCsv::toRows()` over those submissions: assert no layout columns.

**craft-smoke-test scenarios (ship in same PR):**
- Build a form in the CP with a Heading, a Divider, an HTML block, and two inputs;
  save; verify the palette "Layout" group and that the HTML block's editor lacks a
  Required toggle.
- Render the public form: assert the `<h3>`, `<hr>`, and purified HTML appear in
  order between the inputs.
- Submit the form with valid input values; open the submission detail in the CP:
  assert only the two input values are shown, no heading/divider/html rows.
- Export the submission to CSV: assert columns are exactly metadata + the two
  input labels.
- As a CP user lacking `editHtmlBlocks`, confirm the HTML option is unavailable.

## 8. Open Questions

- **Permission name & granularity.** Is a dedicated `editHtmlBlocks` permission
  warranted, or do we gate HTML editing on admin-only? Proposed: a plugin
  permission so non-admin trusted editors can be granted it.
- **Conditionals on layout blocks.** Should a Heading be hideable via the existing
  conditional engine (`FieldModel::isVisible()`)? It may work for free; decide
  whether to expose the conditional UI for non-input blocks in v1.
- **Heading levels & a11y.** Constrain to `h2`–`h4` to avoid breaking document
  outline (the form likely sits under an `<h1>`). Confirm the range with the
  default front-end template.
- **HTML allowlist scope.** Final tag/attribute allowlist — do we permit `<img>`
  / `<a target=…>`? Lean permissive-but-safe (no script vectors).
- **Twig vs. plain HTML toggle.** Should the block default to plain HTML (no Twig
  evaluation) and require an explicit opt-in to Twig, reducing the attack surface
  for most authors?
