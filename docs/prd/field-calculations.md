# PRD — Field type: Calculations

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#131](https://github.com/fabianhaef/craft-simple-form/issues/131)

---

## 1. Problem Statement

Simple Form has number, select, radio and checkbox fields, but no way to derive a
value from other fields. Form creators building order forms, quote requests,
booking deposits, donation tiers or "guests × price" calculators must today
either compute the total in custom front-end JS (untrusted, easy to tamper with)
or accept whatever the visitor types into a free number field.

There is also a concrete gap with the existing **Payment** field
(`PaymentFieldType`, #116): its `amountType: 'field'` mode reads the amount from a
single numeric field's value. There is no way to charge for, say,
`{quantity} * {unitPrice} + {shippingFee}` — the amount can only mirror one raw
input.

We need a first-class **Calculations** field: a read-only/computed field whose
value is derived server-side from a formula that references other fields by
handle, with a live preview as the visitor types. The computed value must be
**recomputed authoritatively on the server** — never trusted from the client.

## 2. Goals

- A new `calculation` field type (`fabianhaef\simpleform\fields\CalculationFieldType`)
  extending `FieldType`, registered in `FieldTypeRegistry`.
- A **formula** config referencing other fields of the same form by handle, e.g.
  `{quantity} * {unitPrice}`, with an allow-listed grammar: numeric literals,
  `+ - * /`, parentheses, field references, and a small set of functions
  (`min`, `max`, `round`, `ceil`, `floor`, `abs`).
- **No arbitrary PHP/Twig eval.** A dedicated, side-effect-free expression
  parser/evaluator with an explicit grammar — never `eval()`,
  `Craft::$app->view->renderString()`, or `ExpressionLanguage` with unrestricted
  scope.
- **Client-side live recompute** (form asset bundle JS): the displayed result
  updates as referenced inputs change. Purely cosmetic — for UX.
- **Authoritative server-side recompute** in `SubmissionService::submit()`: the
  stored value is always the server's computation; any posted value for the
  calculation field is ignored.
- Number **formatting / precision** config: decimal places, optional thousands
  separator, optional prefix/suffix (e.g. `CHF `, ` kg`).
- Optional integration with the **Payment** field: a calculation field's handle
  can be selected as the Payment field's `amountField`, so the charged amount is
  the computed total.
- Graceful handling of **missing / non-numeric / empty** referenced inputs
  (treat as `0` by default, configurable), and division by zero (yields `0`, not
  an error or `INF`).

## 3. Non-Goals (v1)

- String concatenation / text templating. v1 computes a **number** only.
- Date arithmetic (`{eventDate} - today`). Numeric-only in v1.
- Aggregating over repeated rows (a Repeater sum like `sum(rows.price)`) — depends
  on the separate Repeater field PRD; out of scope here.
- Conditional expressions inside the formula (`if/else`, ternary, comparison
  operators). The existing field-level conditional-logic system already handles
  show/hide; v1 calculations are pure arithmetic.
- Cross-form references. A formula references fields in the **same** form only.
- Currency conversion or locale-aware parsing of visitor input beyond a tolerant
  numeric cast.

## 4. Users & Use Cases

- **Marketer / editor** building an order or RSVP form in the CP: adds a
  *Quantity* number field and a hidden/fixed *Unit price*, then a calculation
  field *Total* = `{quantity} * {unitPrice}`, formatted as `CHF 0.00`.
- **Editor** building a donation form: tiers via radio (`10`, `25`, `50`) plus an
  *Add 5% to cover fees?* checkbox → `round({tier} * (1 + {coverFees} * 0.05), 2)`.
- **Developer** embedding via `{{ simpleForm(...) }}` or submitting over GraphQL:
  expects the server to ignore any posted total and store the computed one, so a
  tampered POST cannot under-charge a linked Payment field.

---

## 5. Proposed Solution

### 5.1 Field type & config

`CalculationFieldType` stores everything in the field `config` JSON (no schema
change). Config keys:

```jsonc
{
  "formula": "{quantity} * {unitPrice}",   // required, allow-listed grammar
  "decimals": 2,                            // 0–6, default 2
  "thousandsSeparator": false,             // default false
  "prefix": "CHF ",                        // optional display prefix
  "suffix": "",                            // optional display suffix
  "missingAsZero": true                    // missing/non-numeric ref → 0 (default)
}
```

The field is **read-only**: `renderInput()` emits a non-editable display element
(`<output>` / a styled `<span>`) plus a **hidden input** carrying the last
client-computed value purely so it round-trips for sticky re-render on a
validation error. The hidden value is **never persisted as-is** — see §5.4.

```php
final class CalculationFieldType extends FieldType
{
    public static function getType(): string { return 'calculation'; }
    public static function getLabel(): string { return 'Calculation'; }

    public function renderInput(string $name, mixed $value = null): string { /* output + hidden + data-attrs */ }

    /** Read-only display field — nothing the visitor posts is validated. */
    public function validate(mixed $value): array { return []; }

    /** Compute the value server-side from already-resolved sibling values. */
    public function compute(array $valuesByHandle): float { /* parse + evaluate */ }

    /** Format a computed float per the precision/prefix/suffix config. */
    public function format(float $result): string { /* number_format + affixes */ }
}
```

`renderInput()` writes the formula and the referenced handles into
`data-sf-formula` / `data-sf-refs` attributes so the JS can wire listeners
without an extra config round-trip.

### 5.2 Safe formula parser/evaluator

A dedicated helper `fabianhaef\simpleform\helpers\Formula` (pure, no Craft
dependency, easy to unit-test) with two stages:

1. **Tokenize** — a single regex pass producing a flat token list. Allowed
   tokens only: number literals (`\d+(\.\d+)?`), `+ - * / ( ) ,`, field
   references `{handle}`, and bareword function names from a fixed allow-list
   (`min max round ceil floor abs`). Any other character → `FormulaException`
   (rejected at save time, see §5.5).
2. **Evaluate** — a recursive-descent / shunting-yard evaluator over the token
   list with the standard precedence (`* /` over `+ -`), left-to-right, with
   parentheses and the allow-listed functions. Field references are substituted
   from a `handle => float` map supplied by the caller. No `eval`, no PHP
   callables sourced from input — function dispatch is a hardcoded `match`.

Guard rails:

- Division (and `round`/`min`/`max` with bad arity) is total: divide-by-zero →
  `0.0`; unknown function or arity mismatch → `FormulaException`.
- A reference to a handle not present in the supplied map resolves to `0`
  (when `missingAsZero`) — runtime computation never throws on missing data, only
  **validation at save time** throws on malformed *syntax* or unknown handles.
- Hard recursion-depth / token-count cap to prevent pathological nesting.

### 5.3 Resolving referenced values

`SubmissionService::submit()` already builds `$valuesByHandle` (keyed by field
handle) before validating. Calculation reuses it. Each referenced value is cast
to a float tolerantly:

- numeric string / number → `(float)`
- empty / null / non-numeric → `0.0` when `missingAsZero` (default), otherwise the
  field is skipped (formula yields `0`).
- checkbox-group / multi-value → not numeric → `0` (documented limitation; use a
  number/select/radio whose option *values* are numeric).

A select/radio whose option **values** are numeric (`"value": "25"`) resolve to
`25.0`, which is the donation-tier use case.

### 5.4 Authoritative server-side recompute (security-critical)

In `submit()`, after `$valuesByHandle` is built and the non-calculation fields
validate, the service computes each calculation field server-side and **overwrites**
any posted value:

```php
foreach ($formModel->getFields() as $fieldId => $field) {
    if ($field->getType() === CalculationFieldType::getType()) {
        $ft = $fieldTypeRegistry->getFieldType('calculation', $field->getConfig());
        $result = $ft->compute($valuesByHandle);          // server truth
        $valuesByHandle[$field->getName()] = $result;     // visible to later calcs
        $data['field_' . $fieldId] = [
            'label' => $field->getLabel() ?? $field->getName(),
            'type' => 'calculation',
            'value' => $result,                            // stored = computed
            'display' => $ft->format($result),             // formatted string for CP/exports
        ];
    }
}
```

Notes:

- Calculation fields are processed **after** ordinary fields so their references
  are populated, and re-inserted into `$valuesByHandle` so a calculation may
  reference an earlier calculation (single dependency layer; cycles are rejected
  at save time — see §5.5).
- The client-posted hidden value is **read but discarded**; it never reaches
  `$data`.
- Hidden-by-conditional-logic calculation fields are skipped (consistent with the
  existing `isVisible()` gate), so they neither compute nor store.

### 5.5 Builder UI & save-time validation

On the form edit screen (`src/templates/forms/edit.html`, JS in
`src/web/assets/cp`), the Calculation field settings panel offers:

- a **Formula** textarea with a field-handle picker (insert `{handle}`),
- decimals / separator / prefix / suffix / missing-as-zero controls.

On **form save** (`FormStructureService` save path) the formula is validated:

- parses under the allow-listed grammar (else surface a field error),
- every `{handle}` reference resolves to an existing field in the same form,
- no reference cycle among calculation fields (topological check),
- references point at numeric-capable types (warn, don't block, for non-numeric).

Invalid formulas block the save with a translated message
(`Craft::t('simple-form', ...)`), so a form can never ship a formula that would
fail silently at submit time.

### 5.6 Live recompute (front-end JS)

In the form asset bundle (`src/web/assets/form`): on load, find each
`[data-sf-formula]`, parse its `data-sf-refs`, attach `input`/`change` listeners
to the referenced inputs, and recompute using a **JS port of the same grammar**
(allow-listed tokens; no `eval`/`Function`). Update the `<output>` text and the
hidden input. This is cosmetic only — the server value wins. If JS is disabled,
the field still computes correctly on submit.

### 5.7 Payment integration

`PaymentFieldType` already supports `amountType: 'field'` with `amountField` = a
field handle. A calculation field's handle becomes a valid `amountField` choice.
Because the calculation is recomputed server-side **before** `PaymentsService::prepare()`
runs (step 8 of `submit()` is after the field loop), the Payment field reads the
authoritative computed total, closing the tamper gap.

### 5.8 Display in CP & exports

The submission detail view and `SubmissionCsv` / `SubmissionExporter` read
`data.field_<id>.value` (raw float) and `.display` (formatted). Detail view and
exports show the formatted `display` string; the raw float remains available for
re-import/analytics. No exporter schema change — it is one more column like any
other field.

## 6. Acceptance Criteria

- [ ] `CalculationFieldType` exists, extends `FieldType`, registered in
  `FieldTypeRegistry`; appears in the builder field palette.
- [ ] `Formula` helper tokenizes + evaluates the allow-listed grammar (numbers,
  `+ - * /`, parentheses, `min max round ceil floor abs`, `{handle}` refs).
- [ ] Any character/token outside the allow-list is rejected; no `eval`/Twig/PHP
  callable is ever invoked on formula input.
- [ ] Divide-by-zero yields `0`, not an error/`INF`; missing/non-numeric refs
  resolve per `missingAsZero` (default `0`).
- [ ] Server recompute in `SubmissionService::submit()` overwrites any posted
  value; the stored value equals the server computation for both AJAX and GraphQL
  paths.
- [ ] A tampered POST that supplies a fake total cannot change the stored value or
  a linked Payment amount.
- [ ] Formatting config (decimals, separator, prefix, suffix) applied to the
  stored `display` and the live preview.
- [ ] Form save rejects an unparseable formula, an unknown `{handle}`, or a
  reference cycle, with a translated error.
- [ ] Calculation field hidden by conditional logic neither computes nor stores.
- [ ] A calculation field handle is selectable as a Payment field `amountField`
  and drives the charged amount.
- [ ] Live front-end recompute updates the displayed value as inputs change; with
  JS disabled the submitted value is still correct.
- [ ] CP detail view and CSV/Excel export show the computed value.
- [ ] PHPStan L7 + ECS clean; multi-site/translatable; no breaking change to
  existing forms.

## 7. Testing

**Unit (PHPUnit):**

- `Formula` precedence/associativity (`2 + 3 * 4 == 14`, `(2 + 3) * 4 == 20`).
- Functions: `round(2.345, 2)`, `min/max/ceil/floor/abs`, arity errors throw.
- Divide-by-zero → `0`; missing ref → `0` (and skipped when `missingAsZero` off).
- Reject tokens: `` `phpinfo()` ``, `2 ** 3`, `{handle` (unterminated), `;`, `[ ]`.
- `CalculationFieldType::compute()` over a `handle => value` map incl. numeric
  select values and non-numeric inputs.
- `format()` for decimals/separator/prefix/suffix and negative results.
- Save-time validation: unknown handle, syntax error, and cycle detection all fail.

**craft-smoke-test scenarios (same PR):**

- Build a form with *Quantity* (number), *Unit price* (number), *Total*
  (calculation `{quantity} * {unitPrice}`, 2 decimals, prefix `CHF `). Submit
  `qty=3, price=10`; assert detail/DB stored total `30` and display `CHF 30.00`.
- Post a forged `field_<totalId>` value over the AJAX endpoint; assert stored
  value is the server computation, not the forged one.
- Link a Payment field to the *Total* calculation; assert the pending order amount
  equals the computed total.
- Hide the calculation via a conditional rule; submit; assert no calculation value
  stored.
- Save a form with formula `{nope} + 1` (unknown handle); assert the save is
  rejected with a field error.
- Live preview: load the public form, change *Quantity*, assert the displayed
  total updates without a round-trip.

## 8. Open Questions

- Numeric **locale**: do we parse `1'000.50` / `1.000,50` from visitor input, or
  require a plain decimal point in v1? (Lean: plain dot in v1; document.)
- Should `display` formatting be **per-site** translatable (separator/prefix), or
  global per field? (Lean: prefix/suffix translatable like labels; separator a
  boolean.)
- Do we cap precision at 6 decimals, or follow Commerce's currency precision when
  the calc feeds a Payment field?
- Should the JS evaluator be shared with conditional-logic's existing JS to avoid a
  second mini-parser, or kept separate for isolation?
- For multi-layer calc-references-calc chains, is one dependency pass enough, or do
  we want a full topological evaluation order at submit time?
