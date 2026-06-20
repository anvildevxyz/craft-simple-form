# PRD — Field type: Phone (country code + validation)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#123](https://github.com/fabianhaef/craft-simple-form/issues/123)

---

## 1. Problem Statement

Simple Form ships eight first-class field types (text, email, textarea, select,
checkbox, radio, date, number) plus file and payment. Phone numbers today must be
collected with a plain **Text** field, which gives form creators no telephone
semantics: no `type="tel"` (so mobile browsers don't show the dial keypad), no
country-code selector, no format validation, and no normalized storage. A lead
form that wants a clean, callable number ends up storing free-form strings like
`079 123 45 67`, `+41791234567`, and `tel: 0041 79 ...` side by side — useless for
exports, CRM sync (via the outbound-integrations feature), and notification SMS.

Phone is a table-stakes field type for any contact/lead form and is a natural fit
for the existing `FieldType` architecture.

## 2. Goals

- Add a **Phone** field type (`fabianhaef\simpleform\fields\PhoneFieldType`)
  registered in `FieldTypeRegistry`, appearing in the builder's field-type
  palette like every other type.
- Render a single-line `<input type="tel">` with an **optional country-code
  selector** driven by a predefined, config-controlled dial-code list.
- Validate server-side against a configurable **format/pattern** (a built-in
  sensible default plus an optional creator-supplied regex), with required /
  placeholder / default support inherited from the base.
- Store both a **normalized E.164-ish value** (for exports/integrations) and the
  **raw entered value** (for fidelity), inside the existing
  `field_<id> => {label, type, value}` submission payload — no new tables.
- Keep the field **multi-site safe and translatable**: the label, placeholder, and
  selector are per-site translatable; the dial-code list is structural config.

## 3. Non-Goals (v1)

- Live, as-you-type masking/formatting (e.g. libphonenumber-grade reformatting in
  the browser). v1 does light client hints + authoritative server validation.
- Carrier/line-type lookup, deliverability checks, or SMS verification.
- Auto-detecting the visitor's country by IP/geolocation. Default country is a
  config value.
- A full libphonenumber dependency. v1 uses a curated dial-code list + pattern
  validation, not per-country national-number rules.

## 4. Users & Use Cases

- **Marketer** building a contact or callback-request form: adds a Phone field,
  picks "Switzerland" as the default country, optionally restricts the selector to
  DACH dial codes, and gets clean `+41…` numbers in the CSV export and CRM sync.
- **Developer** rendering via `{{ simpleForm(...) }}` or headless/GraphQL: gets a
  semantic `tel` input and the same server validation regardless of channel
  (everything funnels through `SubmissionService::submit()`).
- **Operator** reading submissions in the CP / exporting: sees a single, normalized
  number column suitable for dialing or import.

---

## 5. Proposed Solution

### 5.1 New field type

`PhoneFieldType extends FieldType`:

```php
public static function getType(): string  { return 'phone'; }
public static function getLabel(): string { return 'Phone'; }
public function renderInput(string $name, mixed $value = null): string;
public function validate(mixed $value): array;
```

Registered in `FieldTypeRegistry::init()` alongside the existing
`registerFieldType(...)` calls. No change to the OPTION_TYPES set (Phone is not a
choice field).

### 5.2 Per-field config (stored in the `config` JSON column)

Structural, non-translatable keys live in `config`; translatable strings (label,
option labels, placeholder) flow through the existing per-site translation path.

```json
{
  "required": true,
  "placeholder": "+41 79 123 45 67",
  "default": "",
  "showCountrySelector": true,
  "defaultCountry": "CH",
  "allowedCountries": ["CH", "DE", "AT", "FR"],
  "pattern": "",
  "minDigits": 7,
  "maxDigits": 15
}
```

- `showCountrySelector` (bool): render the dial-code `<select>` prefix.
- `defaultCountry` (ISO-3166-1 alpha-2): preselected entry; also the assumed
  country when the user types a national (non-`+`) number.
- `allowedCountries` (list, optional): restrict the selector; empty/omitted = full
  built-in list.
- `pattern` (string, optional): creator regex applied to the **digits** of the
  number. Empty = built-in default (digit-count + optional leading `+`).
- `minDigits` / `maxDigits`: bounds on the national+country digit count
  (defaults 7 / 15, matching E.164's 15-digit max).

### 5.3 Dial-code list (predefined, config-driven)

A curated static map ships in a helper, e.g.
`src/helpers/DialCodes.php` returning
`['CH' => ['dial' => '+41', 'label' => 'Switzerland'], 'DE' => ['dial' => '+49', …], …]`.
The selector renders one `<option value="CH" data-dial="+41">` per allowed entry.
Country **labels are translatable** via `Craft::t('simple-form', 'Switzerland')`
so the dropdown is localized per site.

### 5.4 Rendering

`renderInput()` emits a `tel` input, optionally preceded by the dial-code select:

```html
<div class="sf-phone">
  <select name="field_<id>[country]" id="field_<id>-country">…</select>
  <input type="tel" id="field_<id>" name="field_<id>[number]"
         value="…" placeholder="…" required class="text fullwidth"
         inputmode="tel" autocomplete="tel-national">
</div>
```

- When `showCountrySelector` is **false**, only the `tel` input renders and the
  posted value is a flat string (the existing `field_<id>` convention). The render
  path handles both shapes.
- The shared base helpers `controlAttributes()` / `getInputAttributes()` supply
  `id` / `name` / `required` / `placeholder` for the input, keeping it consistent
  with the other field types and the a11y `<label for>` association.
- All markup is `htmlspecialchars`-escaped exactly as the existing types do.

### 5.5 Posted value shape & server normalization

Because the selector posts a `[country]` + `[number]` pair, the Phone field
receives an **array** value from `SubmissionService` (the service already supports
non-scalar values — file fields post arrays today). `PhoneFieldType` normalizes in
both `validate()` and at persist time:

1. Read `country` (ISO) + `number` (string), or treat a flat string as
   `{country: defaultCountry, number: <string>}`.
2. Strip spaces, dashes, parens, and a leading `tel:`.
3. If the number starts with `+`, take it as already-international; else prefix the
   selected country's dial code (`+41` + national digits, dropping a single
   leading `0`).
4. Produce `e164` = `+` followed by digits only.

The persisted value is a small structured map so both the normalized and raw forms
survive into exports/integrations:

```json
"field_42": {
  "label": "Phone",
  "type": "phone",
  "value": { "raw": "079 123 45 67", "e164": "+41791234567", "country": "CH" }
}
```

This stays entirely within the existing `data` JSON model — **no new table**.

### 5.6 Validation (server-authoritative)

`validate(mixed $value): array`:

- Calls `parent::validate($value)` for the `required` check (an empty
  number array/string yields the standard "This field is required." message). The
  base's `hasValue()`/`empty()` semantics treat `['number' => '']` as empty via a
  small guard in the field.
- Validates the digit count against `minDigits`/`maxDigits` (reusing the
  `validateLength`-style pattern, but on digit count).
- Applies the built-in default pattern (`/^\+?\d{7,15}$/` over the normalized
  number) or the creator's `pattern` if set.
- If `allowedCountries` is set, rejects a country not in the list (defense for
  crafted POSTs, mirroring `validateOptionMembership`'s spirit).
- Returns translatable error strings via `Craft::t('simple-form', …)`, e.g.
  *"Enter a valid phone number."*

All of this runs inside the single `SubmissionService::submit()` path, so the
AJAX `SubmitController` and the GraphQL submit mutation enforce it identically.

### 5.7 Builder UI

- The Phone type appears in the add-field palette (`getAllFieldTypes()` already
  drives it; only the registry line is needed).
- The per-field property editor in `src/templates/forms/edit.html` /
  `src/web/assets/cp` gains Phone-specific controls: **Show country selector**
  toggle, **Default country** dropdown, **Allowed countries** multi-select,
  **Custom pattern** text, **Min/Max digits**. These serialize into the same
  `#sf-fields-data` JSON the inspector already writes — no new save endpoint.
- Conditional logic keeps working unchanged: the Phone field's `value` (its `e164`
  string, with the existing operator normalization) participates in rules like any
  other field, since rules read the resolved value.

### 5.8 GraphQL / MCP

- GraphQL `FormFieldType` exposes the phone-specific config (selector flag, default
  country, allowed list) so headless clients can render the selector.
- The submission `data` value object (`raw`/`e164`/`country`) is returned as-is in
  the submission GraphQL type; no signature change to `submitForm`.

---

## 6. Acceptance Criteria

- [ ] A **Phone** field type is registered and appears in the builder palette.
- [ ] The rendered field is an `<input type="tel">`, optionally preceded by a
      config-driven dial-code `<select>` limited to `allowedCountries`.
- [ ] Submitting stores `{raw, e164, country}` under
      `field_<id>.value`; the normalized `e164` is a clean `+<digits>` string.
- [ ] Server-side validation enforces required, digit bounds, the default or
      custom pattern, and `allowedCountries`, via `SubmissionService::submit()` —
      verified on both the AJAX and GraphQL submit paths.
- [ ] Label, placeholder, and country labels are **per-site translatable**; the
      dial-code list is config, not content.
- [ ] No new DB tables; existing forms are unaffected (backward compatible).
- [ ] CSV/element export shows the phone column with the normalized `e164` value
      (see Testing for the cell shape decision in Open Questions).
- [ ] PHPStan L7 + ECS clean; no raw SQL added (so no `[[...]]` quoting needed).

## 7. Testing

### Unit (PHPUnit)

- `PhoneFieldType::validate()` matrix: required empty → error; valid national
  number + country → no error and correct `e164`; already-`+` number passed
  through; too-few / too-many digits → error; custom `pattern` honored;
  disallowed country → error.
- Normalization: `079 123 45 67` + `CH` → `+41791234567`; `0041 79…` → `+4179…`;
  leading-zero drop; whitespace/dash/paren stripping; `tel:` prefix removal.
- `renderInput()`: emits `type="tel"`; selector present only when
  `showCountrySelector`; options limited to `allowedCountries`; all attributes
  escaped.

### craft-smoke-test scenarios

- Add a Phone field with the country selector to a form, render it on the front
  end, submit a Swiss national number, and assert the stored submission's
  `field_<id>.value.e164` is `+41791234567`.
- Submit an invalid number (too short / letters) and assert the form re-renders
  with the translatable error and **no** submission row is created.
- Submit with the selector hidden (flat string config) and assert it still
  normalizes against `defaultCountry`.
- Export the submissions index and assert the phone column contains the normalized
  number.

## 8. Open Questions

- **Export cell shape:** export the `e164` only, or `e164` plus a second `… (raw)`
  column? Leaning `e164` only for a clean single column; raw stays in the JSON.
- **National-number rules:** is the curated dial-code list + digit-count pattern
  enough, or do we eventually want a libphonenumber-backed validator behind the
  same field API (opt-in, to avoid the dependency in v1)?
- **Selector vs. inline `+code`:** should a creator be able to force
  international-only entry (no selector, require a leading `+`) as a third mode?
- **Conditional operators on phone:** do `contains`/`eq` operate on `e164` or
  `raw`? Proposed: on `e164` for predictability.
