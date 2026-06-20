# PRD — Form Configuration Import/Export (Portability)

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#139](https://github.com/fabianhaef/craft-simple-form/issues/139)

---

## 1. Problem Statement

A form's definition is locked inside one install's database. There is no way to take the
"Contact" form built on a staging site and move it to production, share a tested
registration form with another project, version a form definition in git, or hand a
support customer a reproducible config. Today the only paths are: rebuild it by hand, or
copy database rows directly — fragile, error-prone, and impossible across installs with
different ids.

Neither Formie nor Freeform ships a headline form export/import. Doing this well — a
portable, versioned, **secret-free** JSON definition that round-trips a form across
installs — is a genuine differentiator and the natural complement to the duplicate /
stencils work (which copies *within* an install).

## 2. Goals

- **Export** a form's full definition to a single portable JSON file: the `Form` element
  attrs, all fields with per-field `config` (conditional logic included), per-site
  labels/help text/option labels, attached **notifications**, and **references** to
  attached integrations — *without* any secrets or submissions.
- **Import** that JSON on another install (or the same one) to recreate the form,
  including a versioned schema so old exports keep importing.
- Deterministic **conflict handling** on handle collision (rename / replace / abort).
- Hard **secret redaction**: integration API keys/tokens/signing secrets never appear in
  an export.
- A **console command** (`src/console/controllers/`) for CI/scripted use *and* CP
  buttons (export on the form edit screen, import on the index).
- Clearly document the relationship to Craft **project config** (this is *not* PC; it is
  an explicit file you move).

## 3. Non-Goals (v1)

- Exporting/importing **submissions** (covered by the existing
  `simple-form/submissions/export` CSV path).
- Exporting integration **credentials** — only the integration *reference*
  (type + name + non-secret settings) travels; the target install must have the matching
  integration configured.
- Auto-syncing forms through project config. We may *emit* a PC-friendly representation as
  an open question, but the headline feature is an explicit file.
- A diff/merge UI for re-importing over an existing form (v1 is rename/replace/abort).
- Bulk export of all forms in one archive (single-form files in v1; an "export all" zip is
  a fast-follow).

## 4. Users & Use Cases

- **Staging → production promotion:** build + test a form on staging, export the JSON,
  import on production, re-attach the production integration credentials.
- **Template sharing:** a developer publishes a battle-tested "job application" form JSON
  in a gist; another team imports it as a starting point.
- **Version control:** a team commits `forms/contact.json` to git and re-imports on
  deploy to keep the form definition reproducible.
- **Support reproduction:** a user hits a conditional-logic bug; they export the form and
  attach the (secret-free) JSON to a bug report.

## 5. Proposed Solution

### 5.1 The export schema (versioned JSON)

A `FormPortabilityService` produces a single object:

```jsonc
{
  "_meta": {
    "schemaVersion": 1,
    "plugin": "fabianhaef/craft-simple-form",
    "pluginVersion": "x.y.z",
    "exportedAt": "2026-06-20T10:00:00+00:00",
    "exportedFromSite": "default"
  },
  "form": {
    "handle": "contact",
    "name": "Contact",
    "propagationMethod": "all",
    "allowSaveResume": false,
    "templatePath": null,                // if that PRD has landed
    "content": {                          // keyed by site handle, not id
      "default": { "title": "Contact", "description": "...",
                   "emailTo": "...", "emailSubject": "...",
                   "emailReplyTo": "...", "emailBody": "..." },
      "fr":      { "title": "Contact", ... }
    }
  },
  "fields": [
    {
      "handle": "email",                  // simpleform_fields.name
      "type": "email",
      "required": true,
      "sortOrder": 2,
      "config": { /* decoded field config JSON incl. conditional */ },
      "content": {                        // per site: label/helpText/optionLabels
        "default": { "label": "Email", "helpText": "", "optionLabels": null,
                     "errorMessage": null },
        "fr":      { "label": "Courriel", ... }
      }
    }
  ],
  "notifications": [
    { "name": "Admin alert", "enabled": true, "recipientType": "fixed",
      "recipient": "team@example.com", "subject": "...", "replyTo": null,
      "body": "...", "conditional": null, "sortOrder": 1 }
  ],
  "integrations": [
    { "type": "webhook", "name": "Zapier hook",
      "settings": { "url": "https://...", "apiKey": "__REDACTED__" } }
  ]
}
```

Key mapping decisions, grounded in the real schema:

- **Site keys are handles, not ids.** `simpleform_forms_sites` /
  `simpleform_fields_sites` are keyed by `siteId` internally, but ids are not portable.
  Export keys per-site content by **site handle**; import re-resolves to local site ids,
  skipping sites that do not exist on the target (with a warning).
- **Fields are keyed by handle** (`simpleform_fields.name`), not id, so conditional rules
  (which already reference fields by handle in `config`) survive untouched. No id ever
  appears in an export.
- **Notifications** map 1:1 to `NotificationModel`
  (`src/models/NotificationModel.php`); `id`/`formId`/`uid` are dropped (regenerated on
  import).
- **Integrations** export only the *reference*: `type` + `name` + non-secret settings,
  with every key in `IntegrationsService::SECRET_KEYS`
  (`apiKey`, `apiToken`, `secret`, `token`) replaced by the literal `"__REDACTED__"`. The
  global `IntegrationModel` rows are shared install-state, not form definition; import
  re-attaches by matching `type`+`name` (see 5.3).

### 5.2 Export (service + console + CP)

- `FormPortabilityService::export(Form $form): array` builds the structure above by
  reading the resolved field sets per supported site
  (`FormStructureService::getFieldSet()` / `FieldQueryHelper`), the form's notifications
  (`NotificationsService`), and its integration attachments
  (`IntegrationsService`), then **redacting** secret keys via the existing secret-key
  list rather than re-implementing it.
- **Console:** `simple-form/forms/export --form=<handle> [--out=path.json]` in a new
  `src/console/controllers/FormsController.php`, mirroring the option/`--out` pattern of
  `SubmissionsController` (`actionExport`). Writes pretty JSON to the path or stdout.
- **CP:** an "Export" download button on the form edit screen
  (`src/templates/forms/edit.html`) hitting `FormsController::actionExport()` which streams
  `application/json` as an attachment.

### 5.3 Import (service + console + CP)

`FormPortabilityService::import(array $data, array $opts): ImportResult` validates and
recreates the form in one transaction:

1. **Validate `_meta.schemaVersion`** against a known set; unknown future versions abort
   with a clear message. Older versions pass through a small upgrader chain
   (`migrateSchema(v→latest)`), so v1 exports keep importing after the schema evolves.
2. **Resolve the target handle / conflict mode** (`--mode` / CP radio):
   - `rename` (default): if `handle` exists, derive a unique one (`{handle}-2`, …) reusing
     the duplicate PRD's `uniqueHandle()` helper.
   - `replace`: delete the existing form with that handle, then create fresh.
   - `abort`: stop with a clear error if the handle exists.
3. **Create the `Form`** with shared + per-site content, resolving site handles to local
   ids (unknown sites skipped + warned).
4. **Sync fields** through `FieldSyncService::sync()` (not raw inserts) so all the
   existing invariants hold (option-label splitting, conditional sanitisation, per-site
   rows). Field handles travel verbatim, so conditional rules re-bind correctly.
5. **Create notifications** from the array via `NotificationModel` + `NotificationsService`.
6. **Re-attach integrations:** for each exported integration reference, look up an
   existing global `IntegrationModel` by `type`+`name` on the target. If found, attach.
   If not found, create a **disabled** placeholder integration with the non-secret
   settings and `"__REDACTED__"` blanked out, attach it, and surface a
   `needsCredentials` warning in the result so the admin knows to fill in the secret. An
   integration is never silently enabled with empty credentials.
7. Return an `ImportResult` { newForm, warnings[] (skipped sites, integrations needing
   credentials, schema upgrades applied) }.

- **Console:** `simple-form/forms/import <path.json> [--mode=rename|replace|abort]`.
- **CP:** an "Import a form" button on the form index (`src/templates/forms/index.html`)
  → file upload + conflict-mode radios → `FormsController::actionImport()` → redirect to
  the new form's edit screen with the warnings shown as a notice.

### 5.4 Relationship to Craft project config

Documented explicitly: Simple Form forms are **elements** (content/data), so they are not
managed by project config. Import/export is an *explicit file you move on demand* — it does
not write to `config/project/` and is unaffected by `allowAdminChanges`. This is a
deliberate contrast with PC's automatic sync, and the right model for content-like forms.
(Whether to *additionally* emit a PC-style YAML for the structural subset is an open
question, not a v1 goal.)

### 5.5 Standards

- Secret redaction reuses `IntegrationsService::SECRET_KEYS` (single source of truth — F4
  already defines it); a unit test asserts no secret-key value ever leaves export as
  anything but `"__REDACTED__"`.
- All console + CP strings via `Craft::t('simple-form', …)`, added to all eight catalogs.
- Multi-site safe: per-site content keyed by handle; import skips absent sites with a
  warning rather than failing.
- Import runs in a transaction; partial failures roll back. PHPStan L7 + ECS clean. Any
  hand-written SQL `[[...]]`-quotes camelCase columns; the field write path goes through
  `FieldSyncService` so most of this is already covered.
- No new tables. A new console controller file is added under `src/console/controllers/`.

## 6. Acceptance Criteria

- [ ] `export()` produces versioned JSON with `_meta.schemaVersion`, form attrs, per-site
      content keyed by **site handle**, all fields (handle-keyed, with decoded `config`
      incl. conditional), notifications, and integration **references**.
- [ ] **No secret** (`apiKey`/`apiToken`/`secret`/`token`) appears in any export — each is
      `"__REDACTED__"`.
- [ ] `import()` recreates a working form across installs: fields with conditional logic
      re-bind correctly, notifications fire, per-site content restored for sites that exist
      on the target.
- [ ] Conflict modes work: `rename` derives a unique handle, `replace` overwrites,
      `abort` errors cleanly.
- [ ] Integrations re-attach to a matching `type`+`name` global integration when present;
      otherwise a **disabled** placeholder is created and a `needsCredentials` warning is
      returned — never enabled with empty secrets.
- [ ] Console `simple-form/forms/export` and `import` work with `--out`/path and `--mode`;
      CP export download + import upload work and redirect appropriately.
- [ ] An import of an unknown future `schemaVersion` aborts with a clear message; a v1
      export still imports after the schema version bumps (upgrader path exercised).
- [ ] Import is transactional — a mid-import failure leaves no partial form.
- [ ] Docs explain the (non-)relationship to project config.
- [ ] PHPStan L7 + ECS clean; translation keys in all catalogs.

## 7. Testing

### Unit / functional

- `ExportSchemaTest` — export a rich fixture form (multi-site, select with per-site option
  labels, conditional rules, a notification, a webhook integration with a secret) and
  assert the JSON shape, handle-keyed sites/fields, and that the integration secret is
  `"__REDACTED__"`.
- `SecretRedactionTest` — for every key in `IntegrationsService::SECRET_KEYS`, a non-empty
  value never survives export verbatim.
- `RoundTripTest` — export → wipe → import yields a form structurally equal to the
  original (fields, config, conditional, per-site content, notifications), with new ids.
- `ConflictModeTest` — `rename`/`replace`/`abort` against an existing handle.
- `SchemaVersionTest` — unknown future version aborts; a synthetic v0/v1 upgrader path
  produces a valid latest-schema structure.
- `IntegrationReattachTest` — match by `type`+`name` attaches; no match creates a disabled
  placeholder + `needsCredentials` warning.
- `MissingSiteTest` — importing content for a site absent on the target skips it + warns,
  and the form still imports.

### craft-smoke-test scenarios

- "Export then import on the same install": export a form via the CP button, import the
  file with `rename`, assert a `-2` form appears with identical fields and a working
  conditional rule.
- "Console round-trip": `forms/export --form=contact --out=/tmp/contact.json` then
  `forms/import /tmp/contact.json --mode=rename`; assert exit code OK and the new form
  renders + submits.
- "Secrets stay home": export a form with a Slack/webhook integration; grep the file and
  assert the token is `__REDACTED__`; import on a fresh install and assert the integration
  comes in **disabled** with a credentials warning.
- "Replace mode": import over an existing handle with `replace`; assert the old form (and
  its submissions) are gone and the new definition is in place.

## 8. Open Questions

- Should `replace` mode **preserve submissions** belonging to the replaced form, or is
  delete-and-recreate acceptable? Replacing the element id orphans
  `simpleform_submissions.formId`. Lean: `replace` warns it discards submissions; offer
  `update-in-place` later.
- Do we additionally emit a **project-config YAML** for the structural (non-content)
  subset so forms *can* opt into PC sync? Lean: out of scope for v1; revisit if users ask
  to manage forms via PC.
- Integration matching is by `type`+`name` — fragile if names differ across installs.
  Add an optional stable `key`/handle on `IntegrationModel` to match on instead? Lean:
  start with `type`+`name`, add a handle if it bites.
- Should export optionally **include** secrets (encrypted, for same-key migrations) behind
  an explicit `--with-secrets` flag, or is redaction absolute? Lean: absolute in v1 —
  secrets never travel; encrypted-secret migration is a separate, opt-in feature.
- Bulk "export all forms" as a single archive — fast-follow once single-form is proven.
