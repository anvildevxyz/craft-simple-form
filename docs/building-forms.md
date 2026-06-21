# Building & Configuring Forms

Everything about creating a form, laying out its fields, and deciding what happens
after a visitor submits. Field-level concerns (the available field types,
conditional logic, theming) live in their own guides, linked where relevant.

## Creating a form

1. Go to **Simple Form → Forms** and click **New form**, or start from a
   [stencil](#stencils-presets).
2. Give the form a **Name** and **Handle** (the handle is shared across sites and
   must be globally unique).
3. Fill in the per-site **Title**, optional **Description**, and the email/
   notification settings.
4. Build the field set in the drag-and-drop builder, then **Save**.

The **Title**, **Description**, and email settings are *translatable* (per-site);
the **Handle**, the field set, scheduling, and the post-submit choice are
*structural* and shared across all sites.

## The field builder

The form edit screen hosts a drag-and-drop builder:

- **Add a field** from the palette, then drag to reorder.
- Select a field to open its inspector — label, handle, help text, required
  toggle, type-specific options, conditional logic, and the layout controls
  (page + row) below.

The set of field types you can add, and each type's options, are covered in
**field-types.md**.

## Multi-step (multi-page) forms

A form becomes multi-step the moment its fields span more than one **page**.

- Each field carries a 1-based **Page** number in its inspector (default `1`).
- Fields are grouped into steps by page number, in ascending order, keeping their
  original order within a page (`FormSteps`).
- A form whose fields are all on page 1 is *not* multi-step and renders exactly as
  a single-page form — there is no schema change and no behavioural difference for
  existing forms.

On the front end, a multi-step form renders **Back / Next** navigation and a step
indicator via the `step-nav.twig` partial. Validation runs per step on advance and
authoritatively on the final submit.

> Multi-step output is only produced by the whole-form renderer
> (`craft.simpleForm.form(...)`). The granular `formStart()` / `formEnd()` helpers
> are single-step only — see [render-templates.md](render-templates.md).

## Multi-column row layout

Within a page, fields can sit **side by side** in a row instead of stacking.

- Each field's inspector has a **Row** number. Consecutive fields that share the
  same Row number are laid out as columns of one visual row.
- A field with no Row number (the default), or a different Row number than the row
  being built, starts a new single-column row.
- A row holds at most **4 columns** (`FormRows::MAX_COLUMNS`); a fifth field that
  shares the same Row number spills into a new row.

Rows are a pure, order-driven layout hint (`FormRows`) — a form with no Row hints
renders exactly as before (every field full-width). The built-in theme makes
columns responsive, collapsing to a single column on narrow viewports; a custom
theme can restyle the row/column wrappers (see
[render-templates.md](render-templates.md)).

## Save & continue later

Opt a form into partial submissions so a visitor can save their progress and
return via a link.

- Enable **Allow save & continue later** on the form edit screen
  (`allowSaveResume`). The front end then renders a *Save & continue later*
  button (it applies to multi-step forms too).
- Saving stores the values entered so far as a **draft** addressed by a
  high-entropy **resume token**, and hands the visitor a resume URL containing
  that token.
- Only a **SHA-256 hash** of the token is persisted (`DraftService`) — the token
  itself lives only in the resume URL, so a database read alone can't resurrect a
  session.
- Re-saving with the same token **updates the draft in place** and refreshes its
  expiry, so the resume URL stays stable.
- Drafts expire after the configured **draft retention** window (Settings →
  General; default **30 days**) and expired drafts are garbage-collected.
- On a completed submission, the draft for that token is deleted.

## Conditional logic

Show, hide, or conditionally require a field based on what the visitor has already
entered — evaluated live in the browser and re-checked authoritatively on the
server. Configure it from the field inspector's **Conditions** section. See
[conditional-logic.md](conditional-logic.md) for the operators, guard rails, and
the GraphQL/MCP surface.

## Post-submit behavior

What happens after a successful submission is set per form on the edit screen via
**After submit** (`postSubmitAction`), one of:

| Action | Setting | Notes |
|--------|---------|-------|
| **Show success message** (default) | `message` | Renders the inline success message. |
| **Redirect to a URL** | `url` | Requires **Redirect URL** (`redirectUrl`). |
| **Redirect to an entry** | `entry` | Requires **Redirect Entry** (`redirectEntryId`). |

### Per-form success / error messages

- **Success Message** (`submitMessage`) and **Error Message** (`errorMessage`) are
  per-site overrides. Leave either **blank to fall back** to the global default
  (Settings → General).
- The success message and the redirect URL support **placeholders**: `{submissionId}`
  and any **field handle** (e.g. `{email}`) are interpolated from the submitted
  values — so you can template a confirmation like
  *"Thanks {name}, your reference is {submissionId}."* or redirect to
  `/thank-you?ref={submissionId}`.

These messages are translatable (per-site); the *action choice* itself is
structural and shared across sites.

## Stencils (presets)

Start a new form from a built-in starter instead of a blank slate. On the Forms
index, the **New form from stencil** menu offers each registered stencil; picking
one creates a real, independent form (fields + sensible default notifications)
and drops you into its edit screen to translate and tweak.

Built-in stencils:

| Stencil | What it seeds |
|---------|---------------|
| **Contact** | Name, Email, Message + an admin-notification. |
| **Newsletter signup** | Email + a consent checkbox. |
| **Event registration** | Name, Email, guest count, dietary notes, an "Attending?" select + an admin notification. |
| **Support request** | Name, Email, Priority select, Subject, Details + an admin alert **and** an autoresponder to the submitter's email. |

A stencil is pure data — no element rows, no project config. Plugins can register
their own via `Plugin::EVENT_REGISTER_STENCILS`.

## Duplicate a form

To clone an existing form, select it on the Forms index and run the **Duplicate**
element action. Each selected form is deep-copied into a new, independent form:

- A fresh, collision-safe handle (`<handle>-copy`, `-copy-2`, …).
- All fields recreated with **new ids** (conditional rules re-resolve against the
  copy's own handles), plus per-site labels, options, help text, and error
  messages.
- Notifications copied.
- Integration **attachments** re-pointed at the copy — the shared integration
  definitions and their encrypted secrets are **not** cloned; both forms reference
  the same integration rows.
- **Zero submissions**; the source form is never touched.

## Import / export

A form's full *definition* (attributes, per-site content, fields, conditional
logic, notifications, and secret-free integration references) can be exported to a
portable, versioned JSON file and imported on another install, via the console or
the CP. Submissions and secrets never travel. See
[import-export.md](import-export.md).

## Theming & custom render templates

The front-end markup is rendered through a set of overridable Twig partials. You
can restyle a form, replace its wrappers, or hand-author the markup — per render,
per form, or globally — without re-implementing CSRF, the honeypot, captcha,
multi-step, conditional logic, or save-&-resume. See
[render-templates.md](render-templates.md).
