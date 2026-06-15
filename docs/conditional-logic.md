# Conditional Logic

Show or hide a field — and make it required — based on what the visitor has
already entered in other fields. For example: show a *VAT number* field only
when *Account type* is *Business*, or require an *Other (please specify)* box
only when *Reason* is *Other*.

Conditions are evaluated **live in the browser** as the visitor fills the form,
and **re-checked on the server** when the form is submitted. The server is
always authoritative.

## Configuring conditions in the Control Panel

1. Open a form and select a field in the builder.
2. In the field inspector, expand **Conditions** and toggle **Enable
   conditional logic**.
3. **Visibility** — choose **Show** or **Hide** this field when **all** / **any**
   of the rules match, then add one or more rules. Each rule is:

   > `[another field] [operator] [value]`

   The field dropdown lists the form's other fields; the value input adapts to
   the chosen field (a dropdown of options for choice fields, a number/date
   input, or free text).
4. **Conditional required** — optionally tick **Make this field required when…**
   and add rules. This is independent of visibility: a field can be always
   visible but conditionally required, or vice-versa.

Save the form as usual — conditions are stored with the field.

### Operators

| Operator        | Meaning                              | Best for            |
|-----------------|--------------------------------------|---------------------|
| `is`            | equals                               | any                 |
| `is not`        | does not equal                       | any                 |
| `is empty`      | no value / nothing selected          | any                 |
| `is not empty`  | has any value                        | any                 |
| `contains`      | substring, or option is selected     | text, multi-select  |
| `greater than`  | numeric / date comparison            | number, date        |
| `less than`     | numeric / date comparison            | number, date        |

## How it behaves

- **Visibility** — `Show` means the field starts hidden and appears when the
  rules match; `Hide` means it starts visible and disappears when they match.
- **Hidden = ignored** — a hidden field is never validated and its value is
  **not stored**. A hidden, required field will never block submission, and a
  value posted for a field the visitor never saw is discarded server-side.
- **Required** — a visible field is required if it is statically required *or*
  its conditional-required rules match.
- **Renames & reordering** — rules reference fields by handle and survive
  reordering; renaming a field's handle updates the rules that point at it.

## Guard rails

The form refuses to save when conditions would not make sense:

- A field **cannot reference itself**.
- Rules **cannot form a loop** (A depends on B which depends on A).
- A rule pointing at a field that was deleted is dropped automatically on save.

## Headless (GraphQL & MCP)

Conditional logic is exposed so headless front-ends can mirror the same
behaviour and AI agents can author it.

**GraphQL** — each field exposes a `conditional` object (null when the field
has no conditional logic):

```graphql
{
  simpleForm(handle: "contact") {
    fields {
      name
      conditional {
        action            # "show" | "hide"
        match             # "all" | "any"
        rules { field operator value }
        requiredMatch     # "all" | "any" | null
        requiredRules { field operator value }
      }
    }
  }
}
```

**MCP** — `get_form` returns each field's `config.conditional`, and `add_field`
/ `update_field` accept a `conditional` object in `config`:

```json
{
  "conditional": {
    "enabled": true,
    "action": "show",
    "match": "all",
    "rules": [{ "field": "accountType", "operator": "eq", "value": "business" }],
    "required": {
      "enabled": true,
      "match": "all",
      "rules": [{ "field": "accountType", "operator": "eq", "value": "business" }]
    }
  }
}
```

Self-references and cycles are rejected, and rules pointing at unknown handles
are pruned — the same guard rails as the Control Panel.
