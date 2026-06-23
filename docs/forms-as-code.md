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

## Seeing what's managed

```bash
php craft simple-form/forms/status
```

Lists every form as `[config]` (a matching config file exists) or `[db]`
(database-only), and flags config files that haven't been applied yet.

## What's in the file (and what isn't)

- **In:** structural definition (fields, handles, layout, propagation), per-site
  content (titles, labels, option labels, messages), notifications, and the
  integration list.
- **Out:** integration **secrets/credentials** (an integration applies as a
  *disabled* placeholder until you add its credentials), submissions, and
  install-local ids. Fields travel by **handle**, so conditional rules re-bind
  correctly; submit values use the local `field_<id>` assigned on create — prefer
  the handle-based [`craft.simpleForm.field()`](twig-and-api.md#rendering) helper
  over hardcoding ids.

## Updating a managed form

Because the file is authoritative, change a code-defined form by editing its file
and re-running `apply`. Field **types** are immutable once created — changing a
field's type in the file is ignored (with a warning); give the new control a new
handle instead. Per-site translations are restored from the file on every apply,
so the workflow for content edits made in the CP is **re-export, then commit**:

```bash
php craft simple-form/forms/export --form=contact --out=config/simple-form/forms/contact.json
```

## Roadmap

- **`craft up` hook** to run `apply` automatically as part of a deploy.
