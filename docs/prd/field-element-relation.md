# PRD — Field types: Element relation (Entries / Categories / Tags / Users / Assets)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#130](https://github.com/fabianhaef/craft-simple-form/issues/130)

---

## 1. Problem Statement

Simple Form lives inside Craft, where the most interesting data is **other
elements** — entries, categories, tags, users, assets. Yet a form can only ask a
visitor for free text or a hand-typed list of options. There is no way to ask
"which product is this enquiry about?" (an Entry), "pick a category", "which of
our team should this reach?" (a User), or "choose from the media library" (an
Asset) and have the answer be a **real relation** to the chosen element rather
than a copied-out string.

Today a creator approximates this by manually maintaining a `select` whose options
mirror an entry section — duplicating content, drifting out of sync, and storing
a label string instead of a stable element id. Native element relations are a
defining Craft capability; a form builder that ignores them feels un-Craft-like.

## 2. Goals

- Field types that let a visitor **select one or more Craft elements**, with a
  configurable **element type** (Entry / Category / Tag / User / Asset) and
  **source/section**, **single vs. multi**, and an optional **limit**.
- **CP preview** renders the native Craft **element-select** input (the real
  relational selector authors know).
- **Public front end** renders an appropriate, no-JS-safe control — a `<select>`
  (single) or checkboxes (multi) populated from the allowed source — so visitors
  on the public form get a sensible widget without the CP element modal.
- Store the **related element ids** in `submission.data`; **validate server-side**
  that every selected id belongs to the configured allowed source.
- Surface relations in the **submission detail** (linked element titles) and
  **export** (titles / ids), and expose them in **GraphQL** for headless forms.
- Multi-site safe (cross-site element queries via `siteId('*')` where needed);
  translatable labels; PHPStan L7 + ECS clean; no new tables; no breaking changes.

## 3. Non-Goals (v1)

- Creating new elements inline from the form (e.g. "add a new category"). v1 only
  *selects* existing elements.
- Editing the related element's own fields from within the form.
- True Craft relation-field DB rows (`relations` table) for the submission. v1
  stores ids in the existing JSON `data` payload, keeping the no-new-table rule;
  the ids are resolvable to live elements at read time.
- Cross-element-type pickers in a single field (one field = one element type).
- Advanced source criteria beyond "element type + source/section(s)" (no custom
  element-query conditions in v1).
- Public-facing element *search/autocomplete* for very large sources (v1 renders
  the allowed set as options; see Open Questions for large-source handling).

## 4. Users & Use Cases

- **Sales enquiry form:** "Which product are you interested in?" → an **Entry**
  relation limited to the `products` section, single-select.
- **Support form:** "Which categories apply?" → a **Category** relation, multi,
  limit 3.
- **Internal routing:** "Assign to" → a **User** relation limited to a group.
- **Press / asset request:** "Which images?" → an **Asset** relation from a
  specific volume, multi.
- **CP author building the form:** configures element type + source in the builder
  and previews the real Craft element selector.
- **Form owner reviewing a submission:** sees the chosen items as **linked
  titles**, click-through to the elements; exports get titles/ids.

## 5. Proposed Solution

### 5.1 Field types per element type

Rather than one mega-field, register a small family that share a common base, so
each reads clearly in the palette and the registry. New `src/fields/` classes,
all extending a new abstract `ElementRelationFieldType extends FieldType`:

- `EntryRelationFieldType` (`getType() => 'entry'`)
- `CategoryRelationFieldType` (`getType() => 'category'`)
- `TagRelationFieldType` (`getType() => 'tag'`)
- `UserRelationFieldType` (`getType() => 'user'`)
- `AssetRelationFieldType` (`getType() => 'asset'`)

Each declares its Craft element class (`craft\elements\Entry`, `Category`, `Tag`,
`User`, `Asset`) and is registered in `FieldTypeRegistry::init()`. The shared base
holds the config schema, validation, and option-resolution logic.

**Config** (per field, stored in the JSON `config` column):
- `sources` — list of allowed source handles (section handles for entries, group
  handles for categories/tags/users, volume handles for assets), or `'*'` for any
  source of that type.
- `multiple` (bool) — single vs. multi select.
- `limit` (int|null) — max selectable when multiple.

### 5.2 Allowed-source query (the source of truth)

The base provides `allowedElementQuery(): ElementQueryInterface` that builds the
element query constrained to the configured sources, and
`allowedIds(): list<int>` for membership checks. Because Employee/non-localized
gotchas don't apply here but multi-site does, the query uses `->siteId('*')` when
resolving membership so a form on a non-primary site still validates ids that live
on another site (consistent with the project's multi-site rule), while the
*rendered options* are resolved for the form's current site for correct titles.

### 5.3 Validation (server-side membership)

`ElementRelationFieldType::validate()`:
1. `parent::validate()` for the required check (empty selection + required →
   required error).
2. Normalize the posted value to a list of ints (single → `[id]`, multi → array).
3. **Membership:** every id must be in `allowedIds()`. This is the element-id
   analogue of `validateOptionMembership()` — a keyed `isset` against the allowed
   set — emitting `Craft::t('simple-form', 'Please select a valid option.')` on
   any out-of-source id. A crafted POST referencing an entry from a disallowed
   section, a soft-deleted element, or a non-existent id is rejected.
4. **Limit:** when `multiple` and `limit` is set, more than `limit` ids → error.
5. **Count:** when not `multiple`, more than one id → error.

Validation runs through the same `FieldModel::validateValue()` →
`FieldType::validate()` path as every other field, so AJAX `SubmitController` and
the GraphQL mutation are identical.

### 5.4 Rendering

- **CP preview** (builder canvas / form edit screen): render Craft's native
  **relational element-select** input (`craft.app.elements` element selector,
  e.g. via `Cp::elementSelectFieldHtml()` / `_includes/forms/elementSelect`),
  scoped to the configured element type + sources, so authors see and trust the
  real selector.
- **Public front end** (`renderInput()`): a no-JS-safe control populated from
  `allowedElementQuery()`:
  - **single** → `<select>` of `id => title` (reusing the `SelectFieldType`
    rendering shape / shared control attributes).
  - **multiple** → a checkbox group (`name="field_<id>[]"`), reusing the
    `CheckboxFieldType` a11y pattern (unique ids, `<label for>`, group
    `aria-labelledby`, `isChoiceGroup()` → true).
  - The option label is the element **title**; the value is the element **id**.
  - For Assets the option may render a filename; for Users a username/fullname.
- A headless front end ignores the rendered control and drives the field via
  GraphQL (5.7).

### 5.5 Storage & submission data

The chosen ids are stored in the existing payload:
`data['field_<id>'] = ['label' => …, 'type' => 'entry'|…, 'value' => [<id>, …]]`.
Single-select still stores a one-element list for a uniform read path (mirroring
the File field, which stores an id list). No new table; ids are resolved to live
elements at read time.

### 5.6 Submission detail + export

- **CP submission detail** (`templates/submissions`): resolve each stored id to
  its element (`siteId('*')`, `status(null)` to survive disabled/other-site
  elements) and render the **title as a link** to the element's edit screen, with
  a graceful fallback (e.g. "(deleted #123)") when an element no longer exists.
- **Export** (`helpers\SubmissionCsv` / `SubmissionExporter`): the cell holds the
  related **titles** (pipe-joined for multi, matching the existing multi-value
  join), with the ids available as a secondary form/Open Question. The exporter's
  value-formatting helper gains an element-relation branch (id list → titles)
  alongside the existing scalar/file handling.

### 5.7 GraphQL / headless

- `FormFieldType` exposes the relation `config` (element type, sources, multiple,
  limit) so a headless client can fetch the allowed options (or run its own
  element query) and render the picker.
- The submit mutation accepts an id list for the field; it is validated by the
  same `validate()` membership path.
- The submission payload exposes the related element ids (and optionally resolved
  titles) so a headless confirmation screen can display the selection.

### 5.8 Builder UX

In `templates/forms/edit.html` + `form-builder.js`:
- A **"Relations"** palette group with the five element-relation types.
- The per-field property editor: a source/section multi-select (populated from the
  chosen element type's available sources), a Single/Multiple toggle, and a Limit
  (shown only when Multiple). The label/help text are translatable per site as
  usual.
- The canvas preview shows the native element-select scoped to the configured
  sources.

## 6. Acceptance Criteria

- [ ] Five element-relation field types (`entry`, `category`, `tag`, `user`,
      `asset`) exist, share an `ElementRelationFieldType` base, and are registered
      in `FieldTypeRegistry::init()`.
- [ ] Each is configurable by allowed **sources/sections**, **single/multiple**,
      and **limit** (for multiple).
- [ ] CP preview renders Craft's native element-select scoped to the configured
      element type + sources.
- [ ] Public front end renders a `<select>` (single) or checkbox group (multi)
      from the allowed source, no-JS-safe, with the choice-group a11y pattern.
- [ ] Submissions store the related element **ids** in `submission.data`.
- [ ] `validate()` rejects ids outside the allowed source, soft-deleted/missing
      ids, over-limit selections, and multiple values for a single field — all
      server-side, shared by AJAX + GraphQL.
- [ ] Submission detail renders selected elements as **linked titles** with a
      graceful fallback for deleted elements.
- [ ] CSV/JSON/XML export renders related elements as titles (multi pipe-joined).
- [ ] GraphQL exposes the relation config + submit accepts/validates id lists.
- [ ] Multi-site safe (`siteId('*')` for membership); translatable labels;
      PHPStan L7 + ECS clean; no new tables; no breaking changes.

## 7. Testing

**Unit (PHPUnit):**
- `ElementRelationFieldType::validate()`: an id inside the allowed source passes;
  an id from a disallowed section/group/volume fails; a non-existent id fails; a
  soft-deleted id fails.
- Limit/count: > `limit` ids (multiple) fails; > 1 id (single) fails; required +
  empty fails; optional + empty passes.
- `allowedElementQuery()`/`allowedIds()` honour the configured sources and use
  `siteId('*')` for membership.
- `renderInput()`: single → `<select>` of titles keyed by id; multiple → checkbox
  group with unique ids + `<label for>`; `isChoiceGroup()` true for multi.
- Submission stores an id list; `SubmissionService` validates via the shared path.
- Export helper: an id list renders as pipe-joined titles; a deleted id renders the
  fallback.

**craft-smoke-test scenarios (ship in same PR):**
- Build a form with an Entry relation (single, limited to a `products` section),
  a Category relation (multi, limit 2), and a User relation; confirm the canvas
  previews show native element selectors scoped to the right sources.
- Render the public form: assert the Entry field is a `<select>` of product
  titles and the Category field is a checkbox group of category titles.
- Submit a valid selection; open the submission detail: assert the chosen items
  show as **linked titles**.
- Forge a POST selecting an entry from a different (disallowed) section: assert the
  submission is rejected.
- Select 3 categories on a multi/limit-2 field: assert the limit error.
- Export to CSV: assert the relation columns hold the element titles.
- Delete one selected element, reopen the submission detail: assert the graceful
  "(deleted)" fallback.

## 8. Open Questions

- **Large sources.** A section with thousands of entries makes a `<select>` /
  checkbox group unusable on the public form. v1 renders the allowed set; do we
  add an opt-in search/autocomplete (JS) for large sources, or require the creator
  to narrow the source?
- **Store ids vs. real Craft relations.** v1 stores ids in JSON `data` (no new
  table). Is there demand to also write proper `relations` rows so submissions
  appear in element "related to" queries? Defer unless requested.
- **Export: titles vs. ids vs. both.** Should the export cell be titles, ids, or
  "Title (#id)"? Proposed: titles, with an Open Question to add ids as a second
  column if needed.
- **Disabled / other-site elements.** Confirm read-time resolution uses
  `status(null)` + `siteId('*')` so a valid-at-submit selection still renders
  later even if the element is disabled or lives on another site.
- **Asset/User display.** Best public-form label for Assets (filename vs. title)
  and Users (username vs. full name) — confirm per type.
- **Permissions leakage.** Ensure the public-form option list never exposes
  elements a public visitor shouldn't see (e.g. disabled entries, restricted user
  groups). The allowed-source query must respect status/enabled and only surface
  intended sources.
