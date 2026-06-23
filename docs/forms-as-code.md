# Forms as Code

Define forms as files you commit to git and deploy across environments, instead
of clicking them into the database on every install. Simple Form reuses the same
versioned, secret-free JSON document as [import/export](import-export.md) and
adds an **apply** step so a form can be created from code on `craft up`.

> **Status (v1).** `apply` **creates** forms that don't exist yet on the target —
> the core "deploy a form definition to staging/prod" workflow. It is
> deliberately **non-destructive**: a form that already exists is left untouched,
> so a re-apply can never orphan submissions. Updating an existing form's
> *structure* from code (an id-stable, in-place merge) is the next step — see
> [Roadmap](#roadmap).

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
php craft simple-form/forms/apply            # create any missing config forms
php craft simple-form/forms/apply --dry-run  # show what would happen, change nothing
```

- A handle that doesn't exist on the target is **created** from its file.
- A handle that already exists is **left untouched** (never mutated or deleted).
- It's **idempotent**: re-running only creates what's missing.

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

## Roadmap

- **Id-stable in-place update.** Apply an edited file onto an *existing* form,
  matching fields by handle and updating in place (so field ids — and submissions
  — survive), with an opt-in `--prune` for fields removed from the file.
- **`craft up` hook** to run `apply` automatically.

Until then, to change a live form keep editing it in the CP (and re-export to keep
its file in sync), or recreate it on a fresh environment.
