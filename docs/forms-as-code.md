# Forms as Code

Define forms as files you commit to git and deploy across environments, instead
of clicking them into the database on every install. Simple Form reuses the same
versioned, secret-free JSON document as [import/export](import-export.md) and
adds an **apply** step so a form can be created from code on `craft up`.

`apply` **creates** a form that doesn't exist on the target and **updates an
existing one in place** — the form keeps its element id and fields are reconciled
by handle, so field ids (and therefore their submissions) survive a re-apply. The
file is authoritative: re-applying restores a form's structure and content from
its file.

## Where forms live

Code-defined forms go in:

```
config/simple-form/forms/<handle>.json
```

Each file is a portable form document: form metadata, fields (by handle), per-site
content keyed by site handle, notifications, and integrations **without secrets**.

## Adopting an existing form

Export a form you built in the CP straight into the config folder, then commit it:

```bash
php craft simple-form/forms/export --form=contact --out=config/simple-form/forms/contact.json
```

## Applying on another environment

```bash
php craft simple-form/forms/apply            # create missing forms, update existing ones in place
php craft simple-form/forms/apply --dry-run  # show what would happen, change nothing
php craft simple-form/forms/apply --prune    # also remove fields no longer in the file
```

- A handle that doesn't exist on the target is **created** from its file.
- A handle that already exists is **updated in place**, id-stable: the form keeps
  its element id and matched fields (by handle) keep their ids, so submissions and
  conditional references are never orphaned.
- A field present on the form but **absent from the file** is **kept** by default;
  pass `--prune` to remove it — except a field that still holds **submission data**
  is always kept (with a warning), never silently dropped.
- It's **idempotent**: re-applying an unchanged file is a no-op.

Wire it into your deploy so forms ship with the code, e.g. after `craft up`:

```bash
php craft up && php craft simple-form/forms/apply
```

Or have `craft up` do it for you: set **`applyFormsConfigOnUp = true`** (Settings →
General, or `config/simple-form.php`) and every `craft up` runs `apply` when it
finishes. The automatic run never prunes (safe by default); run
`forms/apply --prune` manually when you intend to remove fields.

## Seeing what's managed

```bash
php craft simple-form/forms/status
```

Lists every form as `[config]` (a matching config file exists) or `[db]`
(database-only), and flags config files that haven't been applied yet.

## What's in the file (and what isn't)

- **In:** the full form definition — fields, layout, and **all form-level
  settings** (post-submit action, availability open/close dates, submission
  limits, login-required, per-user limits, editing window, duplicate prevention,
  render template path, save-&-resume, propagation), per-site content (titles,
  labels, option labels, messages), notifications, and the integration list.
- **Out:** integration **secrets/credentials** (an integration applies as a
  *disabled* placeholder until you add its credentials), submissions, and
  install-local ids. Two ids that *can't* travel are handled for you:
  - **Fields** travel by **handle**, so conditional rules re-bind correctly;
    submit values use the local `field_<id>` assigned on create — prefer the
    handle-based [`craft.simpleForm.field()`](twig-and-api.md#rendering) helper
    over hardcoding ids.
  - A **post-submit "redirect to entry"** target travels as the entry's **URI**
    and is resolved to a local entry on import; if it can't be found, the form
    falls back to its inline message (with a warning) rather than failing.

The document is **schema-versioned** — a file exported by an older plugin still
imports, with any newer settings keeping their defaults.

## Updating a managed form

Because the file is authoritative, change a code-defined form by editing its file
and re-running `apply`. On update, `apply` reconciles the form's **name, fields,
and per-site content** from the file. A few behaviours to know about — each is
covered by a test in `tests/integration/ApplyEdgeCasesTest.php`:

- **The file wins over CP edits.** Re-applying restores field labels, help text
  and titles from the file, so a translation an editor changed in the CP is
  **overwritten on the next apply**. Treat a config-managed form as code: edit the
  file, or edit in the CP and immediately **re-export, then commit** to keep the
  file the source of truth.
- **Renaming a handle creates a new field.** A field is matched by handle, so
  changing `fullName` → `name` in the file adds a fresh (empty) `name` field and
  **keeps the old `fullName` field and its submissions** (it's now "absent from
  the file", so it survives unless you `--prune`). To rename without losing data,
  do it in the CP and re-export.
- **Field types are immutable.** Changing a field's `type` in the file is ignored
  (with a warning); give the new control a new handle instead.
- **A truncated file won't silently wipe fields.** Without `--prune`, fields not
  in the file are kept — only an explicit `--prune` removes them (and never a
  field that still holds submission data).
- **Propagation isn't changed on an existing form.** Changing `propagationMethod`
  in the file is applied only on first create; change it deliberately in the CP.

```bash
php craft simple-form/forms/export --form=contact --out=config/simple-form/forms/contact.json
```
