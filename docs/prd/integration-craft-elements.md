# PRD — Integration: Create Craft Elements (Entry / User) from Submission

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#142](https://github.com/fabianhaef/craft-simple-form/issues/142)

---

## 1. Problem Statement

Simple Form's outbound connectors all push submissions to *external* systems. The most
valuable destination for many Craft sites is **Craft itself**: turn a submission into a native
**Entry** (a blog comment, an event listing, a job application, a directory record) or a
**User** (registration, a gated-content signup). Today there is no way to do this without
custom code in a `EVENT_AFTER_SUBMISSION_SAVE` listener.

Both major competitors (Formie, Freeform) treat "create an element from a submission" as a
headline feature. For a Craft-native form plugin it is table stakes — and it's something the
external SaaS connectors fundamentally cannot offer. This PRD adds a first-class
**Element integration** that maps submission values onto a new Entry or User, saving it through
Craft's element API, async, off the submission save.

## 2. Goals

- A new `CraftElementIntegration` (Element type connector) implementing
  `IntegrationTypeInterface`, registered via `RegisterIntegrationTypesEvent` — reusing the
  same async dispatch (`SendIntegrationJob`), retry, and dispatch-log framework as every other
  connector.
- Support two target element types in v1: **Entry** and **User**.
- A `settingsHtml()` mapping UI: choose the target (Entry: section + entry type; User: group),
  then map submission fields onto **native attributes** (title, slug, email, username, author,
  enabled/status) and **custom fields**.
- Save through `Craft::$app->getElements()->saveElement()` with proper validation handling:
  a non-saving element fails the dispatch (logged, retryable) and does **not** lose the
  submission.
- **Link** the created element back to the submission so the CP detail view shows "Created
  Entry → #123" and re-running doesn't silently duplicate.
- Multi-site correct: the element is created on the submission's site
  (`Submission::$siteId`), respecting propagation expectations.

## 3. Non-Goals (v1)

- No element types beyond Entry and User (Category/Asset/Commerce-Product are future work).
- No editing/updating an existing element from a later submission (create-only in v1).
- No matrix/super-table deep mapping — top-level custom fields only in v1.
- No automatic relation building between the created element and arbitrary other elements
  beyond setting the author.
- No front-end "claim this entry" / moderation queue (the created Entry's *status* — e.g.
  `pending` — is the moderation lever).

## 4. Users & Use Cases

- **Editor**: a "Submit an event" form creates a `pending` Entry in the Events section that an
  editor reviews and publishes.
- **Community manager**: a comment form creates a disabled Entry in a Comments section.
- **Membership site**: a registration form creates a `User` in the "Members" group, mapping
  email/username and a couple of profile custom fields.
- **Recruiter**: an application form creates an Entry in "Applications", mapping the file-upload
  field to an Assets custom field and the rest to plain-text fields.
- **Developer**: relies on the submission→element link to wire follow-up automation, confident
  a validation failure surfaces as a retryable dispatch failure rather than data loss.

## 5. Proposed Solution

### 5.1 Connector shape

`CraftElementIntegration implements IntegrationTypeInterface`. Because there's no external HTTP
call, it does **not** extend the marketing/CRM abstracts; it's a thin connector whose `send()`
builds and saves a Craft element. (If a second "local action" connector ever appears, factor a
small `AbstractLocalIntegration` then — premature now.)

```php
public static function handle(): string { return 'craft-element'; }
public static function displayName(): string { return 'Create Craft Element'; }
public function send(Submission $submission, array $settings): IntegrationResult { … }
```

It still flows through `IntegrationsService::dispatchForSubmission()` →
`SendIntegrationJob` → `runOnce()`, so async, retry, and dispatch-log are free. `send()`
returns `IntegrationResult::success(null, 'Created entry #123')` or
`IntegrationResult::failure(null, '<validation summary>')`.

### 5.2 Mapping UI (`settingsHtml`)

1. **Element type** — `entry` | `user`.
2. **Entry target**: section (dropdown of the site's sections) + entry type (dependent
   dropdown). **User target**: user group(s).
3. **Author** (Entry): a fixed user, or "the submitting user if logged in"
   (`Submission::$userId`), falling back to a configured default author.
4. **Status / enabled**: Entry status (`live` / `pending` / `disabled` via `enabled` +
   `expiryDate`/`postDate` semantics) or User status (`active` / `pending` / `suspended`).
5. **Attribute + custom-field mapping** — reuse the `ApiConnector::mappedFields()` mapping
   pattern (`[submissionFieldHandle => targetHandle]`). Targets are presented as a grouped list:
   native attributes (title, slug, email, username) and the section/group's custom fields
   (queried from the field layout). A "title template" option (small Twig string, e.g.
   `{name} — {{ now|date }}`) covers Entries that need a derived title.

`defineSettingsRules()` requires the element type and target, and rejects a mapping that leaves
a *required* native attribute unmapped (e.g. User email).

### 5.3 `send()` — build, validate, save, link

```php
$element = $type === 'user' ? new User() : new Entry();
$this->applyTarget($element, $settings);        // section/type/group, author, status, siteId
$this->applyAttributes($element, $submission, $settings);   // native attrs
$element->setFieldValues($this->customValues($submission, $settings)); // custom fields

if (!Craft::$app->getElements()->saveElement($element)) {
    return IntegrationResult::failure(null, $this->summariseErrors($element->getErrors()));
}
$this->linkBack($submission, $element);
return IntegrationResult::success(null, sprintf('Created %s #%d', $type, $element->id));
```

- **siteId**: created on `$submission->siteId`. For a User (not localized) this is moot; for an
  Entry the section's propagation settings then govern propagation. Element queries that need to
  find the created element later use `->siteId('*')` where the target type isn't localized.
- **Validation failure** → `failure()` → `SendIntegrationJob` throws → queue retries (3×). A
  persistently-invalid mapping means the submission is **safe** (still stored) but the element
  isn't created; the failure is visible in the dispatch log with a field-by-field summary.
- **File fields → Assets custom field**: map the submission's stored asset ids straight onto an
  Assets field.

### 5.4 Linking back

Store the created element's id + type on the dispatch-log row (already per-attempt) and surface
it on the submission detail view ("Created Entry #123 →" deep link). An idempotency guard on the
log prevents a manual *resend* from creating a duplicate unless the operator explicitly forces
it. No schema change to the submission element is required — the link lives on the existing
integration-log row.

## 6. Acceptance Criteria

- [ ] "Create Craft Element" appears in the integration type picker.
- [ ] Entry target: section + entry type selectable; submitting the form creates an Entry in
      that section with mapped native attributes and custom fields, at the configured status.
- [ ] User target: group selectable; submitting creates a User in that group with mapped
      email/username + custom fields, at the configured status.
- [ ] Author resolves to the submitting user when logged in, else the configured default.
- [ ] A title template renders against submission values for Entries.
- [ ] A validation failure (e.g. duplicate User email) produces a failed, retryable dispatch-log
      row with a readable error summary — and the submission row is **still saved**.
- [ ] The created element is created on the submission's site; non-localized targets are queried
      with `->siteId('*')` where applicable.
- [ ] The submission detail view links to the created element; a resend does not silently
      duplicate.
- [ ] Async via `SendIntegrationJob`; GraphQL submissions trigger it identically.
- [ ] PHPStan L7 + ECS clean; all UI strings via `Craft::t('simple-form', …)`.

## 7. Testing

### Unit
- Attribute + custom-field mapping builds the expected element field-value array.
- Title-template rendering against submission values.
- Author resolution (logged-in user / default / fallback).
- `send()` success → `IntegrationResult::success` carrying the new id; `saveElement` failure →
  `failure` with a non-empty, scrubbed error summary.
- `defineSettingsRules()` rejects unmapped required native attributes.
- File-field → Assets-field mapping passes asset ids through.

### craft-smoke-test scenarios
1. Configure an Entry integration (section "Events", entry type "Event", status pending), map
   a name field → title and a date field → a custom field; submit; verify a `pending` Entry
   exists with the mapped values and the submission detail links to it.
2. Configure a User integration (group "Members"); submit with a fresh email; verify the User
   exists in the group; submit again with the **same** email; verify the dispatch fails
   (duplicate email), is logged, retryable, and the submission row still exists.
3. Submit on a non-primary site; verify the Entry is created on that site and is reachable via
   `->siteId('*')`.
4. Map a file-upload field → an Assets custom field; submit with a file; verify the asset is
   related on the created Entry.
5. Hit **Resend** on a successful element-creation dispatch; verify no duplicate element is
   created.

## 8. Open Questions

- Should we expose an "update if exists" mode (match on a key field) in v1, or strictly
  create-only? Create-only keeps it simple; update is the obvious next request.
- For Users: do we send Craft's activation email when status is `pending`, or leave that to the
  site's User settings? Leaning: respect Craft's group/registration settings, don't add our own
  email path (the autoresponder already covers visitor email).
- Where exactly to persist the submission→element link — dispatch-log row (no migration) vs. a
  dedicated relation table? Leaning dispatch-log row for v1 to avoid schema churn.
- How to present custom-field targets for fields with complex value shapes (Matrix, table)?
  v1 excludes them from the mapping UI; document the limitation.
