# PRD — Form builder: multi-column row layout

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#136](https://github.com/fabianhaef/craft-simple-form/issues/136)

---

## 1. Problem Statement

Every Simple Form renders as a single vertical column. There is no way to put First name
and Last name side by side, or to lay out a compact address block. The render macro
(`TwigExtension::renderForm`) emits one `renderFieldGroup()` per field in order, and the CP
builder (`src/web/assets/cp/dist/js/form-builder.js`) is a flat, draggable list serialized
to the `fieldsData` hidden input. Authors expect basic horizontal grouping — it's table
stakes for a "full-featured" form builder and a frequent request.

The constraint: do this **without new field tables** if at all possible, **without breaking
existing single-column forms**, and consistent with how multi-step pages are already
encoded — via each field's `config` blob (`config.page`, grouped by
`fabianhaef\simpleform\helpers\FormSteps`), not a separate schema.

## 2. Goals

- Let creators arrange fields into **rows**, where a row holds one or more **columns**, each
  column holding one field (e.g. First | Last in a 2-column row).
- Encode the layout **inside the existing per-field `config`** (ordering + a column hint),
  so no new tables and no migration for field storage.
- Drag-and-drop builder UX to create rows, drop fields into columns, and reorder, in
  `form-builder.js` + `edit.html`.
- Responsive front-end rendering: CSS grid that collapses to a single column on mobile.
- **Backward compatible**: existing forms (no layout hints) render exactly as today.
- Honor multi-step: columns are *within* a step/page; the row model composes with the
  existing `config.page` grouping.

## 3. Non-Goals (v1)

- Nested layouts (columns inside columns), or rows containing multiple fields per column.
  v1 = one field per column.
- Arbitrary column widths / a grid designer. v1 = equal-width columns, capped at a small max
  (e.g. up to 4 columns per row).
- Drag-resize of columns.
- Changing the field storage schema or adding a `rows`/`columns` table.
- Layout-aware conditional logic beyond what already exists (a hidden field just leaves a
  gap; revisit if it looks bad — see Open Questions).

## 4. Users & Use Cases

- **Site builder**: First/Last name on one row; City | State | Zip on another.
- **Designer**: a tighter, less stacked contact form that matches the page's grid.
- **Existing author**: opens a pre-layout form and sees it unchanged; adds a row only when
  they want one.

## 5. Proposed Solution

### 5.1 Layout data model (no new tables)

Reuse the field-ordering + `config` mechanism that already encodes steps. Two ideas, with a
recommendation:

**Recommended: per-field `config.row` index + array order.**
Each field keeps its position in the flat ordered `fields` array (already serialized to
`fieldsData`). Add an optional `config.row` integer. Fields sharing the same
`(page, row)` and adjacent in order form one visual row; their column position is their
order within that group. Concretely the resolved field row gains:

```jsonc
// field config (decoded)
{ "page": 1, "row": 2, /* …existing keys… */ }
```

- No `row` (or `row` shared by only one field) → full-width, single-column (today's
  behavior). **Absence of `row` is the back-compat default.**
- N adjacent fields with the same `(page, row)` → an N-column grid row.
- Column count is derived (`count` of fields in the group), capped at 4; extras wrap.

This mirrors `FormSteps`: a pure grouping helper, no schema change, existing forms (no
`row`) unaffected.

A new helper `fabianhaef\simpleform\helpers\FormRows` groups a step's fields into rows:

```php
/**
 * @param list<array<string,mixed>> $stepFields fields of one page, in order
 * @return list<list<array<string,mixed>>> rows, each a list of 1..N fields (columns)
 */
public static function group(array $stepFields): array
```

Grouping rule: walk the ordered fields; consecutive fields with the same numeric
`config.row` (and `row` set) join the current row; a field with no `row`, or a different
`row` value, starts a new single-column row. Keeping it order-driven (not a global `row`
map) means reordering in the builder Just Works and avoids renumbering churn.

### 5.2 Resolved field set + caching

`config.row` rides along in the existing decoded `config` returned by
`FormStructureService::getFieldSet()` — no change to the structure cache shape. The builder
already round-trips arbitrary `config` keys (see `buildPayload()` /
`cleanConfig()` in `form-builder.js`), so persistence is free.

### 5.3 Front-end rendering (TwigExtension + CSS)

`TwigExtension::renderForm` currently loops fields per step calling `renderFieldGroup()`.
Wrap that loop with `FormRows::group()`:

```php
foreach (FormRows::group($stepFields) as $row) {
    if (count($row) > 1) {
        $html .= '<div class="simple-form-row" data-cols="' . count($row) . '">';
        foreach ($row as $field) {
            $html .= '<div class="simple-form-col">'
                   . $this->renderFieldGroup($field, $fieldTypeRegistry, $resumeValues)
                   . '</div>';
        }
        $html .= '</div>';
    } else {
        $html .= $this->renderFieldGroup($row[0], $fieldTypeRegistry, $resumeValues);
    }
}
```

Applies identically to single-step and multi-step branches (both already loop fields).
A single-column field emits **exactly today's markup** (no wrapper) → byte-for-byte
back-compat for existing forms and existing CSS/tests.

CSS (`src/web/assets/form/dist/css/simple-form.css`):

```css
.simple-form-row { display: grid; gap: 1rem; }
.simple-form-row[data-cols="2"] { grid-template-columns: repeat(2, 1fr); }
.simple-form-row[data-cols="3"] { grid-template-columns: repeat(3, 1fr); }
.simple-form-row[data-cols="4"] { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 600px) {
    .simple-form-row { grid-template-columns: 1fr; } /* collapse to single column */
}
```

Grid keeps the no-JS path working — layout is pure CSS, no JS dependency on the front end.

### 5.4 Builder UX (`form-builder.js` + `edit.html`)

The builder is a flat draggable list of `.sf-field` blocks. Minimal, additive changes:

1. **Visual rows.** Render the canvas grouped by `(page, row)`: blocks with the same row
   sit in a flex `.sf-builder-row` wrapper side by side; lone fields render full width as
   today. `renderCanvas()` already rebuilds the canvas from the `fields` array on every
   change — extend it to lay out rows.
2. **Create a row.** Add a per-field inspector control "Columns / row position": a small
   number input or a "group with previous field" toggle that sets/clears `config.row`. The
   simplest first cut reuses the existing `numberRow()` inspector pattern already used for
   `Step / Page` (`f.config.page`) — add a sibling "Row" number input bound to
   `f.config.row`. Fields with the same Row + Page render as columns.
3. **Drag-drop into columns.** Extend the existing drag handlers so dropping a field onto a
   row (rather than between rows) assigns it that row's `row` value and inserts it at the
   target column index. Reordering within a row reorders the underlying `fields` array.
   Cap a row at 4 columns; a 5th drop spills to a new row.
4. **Serialization unchanged.** `buildPayload()` already serializes `config` per field, so
   `config.row` persists with no change to the hidden `#sf-fields-data` contract or
   `FormsController::parseFieldsData()`.

`edit.html` needs no structural change (the builder mounts into the existing canvas); only
the bundled `form-builder.js`/`cp.css` are rebuilt. A short inline hint near the canvas
explains row grouping.

### 5.5 Backward compatibility

- A form with no `config.row` on any field: `FormRows::group()` returns each field as a
  lone single-column row → `renderFieldGroup()` emits today's exact markup. No DB change, no
  visual change, existing smoke tests/CSS untouched.
- Conditional logic and steps are orthogonal: rows group *within* a page after the step
  split, and a conditionally-hidden field is simply omitted from its row (the grid reflows).

## 6. Acceptance Criteria

- [ ] `FormRows::group()` groups a step's ordered fields into rows by `(page, row)`; fields
      without `config.row` are lone single-column rows.
- [ ] Front-end renders multi-field rows as a CSS grid wrapper (`.simple-form-row` /
      `.simple-form-col`) with `data-cols`; single fields emit unchanged markup.
- [ ] Grid collapses to a single column at the mobile breakpoint (pure CSS, works with JS
      off).
- [ ] No new tables and no migration for field layout; `config.row` round-trips through the
      existing `fieldsData` serialization and structure cache.
- [ ] Builder lets an author group fields into a row and reorder within it; rows cap at 4
      columns; serialization writes `config.row`.
- [ ] Existing forms (no layout hints) render byte-for-byte as before; multi-step + columns
      compose correctly (rows are within a step).
- [ ] Conditional-logic hide of a column reflows the row without error.
- [ ] PHPStan L7 + ECS clean; CP strings via `|t('simple-form')`.

## 7. Testing

### Unit (PHPUnit)

- `FormRows::group()`:
  - all fields without `row` → N single-column rows (back-compat);
  - two adjacent fields sharing `(page=1, row=1)` → one 2-column row;
  - a non-adjacent same-`row` field starts a new row (order-driven grouping);
  - cap behavior (5th column spills to a new row);
  - composes with `FormSteps` (rows computed per step).
- Rendered-markup assertion: a 2-column form emits `.simple-form-row[data-cols="2"]` with
  two `.simple-form-col`s; a plain form emits no `.simple-form-row` wrapper.

### JS (node, mirroring `tests/js/conditional-evaluator.test.js`)

- If the row-grouping logic is extracted as a pure function in `form-builder.js`, assert it
  groups the same cases as the PHP `FormRows::group()` (parity).

### craft-smoke-test scenarios

- In the builder, create a form, add First name + Last name, group them into one row;
  save; render the public page and assert the two fields appear side by side
  (`.simple-form-row[data-cols="2"]`), and that resizing/mobile width collapses them to one
  column.
- Open an existing single-column form (no layout hints), save without changes, render →
  assert markup is unchanged (no `.simple-form-row` wrapper) and the form still submits.
- Build a 2-step form where step 1 has a 2-column row and step 2 is single-column; assert
  the columns render only within step 1 and the multi-step nav still works.
- Add a conditional rule that hides one column's field; trigger the hide → assert the row
  reflows with no JS error and the form submits.

## 8. Open Questions

- **Row encoding: `config.row` index vs. an explicit `config.colspan`/`config.rowBreak`
  flag.** Proposing the order-driven `config.row` index (5.1) for simplicity and
  reorder-friendliness; a `rowBreak` boolean is an alternative that avoids renumbering but
  is less explicit. Decide before building the serializer.
- **Max columns.** Cap at 4? Most forms need 2–3. Proposing 4.
- **Equal width only?** v1 equal-width. Do we want a later `config.colWidth` (e.g. 2/3 + 1/3)?
  Out of scope for v1.
- **Hidden-column gap.** When a conditionally-hidden field leaves an empty grid cell, do we
  want the remaining columns to re-flow to fill, or hold the gap? Proposing reflow (omit the
  cell), revisit if it looks unbalanced.
- **Builder drag affordance.** Is a "Row" number input enough for v1, or do we need true
  drag-into-column from the start? Proposing ship the number-input grouping first (lowest
  risk on the existing drag code), enhance drag-into-column as a fast follow.
