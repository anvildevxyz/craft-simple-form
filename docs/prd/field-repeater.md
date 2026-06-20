# PRD — Field type: Repeater / Group (repeatable rows)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#132](https://github.com/fabianhaef/craft-simple-form/issues/132)

---

## 1. Problem Statement

Every Simple Form field collects a single value. There is no way to collect a
**variable-length list of structured rows** — e.g. "add each attendee
(name + email)", "list line items (description + quantity)", "add team members".
Today creators work around this with a fixed number of duplicated fields
(*Guest 1 name*, *Guest 2 name*, …), which caps the count arbitrarily and clutters
the builder, the submission detail, and exports.

A **Repeater** field — a container holding a small set of inner sub-fields the
visitor can repeat N times ("Add another") — is a standard form-builder
capability and the most-requested structural feature after conditional logic and
calculations.

This is genuinely more complex than the existing flat field types: it nests fields
inside a field, changes the shape of stored submission data (array of row
objects), needs add/remove-row JS in both the builder and the public form, and
complicates submission display and CSV/Excel export. This PRD therefore proposes a
**deliberately constrained v1** and is explicit about what is deferred.

## 2. Goals

- A new `repeater` field type
  (`fabianhaef\simpleform\fields\RepeaterFieldType`) extending `FieldType`,
  registered in `FieldTypeRegistry`.
- A **nested config schema**: the inner sub-fields are defined in the container's
  `config` JSON (no new tables, no new element type), each with type, handle,
  label and the same per-type settings the flat fields use.
- **Min / max rows** bounds, enforced both client-side and server-side.
- Visitor-facing **add-row / remove-row** UI on the public form, and an inner-field
  editor (add/remove/reorder sub-fields) in the builder.
- Repeated values **serialize into the submission JSON** as an ordered array of row
  objects, persisted through the existing single `SubmissionService` path.
- **Server-side validation** of every cell of every row plus the row-count bounds,
  reusing each inner field type's existing `validate()`.
- Repeated data surfaces sensibly in the **submission detail view** and in
  **CSV/Excel export**.

## 3. Non-Goals (v1)

Honest scope cut — these are explicitly **out** of v1:

- **Limited inner field types.** v1 allows only the simple, single-value types:
  `text`, `email`, `number`, `select`. No `textarea`, `radio`, `checkbox` (group),
  `date`, `file`, `payment`, `calculation` inside a repeater in v1.
- **No nested repeaters.** A repeater may not contain a repeater.
- **No conditional logic inside rows.** Inner sub-fields cannot show/hide based on
  other cells; the field-level conditional system does not descend into rows.
- **No file uploads inside rows** (asset handling per row is a meaningful extra; the
  `file` type is excluded above for this reason).
- **No per-row calculations or aggregation** (e.g. a row subtotal, or summing a
  column into a Calculation field). Cross-references between the Calculation PRD and
  repeater rows are a future item.
- **No GraphQL mutation support for repeater input in v1** beyond what falls out
  naturally — the GraphQL submit path reuses `SubmissionService::submit()`, but a
  typed nested input shape is deferred; v1 may accept a JSON-encoded value.
- **No drag-to-reorder rows** on the public form in v1 (add/remove only; order is
  submission order).

## 4. Users & Use Cases

- **Editor** building an event RSVP: one *Attendees* repeater with inner *Name*
  (text) + *Email* (email), min 1 / max 10 rows; visitor clicks "Add attendee".
- **Editor** building a simple order/quote form: a *Line items* repeater with
  *Description* (text), *Quantity* (number), *Option* (select).
- **Developer** rendering via `{{ simpleForm(...) }}`: expects the repeater to add
  and remove rows without writing custom JS, and the server to validate each row
  and reject under-min / over-max submissions.

---

## 5. Proposed Solution

### 5.1 Data model — nested config in the existing field `config` JSON

No new tables, no new element type. The container's `config` holds its bounds plus
an ordered list of inner sub-field definitions:

```jsonc
{
  "minRows": 1,
  "maxRows": 10,
  "addButtonLabel": "Add another",     // translatable per site
  "fields": [
    { "handle": "name",  "type": "text",   "label": "Name",  "required": true },
    { "handle": "email", "type": "email",  "label": "Email", "required": true },
    { "handle": "qty",   "type": "number", "label": "Qty",   "min": 1, "max": 99 },
    { "handle": "size",  "type": "select", "label": "Size",
      "options": [{ "value": "s", "label": "S" }, { "value": "m", "label": "M" }] }
  ]
}
```

Inner `handle`s are unique **within the repeater** (not globally). Inner field
configs reuse the **exact same** per-type config keys the flat field types already
understand, so each inner cell can be validated by instantiating the corresponding
`FieldType` via `FieldTypeRegistry::getFieldType($type, $innerConfig)`.

### 5.2 Field type

```php
final class RepeaterFieldType extends FieldType
{
    public static function getType(): string { return 'repeater'; }
    public static function getLabel(): string { return 'Repeater'; }

    /** @return list<array{handle:string,type:string,label:?string,...}> inner defs */
    public function innerFields(): array { /* from config['fields'] */ }

    /** Render a row template + the already-submitted rows (sticky re-render). */
    public function renderInput(string $name, mixed $value = null): string { /* see 5.4 */ }

    /**
     * Validate the whole posted value (array of rows): row-count bounds + each
     * cell via the inner field type's own validate().
     * @return string[]
     */
    public function validate(mixed $value): array { /* see 5.5 */ }
}
```

`ALLOWED_INNER_TYPES = ['text', 'email', 'number', 'select']` is a hard
allow-list; an inner def with any other type is rejected at form-save time.

### 5.3 Posted value shape & serialization

The public form posts repeater cells as a 2-D array keyed by row index and inner
handle:

```
field_<id>[0][name]=Ada   field_<id>[0][email]=ada@x.io   field_<id>[0][qty]=2
field_<id>[1][name]=Alan  field_<id>[1][email]=alan@x.io  field_<id>[1][qty]=1
```

`SubmissionService` already reads `field_<id>` via `getBodyParam` — for a repeater
this yields a nested array. The service normalizes it to a **0-indexed, ordered
list of row objects** (drop empty trailing rows, re-key gaps so removed-row indices
don't leave holes), then stores it under the usual `data.field_<id>`:

```jsonc
"field_42": {
  "label": "Attendees",
  "type": "repeater",
  "value": [
    { "name": "Ada",  "email": "ada@x.io",  "qty": "2" },
    { "name": "Alan", "email": "alan@x.io", "qty": "1" }
  ]
}
```

The value is an **array of row objects**, keyed by inner handle. This stays inside
the existing JSON `data` column — no schema migration.

### 5.4 Public-form rendering & add/remove JS

`renderInput()` emits:

- a `<template>` (or hidden prototype `<fieldset>`) for one empty row, with inner
  inputs named `field_<id>[__INDEX__][<handle>]`,
- one rendered `<fieldset>` per already-submitted row (for sticky re-render after a
  validation error), seeded from `$value`,
- an **Add** button (`data-sf-repeater-add`) and per-row **Remove** buttons
  (`data-sf-repeater-remove`), and `data-sf-min`/`data-sf-max` on the container.

Inner cells are rendered by delegating to each inner `FieldType::renderInput()`
with the row-scoped name, so markup matches the flat fields exactly.

Form asset-bundle JS (`src/web/assets/form`): on "Add", clone the template,
substitute `__INDEX__` with the next index, append; on "Remove", drop the
`<fieldset>`; disable **Add** at `maxRows` and **Remove** at `minRows`. Re-indexing
on submit is unnecessary — the server re-keys (§5.3). With JS disabled the form
still submits whatever rows were rendered server-side (degrades to a fixed row
set ≥ minRows).

### 5.5 Server-side validation

In `RepeaterFieldType::validate($value)`:

1. Coerce `$value` to a list of row arrays (tolerate a JSON-encoded string from
   non-browser clients; reject a non-array as a single bounds error).
2. **Row-count bounds:** error if `count < minRows` or `count > maxRows`
   (translated, e.g. `Add at least {min} row(s).` / `No more than {max} row(s).`).
   A `required` repeater implies `minRows >= 1`.
3. **Per-cell:** for each row and each inner def, instantiate the inner
   `FieldType` and call its `validate()`; collect errors keyed by
   `row index + inner handle` so the UI can highlight the exact cell.
4. Unknown inner handles in the posted row (not in the schema) are dropped, not
   stored — same trust posture as hidden conditional fields in `submit()`.

Errors are returned as a structured array under `field_<id>` so the front-end can
map them back to specific row cells; the existing flat-string error path still
works for the simple case.

Because validation goes through `FieldModel::validateValue()` →
`RepeaterFieldType::validate()` inside the single `submit()` loop, both the AJAX
controller and GraphQL mutation enforce identical rules. Repeaters hidden by
field-level conditional logic are skipped exactly like any other field.

### 5.6 Builder UI

On the form edit screen (`src/templates/forms/edit.html`, JS in
`src/web/assets/cp`), selecting the Repeater type opens a settings panel with:

- min/max rows + add-button label (translatable),
- a **mini field editor**: add/remove/reorder inner sub-fields, each with a type
  picker limited to `ALLOWED_INNER_TYPES`, a handle, a label, and the type's own
  settings (e.g. select options, number min/max).

On **form save** (`FormStructureService`): validate that inner types are in the
allow-list, inner handles are unique within the repeater and slug-safe,
`minRows <= maxRows`, and select inner fields have options. Invalid configs block
the save with a translated message.

### 5.7 Submission detail & export

- **Detail view** (`src/templates/submissions`): render a repeater value as a small
  table — columns = inner labels, one row per submitted row. Empty value → "No
  rows".
- **CSV / Excel export** (`SubmissionCsv`, `SubmissionExporter`): a repeater can't
  map to one tidy column. v1 default: **serialize the whole repeater value as a
  JSON string** in a single column named after the field label (lossless, simple,
  matches how file-id arrays already export). A **flattened** mode (one column per
  `row{N}.{innerHandle}`, widening to the max row count in the result set) is a
  follow-up option — see Open Questions. The exporter change is additive; existing
  per-field columns for flat fields are unchanged.

### 5.8 Constraints honored

Multi-site/translatable (`Craft::t('simple-form', ...)` for all labels/messages
incl. inner labels and the add-button label); no breaking change to existing forms
(repeater is purely additive; absent config means a form has no repeaters);
PHPStan L7 + ECS clean; any raw SQL `[[...]]`-quotes camelCase (none expected — all
data lives in the JSON column).

## 6. Acceptance Criteria

- [ ] `RepeaterFieldType` exists, extends `FieldType`, registered in
  `FieldTypeRegistry`; appears in the builder palette.
- [ ] Inner sub-fields are configurable in the builder, limited to
  `text/email/number/select`; non-allowed inner types are rejected at save.
- [ ] Min/max rows enforced **client-side** (Add/Remove disable at bounds) **and
  server-side** (under-min / over-max rejected with a translated error).
- [ ] Posted nested values normalize to an ordered **array of row objects** keyed
  by inner handle, stored in `data.field_<id>.value` (no schema migration).
- [ ] Each cell is validated by its inner field type; errors map to the specific
  row + inner handle; both AJAX and GraphQL paths enforce identically.
- [ ] Unknown/extra posted inner keys are dropped, not stored.
- [ ] Add/remove-row works on the public form with JS; with JS disabled the form
  still submits the server-rendered rows.
- [ ] A repeater hidden by conditional logic is neither validated nor stored.
- [ ] Submission detail renders the rows as a table; CSV/Excel export emits the
  repeater value (JSON column in v1).
- [ ] No nested repeaters, no conditional logic inside rows, no file/payment inner
  types (v1 scope enforced).
- [ ] PHPStan L7 + ECS clean; all labels/messages translatable; no breaking change
  to existing forms.

## 7. Testing

**Unit (PHPUnit):**

- Normalize posted nested arrays → ordered row list; trailing-empty rows dropped;
  removed-index gaps re-keyed; JSON-string input tolerated.
- `validate()`: under-min and over-max row counts error; required ⇒ min ≥ 1.
- Per-cell validation delegates correctly (invalid email cell, number out of
  `min/max`, select value not in options) and errors key by row+handle.
- Extra/unknown inner keys are stripped.
- Save-time config validation: non-allowed inner type, duplicate inner handle,
  `minRows > maxRows`, select without options — each fails.
- Export serialization of a repeater value to the JSON column.

**craft-smoke-test scenarios (same PR):**

- Build a form with an *Attendees* repeater (inner *Name* text required, *Email*
  email required), min 1 / max 3. Submit 2 rows; assert DB
  `data.field_<id>.value` is a 2-element array of `{name,email}` objects and the
  detail view shows a 2-row table.
- Submit 0 rows (required/min 1); assert rejection with the min-rows error.
- Submit 4 rows (max 3); assert rejection with the max-rows error.
- Submit a row with an invalid email cell; assert the error maps to that row/cell
  and nothing persists.
- Add then remove a row on the public form via the JS; assert the submitted count
  matches what's visible.
- Export the submission; assert the repeater column contains the JSON of the rows.
- Builder: try to add a `file` inner type; assert it isn't offered / save is
  rejected.

## 8. Open Questions

- **Export shape:** ship JSON-column only in v1, or also a flattened
  `row{N}.{handle}` widening mode behind a per-export toggle? (Lean: JSON in v1,
  flatten as fast-follow.)
- **GraphQL input:** accept a JSON-encoded string for the repeater value in v1, or
  invest in a typed nested input object now? (Lean: JSON string v1, typed later.)
- **Inner-handle collision with top-level handles:** confirm inner handles live in
  their own namespace and never collide with form-level field handles (they post
  under `field_<id>[i][handle]`, so they should be isolated — verify in the JS and
  the normalizer).
- **Notifications/integrations:** how should a repeater value render in the
  notification email body and in outbound integration payloads — JSON, a bullet
  list, or a table? (Likely a list in email, JSON/array in integration payloads.)
- **Min-rows on render:** should the public form pre-render `minRows` empty rows on
  first load, or start with one and rely on the visitor to add? (Lean: pre-render
  `max(1, minRows)`.)
- **Akismet content scoring:** should inner text/email cells feed the Akismet
  content payload like flat fields do? (Likely yes — flatten row text into the
  scored content.)
