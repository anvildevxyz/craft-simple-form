# PRD — Field types: Rating & Opinion Scale

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#128](https://github.com/fabianhaef/craft-simple-form/issues/128)

---

## 1. Problem Statement

Simple Form can collect text, numbers, choices, dates, and files, but it cannot
ask the most common quantitative survey questions: **"rate this 1–5 stars"** and
**"how likely are you to recommend us, 0–10?"** (Net Promoter Score / opinion
scale).

Creators today fake these with a `select` or `radio` of `1,2,3,4,5`, which:
- renders as a dropdown/radio list, not the expected stars or 0–10 scale;
- stores a string, so it sorts and aggregates as text, not a number;
- has no left/right anchor labels ("Not likely" … "Very likely");
- gives no accessible, keyboard-navigable rating widget.

Rating and NPS are table-stakes for feedback forms and surveys, and both must
land as **integers** so the existing analytics (`ReportsService`) and exporter
can compute averages and distributions.

## 2. Goals

- **Rating** field type: a star-or-number rating, configurable **max 1–10**, with
  an **icon style** (star / heart / number). Stores the chosen integer.
- **Opinion Scale / NPS** field type: a horizontal scale over a configurable
  **range** (default 0–10 for NPS) with translatable **left/right anchor labels**.
  Stores the chosen integer.
- Both render **accessible** widgets built on a native **radio group** (full
  keyboard + ARIA support), degrading gracefully without JS.
- Both perform **server-side bounds checking** reusing the existing
  option-membership pattern (`validateOptionMembership`) so a forged out-of-range
  value is rejected.
- Both surface in **analytics and export as numbers** (average, distribution),
  not opaque strings.
- Per-site translatable labels/anchors; multi-site safe; PHPStan L7 + ECS clean;
  no new tables; no breaking changes.

## 3. Non-Goals (v1)

- Half-star / fractional ratings. v1 stores whole integers only.
- Emoji / custom-image scales beyond the star/heart/number presets.
- Matrix / grid questions (multiple rows sharing one scale).
- Computed NPS *segmentation* dashboards (promoters/passives/detractors split) —
  v1 stores the raw 0–10 integer; the promoter/detractor math can be a later
  analytics enhancement.
- Weighting or reverse scoring.

## 4. Users & Use Cases

- **Customer-feedback form:** a 1–5 star "How was your experience?" rating plus a
  0–10 "How likely are you to recommend us?" NPS question with "Not at all
  likely" / "Extremely likely" anchors.
- **Event survey:** number-style 1–10 rating of session quality.
- **Form filler:** clicks/keys a star or scale point; screen-reader users hear
  "Rating, 1 to 5, 4 selected" and can arrow between options.
- **Analyst:** opens the form's analytics and sees an average rating and a
  distribution bar; exports submissions to CSV where the column holds plain
  integers ready for a spreadsheet.

## 5. Proposed Solution

### 5.1 Two new field types

New classes under `src/fields/`, extending `FieldType`, registered in
`FieldTypeRegistry::init()`:

#### `RatingFieldType` (`getType() => 'rating'`)
- Config: `max` (int 1–10, default 5), `iconStyle` (`star`|`heart`|`number`,
  default `star`), `required`.
- The *allowed values* are the integers `1..max`. Reuse the choice-membership
  idea: a private `allowedValues(): list<int>` produces `range(1, $max)`, and
  `validate()` checks membership against it — the integer analogue of
  `validateOptionMembership()`.

#### `OpinionScaleFieldType` (`getType() => 'opinion'`)
- Config: `min` (int, default 0), `max` (int, default 10), `leftLabel`
  (translatable, e.g. "Not likely"), `rightLabel` (translatable), `required`.
- Allowed values: `range($min, $max)`. Default 0–10 makes it an NPS question out
  of the box.
- `max - min` is clamped to a sane span (≤ 10 points rendered as discrete buttons;
  see Open Questions for larger ranges).

### 5.2 Storage: integers

Both write the chosen value into the existing
`data['field_<id>'] = ['label' => …, 'type' => 'rating'|'opinion', 'value' => N]`
payload, with `value` cast to **int**. No schema change — `data` is already a JSON
column. Casting to int (not string) is what lets the exporter and analytics treat
the column numerically.

### 5.3 Validation (server-side, reuse the bounds pattern)

`validate()` on each type:
1. Call `parent::validate()` for the required check.
2. If a value is present, ensure it is an integer within the allowed range.
   Mirror `validateOptionMembership()`: build the allowed-values set once and do
   an `in_array($value, $allowed, true)` (or keyed `isset`) check, emitting
   `Craft::t('simple-form', 'Please select a valid option.')` on miss.

This guarantees a crafted POST of `7` to a 1–5 rating, or `11` to a 0–10 NPS, is
rejected on the server regardless of client JS — exactly how `select`/`radio`
membership is enforced today.

### 5.4 Accessible widget (radio group + ARIA)

`renderInput()` for both types emits a **radio group**, reusing the a11y pattern
already proven in `RadioFieldType` (`isChoiceGroup()` true; unique `id` per
option; explicit `<label for>`; the group labelled via the field group's
`aria-labelledby`):

- One `<input type="radio">` per allowed value, visually styled by the asset
  bundle into stars/hearts/number pills (Rating) or a labelled 0–10 strip
  (Opinion). Because the control is a real radio group:
  - **No-JS fallback:** the radios are directly clickable and submit correctly.
  - **Keyboard:** native arrow-key navigation within the group; `Tab` moves past
    it.
  - **Screen readers:** the group exposes its label and the selected value via
    standard radio semantics; each option's `<label>` carries an accessible name
    (e.g. "4 stars", or the scale number; anchors are rendered as adjacent text,
    not as the option name).
- Both override `isChoiceGroup()` → `true` so the builder/front-end template wraps
  them in the group `aria-labelledby` markup, consistent with radio/checkbox.
- A small **CP asset bundle** (`src/web/assets/cp`) adds the progressive
  enhancement: hover-fill for stars, click-to-set, and the visual scale strip.
  The front-end CSS is shipped so the default template renders them styled.

### 5.5 Analytics & export as numbers

- **Analytics (`ReportsService`):** extend the groupable/aggregatable set so
  `rating` and `opinion` are recognised numeric fields. Surface **average** and a
  **distribution** (count per value). Today analytics groups on
  `FieldTypeRegistry::OPTION_TYPES`; add a parallel notion of **numeric scale
  types** (`['rating', 'opinion']`) the report service reads for numeric stats.
  (NPS-score computation is a non-goal but the raw distribution makes it trivial
  later.)
- **Export (`helpers\SubmissionCsv` / `SubmissionExporter`):** no change needed —
  the stored `value` is already an int, so the column emits `4`, `9`, etc. The
  header is the field's translatable label as usual.

### 5.6 Builder UX

In `templates/forms/edit.html` + `form-builder.js`:
- Both appear in the field-type palette (a "Survey" group alongside the existing
  choice fields, or the general list).
- Rating's property editor: Max (1–10), Icon style (star/heart/number), Required.
- Opinion's property editor: Min, Max, Left label, Right label, Required.
- Canvas preview renders the actual stars / scale strip so the creator sees the
  result.

### 5.7 GraphQL / headless

The submit mutation already accepts a generic value per field
(`FieldValueInputType`); a rating/opinion value posts as the integer and is
validated by the same `validate()` path. The `FormFieldType` GraphQL type should
expose `config` (max/min/anchors/iconStyle) so a headless front end can render the
widget itself.

## 6. Acceptance Criteria

- [ ] `RatingFieldType` (`rating`) and `OpinionScaleFieldType` (`opinion`) exist,
      extend `FieldType`, and are registered in `FieldTypeRegistry::init()`.
- [ ] Rating: `max` constrained 1–10 (default 5); `iconStyle` ∈
      {star, heart, number}; allowed values are `1..max`.
- [ ] Opinion: configurable `min`/`max` (default 0/10) with translatable
      left/right anchor labels.
- [ ] Both store the chosen value as an **integer** in `submission.data`.
- [ ] `validate()` rejects out-of-range / non-integer values server-side, reusing
      the membership-check pattern; honours `required`.
- [ ] Both render as a native radio group with unique ids, `<label for>`, group
      `aria-labelledby`, keyboard arrow navigation, and a no-JS fallback.
- [ ] Anchor labels and field labels are per-site translatable.
- [ ] Analytics shows average + distribution for rating/opinion fields.
- [ ] CSV/JSON/XML export emits the integer value under the field's label column.
- [ ] GraphQL exposes the config needed to render the widget headlessly; submit
      mutation validates the value through the shared path.
- [ ] PHPStan L7 + ECS clean; no new tables; no breaking changes.

## 7. Testing

**Unit (PHPUnit):**
- `RatingFieldType`: `validate(3)` passes for max 5; `validate(6)` and
  `validate('x')` fail; `max` clamps outside 1–10; required empty value fails.
- `OpinionScaleFieldType`: `validate(0)` and `validate(10)` pass for 0–10;
  `validate(11)` fails; `min/max` boundaries inclusive.
- `renderInput()` for both: emits N radios with unique ids + `<label for>`;
  `isChoiceGroup()` true; the checked value matches the passed value.
- Submission of a rating + opinion form: assert `data` stores ints, not strings.
- `ReportsService` over a set of rating submissions: assert correct average and
  per-value counts.
- `SubmissionCsv::toRows()`: assert the cell is the integer.

**craft-smoke-test scenarios (ship in same PR):**
- Build a form with a 1–5 star Rating and a 0–10 NPS Opinion scale (anchors "Not
  likely"/"Very likely"); save and confirm the canvas previews render stars and
  the scale strip.
- Render the public form: assert a radio group of 5 stars and a 0–10 strip with
  both anchor labels; select a star via keyboard arrows and the scale via click.
- Submit valid values; open the submission detail: assert the integers display.
- Forge an out-of-range POST (`field_<id>=6` for the 1–5 rating): assert the
  submission is rejected with a validation error.
- Open form analytics: assert an average and a distribution appear.
- Export to CSV: assert the rating/NPS columns hold plain integers.

## 8. Open Questions

- **Large opinion ranges.** For spans > 10 (e.g. 1–100), discrete radios are
  unwieldy. v1 caps the rendered button count; do we add a slider style for wide
  ranges, or simply forbid spans > 10?
- **Optional vs. required default.** Should an unanswered rating store `null`
  (skipped) or be forbidden? Proposed: respect the `required` flag like every
  other field; unanswered + optional stores nothing.
- **NPS scoring.** Do we compute the NPS score (% promoters − % detractors) in
  analytics now, or ship raw distribution and add scoring later? Leaning: raw
  distribution in v1 (non-goal for scoring).
- **Icon set source.** Star/heart icons — use Craft's built-in icon set / inline
  SVG, or ship the plugin's own to avoid CP-only icon coupling on the front end?
- **Half-star demand.** Confirm whole-integer-only is acceptable for v1 (it
  simplifies storage, validation, and analytics).
