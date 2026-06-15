# PRD — Field Conditionals (Conditional Logic)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-15
**Tracking issue:** [#68](https://github.com/fabianhaef/craft-simple-form/issues/68)

---

## 1. Problem Statement

Today every field in a Simple Form is always visible and statically required or
optional. Form creators cannot build forms that adapt to what the visitor has
already entered — e.g. "show the *Company VAT* field only when *Account type* is
*Business*", or "make *Other (please specify)* required only when *Reason* is
*Other*".

This forces creators to either ship long, intimidating forms or split a single
logical form into several. Conditional logic ("if X then show/require Y") is a
table-stakes feature for any form builder and is one of the most requested
capabilities for forms of this kind.

## 2. Goals

- Let creators attach **conditional rules** to any field that control whether it
  is **shown/hidden** and whether it is **required**, based on the values of
  other fields in the same form.
- Support **multiple rules per field** combined with **match-all (AND)** or
  **match-any (OR)** logic.
- Evaluate rules **client-side** for instant UX and **server-side** for
  correctness and security (a hidden required field must not block submission;
  values for hidden fields must not be persisted or validated).
- Fit the existing two-table field schema and the single `SubmissionService`
  validation path with **no new tables** and **no breaking changes** to existing
  forms.

## 3. Non-Goals (v1)

- Nested/grouped boolean expressions (e.g. `A AND (B OR C)`). v1 is a flat list
  of rules with one top-level AND/OR combinator. Nesting can come later.
- Cross-form or cross-page conditions (forms are single-page today).
- Conditional logic on form-level behaviour (notification routing, redirect,
  success message). Scope is **field visibility + required** only.
- Calculated/derived field values.

## 4. Users & Use Cases

- **Marketer / content editor** building a contact or signup form in the CP:
  wants "Show *How did you hear about us?* detail box only when *Source* is
  *Other*".
- **Developer** embedding a form via `{{ simpleForm(...) }}`: expects the
  rendered form to hide/show fields live without writing custom JS, and expects
  the server to enforce the same rules for headless/GraphQL submissions.

---

## 5. Proposed Solution

### 5.1 Data model — store rules in the existing field `config` JSON

Conditionals are **structural**, not translatable, so they belong in the shared
`simpleform_fields.config` JSON column — no migration of table shape, no new
table. They are written/read through the existing `FieldSyncService` and
`FieldQueryHelper` paths and are invalidated by the existing
`FormStructureService` tag-based cache.

Shape stored under `config.conditional`:

```json
{
  "conditional": {
    "enabled": true,
    "action": "show",          // "show" | "hide"  (visibility behaviour)
    "match": "all",            // "all" (AND) | "any" (OR)
    "rules": [
      { "fieldId": 1, "operator": "eq",  "value": "business" },
      { "fieldId": 7, "operator": "neq", "value": "" }
    ],
    "required": {              // optional, independent conditional-required block
      "enabled": true,
      "match": "all",
      "rules": [
        { "fieldId": 3, "operator": "eq", "value": "other" }
      ]
    }
  }
}
```

Notes:
- `action: "show"` means "field is hidden by default and shown when rules match";
  `action: "hide"` means "field is shown by default and hidden when rules match".
  This keeps a single, unambiguous truth for both client and server.
- Rules reference the **target field by `fieldId`** (the stable identity used in
  `field_<id>` inputs and submission payload keys), not by handle, so renames are
  safe.
- The `required` block is **independent** of visibility (chosen scope: show/hide
  *plus* conditional-required). A field can be conditionally required while
  always visible, or vice-versa. When a field is hidden, its required rule is
  moot (see evaluation semantics).

### 5.2 Operators (v1)

A small, type-aware operator set covering the common cases:

| Operator | Meaning                | Applies to                |
|----------|------------------------|---------------------------|
| `eq`     | equals                 | all                       |
| `neq`    | not equals             | all                       |
| `empty`  | is empty / unchecked   | all                       |
| `notEmpty` | has any value        | all                       |
| `contains` | substring / option selected | text, multi-value |
| `gt` / `lt` | greater / less than | number, date              |

Comparisons are **string-normalised** to match how values arrive in the POST
payload and submission JSON. Checkbox "checked" maps to `notEmpty`/`empty`.

### 5.3 Evaluation engine — one source of truth, two runtimes

Introduce a single, framework-agnostic rule definition evaluated identically on
the server (PHP) and client (JS):

- **PHP:** new `ConditionalEvaluator` helper (e.g.
  `src/helpers/ConditionalEvaluator.php`) with
  `isVisible(array $fieldConfig, array $formData): bool` and
  `isRequired(array $fieldConfig, array $formData): bool`. `$formData` is the
  same `field_<id> => value` map the submission path already builds.
- **JS:** mirror logic in the frontend asset
  (`src/web/assets/form/dist/js/simple-form.js`), reading the rule set emitted as
  a `data-` attribute / inline JSON config on the rendered form.

The two implementations share the **operator table and `show`/`hide`/`match`
semantics** by spec (documented here + covered by parallel unit tests), so they
can never drift silently.

### 5.4 Server-side enforcement (`SubmissionService::submit()`)

Before per-field validation, compute a **visibility map** for all fields from the
submitted `$formData`:

1. For each field, `ConditionalEvaluator::isVisible(...)`.
2. **Hidden fields:** skip `FieldModel::validateValue()` entirely and **strip
   their value** from the persisted submission `data` (a hidden field's value is
   never trusted or stored).
3. **Visible fields:** validate as today, except `required` is the OR of the
   static `required` flag and `ConditionalEvaluator::isRequired(...)`.

Because visibility can itself depend on other fields, evaluate against the
**submitted snapshot** in a single pass (no field may depend on a field that is
itself conditionally controlled in a cycle — see §5.6).

`FieldModel::validateValue()` gains an optional `array $formData = []` parameter
(or a sibling `validateValue(mixed $value, array $context)`) so required-ness can
be resolved with full-form context. This is the single change rippling into the
GraphQL `submitForm` mutation and REST submit, since all three already funnel
through `SubmissionService::submit()`.

### 5.5 Editor UI — a "Conditions" section in the field inspector

In `src/templates/forms/edit.html`, extend `renderInspector()` with a collapsible
**Conditions** section for the selected field:

- Toggle: **"Enable conditional logic"**.
- Visibility row: **`[Show | Hide] this field when [All | Any] of the following
  match:`** followed by an add-able list of rule rows.
- Each rule row: **`[field ▾] [operator ▾] [value]`**, where the field dropdown
  lists the other fields in the form (by label) and the value input adapts to the
  target field's type (text input, option dropdown for select/radio/checkbox,
  number/date input).
- Separate **"Make this field required when…"** block with the same rule-row UI.
- The rule set is serialised into `config.conditional` inside the existing
  `#sf-fields-data` JSON on every mutation — no new save endpoint.

Editor guardrails:
- A field cannot reference **itself**.
- The target-field dropdown excludes the current field and warns on rules that
  reference a deleted field (rule is dropped on save).

### 5.6 Edge cases & rules

- **Self-reference:** disallowed in the editor; ignored server-side.
- **Cycles** (A shows-if-B, B shows-if-A): detected at save time; the form save
  surfaces a validation error rather than silently looping. Runtime evaluation is
  single-pass against the submitted snapshot, so it terminates regardless.
- **Deleted target field:** rules pointing at a removed `fieldId` are pruned on
  save; at runtime an unknown `fieldId` evaluates as "no value" (`empty`).
- **Hidden + required:** a hidden field is never required (visibility wins).
- **Reorder:** because rules key on `fieldId`, drag-reorder doesn't affect them.

### 5.7 Frontend rendering

`TwigExtension::renderForm()` / the field render path emits each field with its
serialised conditional config (e.g. `data-sf-conditional='{...}'`) and a stable
`data-sf-field-id`. On load and on every `input`/`change`, the frontend JS
recomputes visibility + required for all fields and toggles a `hidden` class +
the `required` attribute accordingly. Hidden fields are excluded from the POST by
the JS (defensively; the server strips them regardless).

### 5.8 GraphQL & MCP

- **GraphQL:** add a `conditional` field to `FormFieldType` exposing the rule set
  (new `SimpleFormFieldConditional` + rule types) so headless clients can render
  the same logic. The `submitForm` mutation needs no signature change — it
  already routes through `SubmissionService::submit()`, which now enforces
  visibility/required.
- **MCP:** `GetFormTool`/`FormPresenter::fields()` include the `conditional`
  block; `UpdateFieldTool` accepts it so an AI agent can author conditions.

---

## 6. Acceptance Criteria

- [ ] A field can have conditional **show/hide** with multiple rules and an
      All/Any combinator, configured entirely in the CP field inspector.
- [ ] A field can be made **conditionally required** independently of visibility.
- [ ] Rendered forms hide/show fields live (no page reload) as the visitor edits,
      and toggle the `required` attribute accordingly.
- [ ] Server-side: hidden fields are **not validated** and their values are **not
      persisted**; conditionally-required visible fields are enforced. Verified
      via Twig, REST, and GraphQL submission paths.
- [ ] Conditionals survive field **reorder** and **rename** (keyed by `fieldId`).
- [ ] Self-reference and cyclic rules are rejected at save with a clear error.
- [ ] No new DB tables; existing forms with no conditionals behave exactly as
      before (backward compatible).
- [ ] PHP `ConditionalEvaluator` and JS evaluator have **parallel unit tests**
      asserting identical results across the operator matrix.
- [ ] GraphQL `FormFieldType` exposes the `conditional` block; MCP read/update
      tools round-trip it.
- [ ] Smoke-test suite covers: show-on-select, hide-on-checkbox, conditional
      required enforced server-side, hidden-required not blocking submit, hidden
      value stripped from stored submission.

## 7. Implementation Slices (suggested)

1. **Spec + `ConditionalEvaluator` (PHP)** — pure evaluator + unit tests over the
   operator matrix. No wiring.
2. **Server enforcement** — visibility map in `SubmissionService::submit()`,
   `FieldModel::validateValue($value, $formData)`, value stripping; integration
   tests on all three submit paths.
3. **Editor UI** — Conditions section in the inspector, serialise into
   `config.conditional`; save-time self-ref/cycle/dangling validation.
4. **Frontend JS evaluator** — mirror logic, live show/hide + required toggling,
   exclude hidden from POST; parallel JS unit tests.
5. **GraphQL + MCP exposure** — `conditional` type/field; MCP read/update.
6. **Smoke tests + docs** — `ConditionalsCest`, end-user docs, CHANGELOG.

## 8. Risks & Mitigations

- **Client/server drift** → single documented spec + parallel test matrices; the
  server is authoritative.
- **Editor complexity creep** → flat rule list with one AND/OR combinator in v1;
  nesting explicitly deferred.
- **Performance** → evaluation is in-memory over already-loaded field config;
  no extra queries. Cache invalidation already covered by `FormStructureService`.
- **Security** → hidden fields' values are stripped server-side, so a crafted
  POST cannot inject data into a field the visitor never saw.
