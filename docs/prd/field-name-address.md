# PRD — Field types: Name & Address (composite / multi-part)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#126](https://github.com/fabianhaef/craft-simple-form/issues/126)

---

## 1. Problem Statement

Collecting a person's **name** or a **postal address** today means stitching
together several separate Simple Form fields — "First name", "Last name", "Street",
"City", "Postal code", "Country" — each a standalone field card in the builder and a
standalone column in exports. That is tedious to build, easy to get inconsistent
across forms, and produces messy submissions where the parts of one logical thing
are scattered. There's no notion of "Name" or "Address" as a single, structured
field with labelled sub-inputs.

Two **composite** field types — **Name** and **Address** — let creators drop one
field that renders multiple labelled sub-inputs, validates each part, and stores
the parts as a structured value, while still flattening cleanly into CSV/Excel.

## 2. Goals

- Add two composite field types,
  `fabianhaef\simpleform\fields\NameFieldType` and
  `fabianhaef\simpleform\fields\AddressFieldType`, registered in
  `FieldTypeRegistry`.
- **Name**: configurable parts — prefix, first, middle, last, suffix — each
  individually toggleable on/off.
- **Address**: line1, line2, city, state/region, postal code, country (a
  `<select>`).
- Render multiple labelled sub-inputs under one field, with **per-site
  translatable** sub-labels.
- Define how the composite value **serializes** into the existing
  `field_<id> => {label, type, value}` submission JSON (value = an associative
  sub-part map), how each sub-part **validates**, and how the field **flattens**
  into CSV/Excel as one column per sub-part.
- Stay within the existing single-field `config` + submission-data model — **no new
  tables**, multi-site safe, no breaking changes.

## 3. Non-Goals (v1)

- Address autocomplete / geocoding (Google Places etc.).
- Per-country dynamic address layouts (e.g. swapping field order/labels by
  country). v1 uses a fixed sub-field set; country is just a stored value.
- Postal-code format validation per country. v1 validates presence/length only.
- Multiple addresses or names in one field (no repeating). One composite value per
  field.
- A shared "person" entity across submissions. Each submission stores its own value.

## 4. Users & Use Cases

- **Marketer / editor** building a registration or shipping form: drops one
  **Address** field instead of six, gets sensible labelled inputs, and exports a
  tidy six-column block.
- **Editor** building a contact form: drops a **Name** field, turns off prefix/
  middle/suffix, keeps just First + Last.
- **Operator / integration**: reads the structured value (`{first, last}` /
  `{line1, city, postalCode, country}`) from the submission JSON, the GraphQL
  payload, or the flattened export, and maps it into a CRM.

---

## 5. Proposed Solution

### 5.1 New field types

Both extend `FieldType`. Each declares its sub-fields and reuses the base helpers.

```php
class NameFieldType extends FieldType {
    public static function getType(): string  { return 'name'; }
    public static function getLabel(): string { return 'Name'; }
}
class AddressFieldType extends FieldType {
    public static function getType(): string  { return 'address'; }
    public static function getLabel(): string { return 'Address'; }
}
```

Registered in `FieldTypeRegistry::init()`. Neither is a choice type, so neither
joins `OPTION_TYPES`. A small shared base (`CompositeFieldType extends FieldType`)
or a trait can hold the common sub-field machinery (render loop, value-map
validation, flatten keys) so Name and Address don't duplicate logic.

### 5.2 Sub-field definitions

Each composite type defines an ordered list of **sub-fields**, each with a stable
**key**, a **default translatable label**, an **input kind**, and an
**enabled/required** flag.

**Name** sub-fields (keys): `prefix`, `first`, `middle`, `last`, `suffix` — each
toggleable; sensible defaults = `first` + `last` enabled, the rest off; `first`
and `last` required when enabled (configurable).

**Address** sub-fields (keys): `line1`, `line2`, `city`, `state`, `postalCode`,
`country`. All text inputs except `country`, which is a `<select>` (reusing the
curated country list — shared with the Phone field's dial-code/country source).

### 5.3 Per-field config (in the existing `config` JSON)

```json
{
  "required": true,
  "subFields": {
    "first":  { "enabled": true,  "required": true,  "label": "First name" },
    "middle": { "enabled": false, "required": false, "label": "Middle name" },
    "last":   { "enabled": true,  "required": true,  "label": "Last name" },
    "prefix": { "enabled": false, "required": false, "label": "Title" },
    "suffix": { "enabled": false, "required": false, "label": "Suffix" }
  }
}
```

- `subFields.<key>.label` is the **per-site translatable** sub-label (flows through
  the existing per-site translation path, like option labels do today).
- `enabled` controls whether a sub-input renders at all; a disabled sub-field is
  never rendered, validated, stored, or exported.
- `required` is per sub-field; the field-level `required` toggle, when on, is a
  shorthand that requires the "primary" sub-fields (`first`/`last` for Name;
  `line1`/`city`/`postalCode`/`country` for Address).

### 5.4 Rendering

`renderInput()` loops the **enabled** sub-fields, emitting one labelled control
each, namespaced under the field's `name` so they post as one array:

```html
<fieldset class="sf-composite" aria-labelledby="field_<id>-legend">
  <div class="sf-subfield">
    <label for="field_<id>-first">First name</label>
    <input type="text" id="field_<id>-first" name="field_<id>[first]"
           value="…" required class="text">
  </div>
  <div class="sf-subfield">
    <label for="field_<id>-last">Last name</label>
    <input type="text" id="field_<id>-last" name="field_<id>[last]" value="…" required>
  </div>
  …
</fieldset>
```

- Each sub-input has a **unique id** (`field_<id>-<key>`) and an explicit `<label
  for>` (a11y, matching the existing per-option id pattern in the choice types).
- The group is a `<fieldset>` so screen readers announce it as one logical field.
- `country` renders a `<select>` of the translatable country list; the
  default/selected value comes from config/posted value.
- All output is `htmlspecialchars`-escaped using the base helpers' conventions.
  Sub-inputs are driven by `id` + `name` arrays; no stray `name` collides inside a
  `fullPageForm` because every name is the composite's array key.

### 5.5 Posted shape & serialization into submission data

The composite posts an **associative array** under `field_<id>` (e.g.
`field_42[first]=Ada`, `field_42[last]=Lovelace`). `SubmissionService` already
reads `field_<id>` and supports non-scalar values (file fields post arrays). The
field stores an associative sub-part map as its `value`, inside the **existing
payload shape** — no new table:

```json
"field_42": {
  "label": "Name",
  "type": "name",
  "value": { "first": "Ada", "last": "Lovelace" }
}
```

```json
"field_55": {
  "label": "Address",
  "type": "address",
  "value": {
    "line1": "10 Downing St", "line2": "",
    "city": "London", "state": "", "postalCode": "SW1A 2AA", "country": "GB"
  }
}
```

Only **enabled** sub-fields appear in the stored map. The composite never invents
keys from a crafted POST: at persist time the field intersects the posted array
with its enabled sub-field keys (defense-in-depth, mirroring how the choice fields
validate option membership).

### 5.6 Validation

`validate(mixed $value): array` runs once, inside the single
`SubmissionService::submit()` path:

1. Coerce `$value` to an array; a non-array posted value for a composite is invalid.
2. For each **enabled** sub-field:
   - If `required` (per-sub or via the field-level shorthand) and empty → add the
     translatable error *"{label} is required."*.
   - Apply length bounds via the shared `validateLength` helper.
   - For `country`, validate membership in the country list (reuse the
     `validateOptionMembership`-style check).
3. Errors are returned as a flat `string[]` (the base contract). Each message names
   its sub-field's translatable label so the visitor knows which part failed.
   (The front-end can map errors back to sub-inputs by label/order; see Open
   Questions on per-sub-input error surfacing.)

All messages go through `Craft::t('simple-form', …)`.

### 5.7 CSV / Excel export — flattened columns

The export helper (`SubmissionCsv::toRows()` / `fromSubmissions()`) builds one
column per stored `data` key today, header = the entry's `label`, and pipe-joins
arrays via `scalar()`. For composites we **flatten the sub-parts into multiple
columns** instead of pipe-joining the array, so an address doesn't collapse into
`10 Downing St|London|GB`:

- For a `type === 'name'` / `type === 'address'` entry, emit one column **per
  enabled sub-field**, header = `"<field label> — <sub-field label>"` (e.g.
  `Name — First name`, `Address — City`, `Address — Country`).
- Cell value = the sub-part scalar (country exports its label or code — see Open
  Questions).
- Column discovery already unions keys across the result set; the composite branch
  expands one `field_<id>` into its sub-columns there. This is a typed formatting
  branch in the existing helper — **no schema change**, and it keeps non-composite
  fields exactly as they are.

### 5.8 Builder UI

- Name and Address appear in the add-field palette.
- The inspector for a composite shows a **list of sub-field rows**, each with an
  **enable toggle**, an editable **translatable label**, and a **required toggle**;
  Address `country` additionally has no extra config (fixed list in v1).
- Config serializes into the existing `#sf-fields-data` JSON — no new endpoint.
- The field card preview in the builder shows the enabled sub-fields so creators see
  the shape at a glance.

### 5.9 Conditional logic, GraphQL, MCP

- **Conditional logic**: composites participate as a whole; a rule referencing a
  composite reads its serialized value (proposed: operators match against any
  non-empty sub-part for `notEmpty`/`empty`; `eq`/`contains` match against a
  whitespace-joined string of the parts). Documented in the conditionals spec.
- **GraphQL**: `FormFieldType` exposes the sub-field definitions; the submission
  type returns the structured sub-part map as-is.
- **MCP**: the field presenter includes the `subFields` config; the update tool
  round-trips it.

---

## 6. Acceptance Criteria

- [ ] **Name** and **Address** composite field types are registered and selectable
      in the builder.
- [ ] Name parts (prefix/first/middle/last/suffix) are each individually
      toggleable; Address renders line1/line2/city/state/postalCode/country with a
      country `<select>`.
- [ ] Each sub-input renders with its own id and a translatable `<label for>`, inside
      a `<fieldset>`; sub-labels are **per-site translatable**.
- [ ] A submission stores the composite as an associative sub-part map under
      `field_<id>.value`, containing **only enabled** sub-fields; crafted extra keys
      are ignored.
- [ ] Per-sub-field validation works (required/length/country membership) server-side
      via `SubmissionService::submit()`, on both AJAX and GraphQL paths; error
      messages name the sub-field and are translatable.
- [ ] CSV/element export **flattens** each composite into one column per enabled
      sub-field (`"<field> — <sub-field>"` headers), not a single pipe-joined cell.
- [ ] No new DB tables; existing forms unaffected; PHPStan L7 + ECS clean.

## 7. Testing

### Unit (PHPUnit)

- Sub-field definition + config: defaults (Name = first/last on), enabling/disabling
  parts, per-site label resolution.
- `renderInput()`: only enabled sub-fields render; unique ids; `<label for>` per
  sub-input; country `<select>` populated; everything escaped.
- Serialization: posted associative array → stored map limited to enabled keys;
  crafted extra key dropped; non-array posted value rejected.
- `validate()`: required sub-field empty → labelled translatable error; length
  bounds; invalid country code → error; field-level `required` shorthand requires
  the primary sub-fields.
- Export flatten: a Name and an Address submission expand into the correct multiple
  columns with `"<field> — <sub-field>"` headers; disabled sub-fields produce no
  column; column union aligns across mixed forms.

### craft-smoke-test scenarios

- Build a form with a Name field (first + last) and an Address field; render on the
  front end and assert each sub-input has its own label.
- Submit valid Name + Address values and assert the stored submission holds the
  structured sub-part maps.
- Submit with a required sub-field empty (e.g. blank city) and assert the form
  re-renders with the labelled error and **no** submission row.
- Disable Address `line2` and `state`, submit, and assert neither appears in the
  stored value nor in the export.
- Export the submissions index and assert the composite fields flatten into
  per-sub-field columns.

## 8. Open Questions

- **Per-sub-input error surfacing:** the base `validate()` returns a flat
  `string[]`. Is naming the sub-field in each message enough for the front end, or
  do we want a richer keyed error contract (`{subKey: [errors]}`) for composites?
  Proposed: labelled flat messages in v1; revisit if the JS needs per-input
  highlighting.
- **Country cell in export:** export the country **label** ("United Kingdom") or the
  **ISO code** ("GB")? Proposed: label for humans, with the code recoverable from
  the JSON. Possibly a setting.
- **Conditional operators on composites:** confirm the "match against any sub-part /
  joined string" semantics, or restrict composites to `empty`/`notEmpty` only in v1.
- **Shared country list ownership:** Name/Address `country` and the Phone field's
  country source should share one curated list helper — confirm a single
  `src/helpers/Countries.php` (or similar) as the home, to avoid drift.
- **Address `state` label:** "State / Region / Province" varies by locale — keep a
  single translatable label, or let the creator rename it per form? Proposed: a
  translatable default label the creator can override (already supported by
  `subFields.<key>.label`).
