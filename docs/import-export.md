# Form configuration import/export (portability)

Move a form's full definition between installs as a portable, versioned,
**secret-free** JSON file. Build and test a form on staging, export it, import it
on production; share a battle-tested form in a gist; commit `forms/contact.json`
to git for reproducible deploys; or attach a (secret-free) form to a bug report.

## What travels

The export document carries the form's full *definition*:

- The form's attributes (`handle`, `name`, `propagationMethod`, `allowSaveResume`).
- Per-site shared content (title, description, email settings) **keyed by site
  handle** — never by id, which is not portable.
- Every field, **keyed by handle**, with its decoded `config` (conditional logic
  included) and per-site label / help text / option labels / error message.
- Notifications (admin alerts and autoresponders).
- Integration **references**: `type` + `name` + non-secret settings.

## What never travels

- **Submissions.** Use `simple-form/submissions/export` (CSV) for those.
- **Secrets.** Every key in `IntegrationsService::SECRET_KEYS`
  (`apiKey`, `apiToken`, `secret`, `token`) is replaced with the literal
  `"__REDACTED__"` on export. On import an unmatched integration is recreated
  **disabled** with its secret blanked, and the result returns a
  `needsCredentials` warning — an integration is never silently enabled with
  empty credentials.
- **Database ids.** Fields are keyed by handle so conditional rules (which already
  reference fields by handle) re-bind correctly with no id rewriting.

## Console

```sh
# Export to stdout, or to a file.
php craft simple-form/forms/export --form=contact --out=contact.json

# Import. --mode is one of rename (default), replace, abort.
php craft simple-form/forms/import contact.json --mode=rename
```

## Control panel

- **Export** — a download button on each form's row in the Forms index and on the
  form edit screen.
- **Import** — an "Import a form" button on the Forms index opens an upload modal
  with a conflict-mode selector, then redirects to the new form's edit screen with
  any warnings shown as a notice.

## Conflict handling

When the imported handle already exists on the target:

- **rename** (default): a unique handle is derived (`contact-2`, `contact-3`, …).
- **replace**: every existing form with that handle is deleted and recreated.
  Its submissions are discarded (a warning says so).
- **abort**: the import stops with a clear error.

## Schema versioning

Each document carries `_meta.schemaVersion`. Importing a document from a **newer**
schema than the installed plugin understands aborts with a clear message. Older
documents are walked forward through an upgrader chain, so a v1 export keeps
importing after the schema evolves.

## Relationship to Craft project config

Simple Form forms are **elements** (content-like data), so they are **not** managed
by Craft project config. Import/export is an *explicit file you move on demand*: it
does not write to `config/project/`, it is unaffected by `allowAdminChanges`, and it
never syncs automatically. This is a deliberate contrast with project config's
automatic structural sync — the right model for content-like forms. If you want a
form definition reproducible in git, commit its exported JSON and re-import on
deploy.
