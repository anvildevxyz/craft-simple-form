# Conditional Logic

> **Standard edition.** Conditional logic requires [Standard](editions.md). A form that
> already uses it keeps evaluating rules after a downgrade to Solo — you just
> can't add it to a form that doesn't have it yet. See
> [Editions](editions.md#what-happens-on-a-downgrade).

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

## Logic jumps

Where visibility rules show/hide single fields, **logic jumps** branch the
*step flow* of a [multi-page](building-forms.md#multi-step-multi-page-forms) or
[conversational](building-forms.md#conversational-mode) form: "when this answer
matches, skip ahead to a later step". Classic use: *"Are you an existing
customer?" — Yes jumps straight to the support questions, skipping the signup
pages.*

### Configuring jumps

Jumps are set on the **source field**, in the field inspector's **Logic jumps**
section: each rule is `[operator] [value] → [target field]`, using the same
operators as visibility rules. The target select only offers **later** fields —
the jump goes to the step that holds the target field. The **first matching
rule wins**; when none match, the form advances to the next step as usual.

### Guard rails & behavior

- Jumps are **forward-only** by construction (the builder only offers later
  fields), and saving rejects a jump whose target isn't on a strictly later
  step: *"a logic jump must point to a later step"*. Renaming a field's handle
  updates the jumps that point at it; a rule whose target field was deleted is
  pruned on save.
- The **Back** button replays the visitor's actual path, so jumped-over screens
  aren't revisited.
- **Skipped steps can't block submission**: the server replays the same jump
  path and skips validation for fields on jumped-over (unreachable) steps — a
  required field the visitor legitimately never saw is not enforced, while a
  step they filled in before jumping past still submits normally.
- On a plain single-page, standard-mode form there is nothing to navigate, so
  jumps are inert.

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
