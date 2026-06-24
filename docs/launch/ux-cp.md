# Simple Form — CP UX Launch-Readiness Punch-List

Scope: control-panel templates (`src/templates/`, excluding `_form/*`), CP controllers' user-facing
strings, `src/models/Settings.php`, and the plugin CP nav (`src/Plugin.php`).

**Overall: the CP is in very good shape.** Empty states exist on every index, settings are grouped
into labelled tabs with thorough `instructions`, destructive actions confirm, and almost all copy is
`Craft::t('simple-form', …)`-wrapped and consistent in tone. The remaining gaps are small and mostly
copy/i18n. Nothing here blocks launch; the P1 i18n gap is the one I'd fix first.

Findings are grouped P1 (fix before launch) → P3 (nice-to-have), then a **QUICK WINS** section with
exact edits.

---

## P1 — Field-builder error strings are not translatable (and user-visible)

**Area:** `src/controllers/FieldsController.php` (12 strings), surfaced to the user via
`Craft.cp.displayError()` toasts and inline inspector validation (confirmed in
`src/web/assets/cp/dist/js/form-builder.js:1632-1633`).

**Issue:** Every other CP string in the plugin is `Craft::t('simple-form', …)`-wrapped, but the field
builder's `asJsonError(...)` and `$errors[...][]` messages are bare English literals. These are *not*
dev-only — they render in the CP when an editor adds/edits/reorders a field:

- `:58` `'Failed to add field'`
- `:102` `'Failed to update field'`
- `:153` `'Fields parameter must be an array'`
- `:177` `'All reordered fields must belong to a single form.'`
- `:203` `'Failed to reorder fields'`
- `:218` `'Label is required'`
- `:222` `'Handle is required'`
- `:224` `'Handle must start with a letter or underscore, and contain only alphanumeric characters and underscores'`
- `:233` `'A field with this handle already exists in this form'`
- `:238` `'Invalid field type'`
- `:243` `"{type} fields must have at least one option"` (string concatenation, not even interpolated)

**Recommendation:** Wrap each in `Craft::t('simple-form', …)`. For `:243`, switch the `$type . ' fields…'`
concatenation to `Craft::t('simple-form', '{type} fields must have at least one option', ['type' => $type])`.
Also soften the two terse `'Failed to add field'` / `'Failed to update field'` to
`Craft::t('simple-form', "Couldn't add the field.")` / `"Couldn't update the field."` to match the
"Couldn't …" voice used everywhere else in the plugin.

**Confidence:** HIGH · **Impact:** med (i18n parity is a stated plugin goal — 8+ locales shipped;
these strings are the only untranslated user-facing copy I found).

> Note: `SubmitController.php:35` `'Form handle is required'` and `:62` `'Form not found'` are
> front-end GraphQL/submit responses, not CP copy — out of scope, lower priority.

---

## P2 — Copy: generic "Couldn't complete that action." is unhelpful

**Area:** `IntegrationsController.php:151, 170, 194`; `NotificationsController.php:107, 121`; and the
matching template fallbacks (`data-error="{{ 'Couldn't complete that action.'|t(...) }}"` in
`settings/_tabs/integrations.twig:19`, `forms/notifications/index.twig:40`, `forms/integrations/index.twig:41`).

**Issue:** The same vague string covers toggle/delete/attach failures. When it fires, the user can't
tell what failed or how to recover. It's correctly translated, just not actionable.

**Recommendation:** Make the verb specific per action, e.g. `"Couldn't delete the integration."`,
`"Couldn't change that integration's status."`, `"Couldn't attach the integration to this form."`.
Lowest-effort win: append " Please try again." to the existing string.

**Confidence:** MED · **Impact:** low-med.

---

## P2 — Forms index: no per-form status, and the action row is icon-soup

**Area:** `src/templates/forms/index.twig:49-107`.

**Issues:**
1. **No status column.** Forms can be open/closed/full (open/close dates, submission limits — see
   `forms/edit.twig` Rules tab), but the index shows no at-a-glance state. An admin can't tell which
   forms are currently accepting submissions without opening each.
2. **Four empty `<th></th>` header cells** (`:56-59`) over an Integrations / Duplicate / Export / Delete
   action cluster. The empty headers read as a layout gap, and four inline buttons per row is busy.
3. **`Email To` column** (`:54, :71`) shows `form.emailTo`, but notifications are now their own subsystem
   (per-form Notifications screen). `emailTo` is the legacy single-recipient field; surfacing it as a
   primary column is slightly misleading next to the richer notifications model.

**Recommendation:**
- Add a lightweight status indicator (e.g. a `<span class="status green/off">` for
  accepting / closed / full) derived from open/close dates + limit.
- Collapse the per-row actions into a single disclosure/gear menu (Craft's `_includes/forms` element-action
  pattern), or at minimum give the action columns a shared header like `Actions`.
- Consider dropping or relabelling `Email To` → `Recipients` and reflecting notification count, or remove
  it in favour of the status column.

**Confidence:** MED (status), LOW (column choices — needs-decision) · **Impact:** med.

---

## P2 — Submission status uses raw `|upper` instead of a styled badge label

**Area:** `submissions/index.twig:86-88`, `submissions/view.twig:38-39`.

**Issue:** Status renders as `{{ submission.readStatus|upper }}` ("NEW", "READ", "ARCHIVED", "SPAM").
It's screaming-caps and the label isn't translatable (it's the raw stored handle upper-cased), unlike
the human, translated labels used in the stat-card filters right above it (`'New'|t`, `'Read'|t`, …).

**Recommendation:** Map to translated labels, e.g.
`{% set statusLabels = {new:'New'|t('simple-form'), read:'Read'|t('simple-form'), archived:'Archived'|t('simple-form'), spam:'Spam'|t('simple-form')} %}` and render
`{{ statusLabels[submission.readStatus] ?? submission.readStatus }}` inside the `status` badge. Drop
`|upper` (let CSS handle casing if desired).

**Confidence:** HIGH · **Impact:** low-med (i18n + visual polish).

---

## P3 — "Save" vs "Create" on new-record edit screens

**Area:** `settings/integrations/edit.twig:84`, `forms/notifications/edit.twig:124` — both hard-code a
`Save` button even when creating a brand-new record (title correctly switches to "New Integration" /
"New notification").

**Issue:** Minor inconsistency with Craft conventions (native full-page forms label the primary button
"Create" for new records, "Save" for existing). The forms/edit screen gets this right because it uses
`fullPageForm`'s native save menu; these two custom-form screens don't.

**Recommendation:** `{{ (record.id ? 'Save' : 'Create')|t('simple-form') }}`. Low priority — purely
conventional.

**Confidence:** MED · **Impact:** low.

---

## P3 — First-run / onboarding is functional but minimal

**Area:** Forms index empty state (`forms/index.twig:108-113`); nav (`Plugin.php:627-650`).

**Observations (mostly positive):**
- A brand-new install lands on the Forms index, which *does* have a proper empty state with heading,
  subtext, and a primary **New Form** CTA (`:108-113`). Good — there is a clear path from install →
  first form.
- The submissions, integrations, notifications, audit, and failures screens all have first-run empty
  states with CTAs. This is genuinely well covered.

**Gaps:**
1. **No pointer to settings on first run.** A new install almost always needs `Default Email Sender` set
   (Email tab) before notifications work, but nothing nudges the admin there. The Forms empty state could
   add a secondary link: "First time? Set your default sender under **Settings → Email**."
2. **Spam protection ships mostly off** (`enableCaptcha=false`, `enableAkismet=false`, honeypot on). For a
   public form builder, a one-line note on the Forms empty state or Spam tab — "The honeypot is on by
   default; add CAPTCHA/Akismet for public forms" — would help. (Spam tab already explains the stacking
   model well at `spam.twig:4`.)

**Recommendation:** Add an optional one-line secondary CTA to the Forms empty state linking to
Settings → Email. Don't over-build a wizard.

**Confidence:** MED · **Impact:** low-med.

---

## P3 — Settings model has config-only options not surfaced in the UI

**Area:** `src/models/Settings.php` vs `src/templates/settings/_tabs/*`.

**Issue:** Several real settings exist only in `config/simple-form.php` and never in the CP:
`applyFormsConfigOnUp`, `dispatchIntegrationsSynchronously`, `cacheFormStructure`, `editPath`,
`draftRetentionDays`. This is a defensible "advanced / forms-as-code" design choice (these are
deploy/perf knobs), and `inlineFormAssets` *is* surfaced. Flagging only so it's a conscious decision.

**Recommendation:** Leave as config-only, but make sure they're documented in the config-file example
(`config/simple-form.php` / README). No CP change needed. If `draftRetentionDays` is something editors
would tune, it belongs next to the other retention fields on the Privacy tab.

**Confidence:** LOW (intentional) · **Impact:** low.

---

## P3 — Minor copy / consistency nits

- **Trailing-period inconsistency in flashes.** `FormsController.php:219` `'Form saved successfully'`,
  `SubmissionsController.php:309` `'Submission approved.'` — mix of terminal-period and not across
  success flashes. Pick one convention (Craft core uses no terminal period on short notices). Low impact,
  HIGH confidence.
- **`submissions/view.twig:43`** uses `|date('Y-m-d H:i:s')` (ISO-ish) while the index uses `|date('short')`.
  Prefer `|datetime('short')` on the detail view for locale-consistent formatting. LOW/low.
- **`submissions/index.twig` pagination** is a hand-rolled `1..totalPages` loop (`:138-157`). Fine for
  small datasets but will render every page number for large submission tables. Consider Craft's
  paginate helper or windowed pagination if high-volume forms are expected. LOW (needs-decision)/low.
- **Discoverability is good.** Integrations, payments (Payment field in the builder palette,
  `forms/edit.twig:116`), conditional logic (Rules tab + notification conditions), and forms-as-code
  (Import/Export buttons on the Forms index + per-form Export) are all reachable from obvious places.
  No action needed.

---

## QUICK WINS (safe, high-confidence, small — apply directly)

These are the low-risk edits I'd ship before launch.

### 1. Wrap field-builder errors in `Craft::t` (P1)
In `src/controllers/FieldsController.php`, wrap all 12 bare strings listed under **P1**. Example pattern:

```php
// :58
return $this->asJsonError(Craft::t('simple-form', "Couldn't add the field."));
// :218
$errors['label'][] = Craft::t('simple-form', 'Label is required');
// :243
$errors['config'][] = Craft::t('simple-form', '{type} fields must have at least one option', ['type' => $type]);
```
Then add the new keys to the en source catalog (and the 8 locale files, machine-translate as elsewhere).

### 2. Translate + de-shout the submission status badge (P2/P3)
`src/templates/submissions/index.twig` (~`:85-88`) and `submissions/view.twig` (~`:37-39`):

```twig
{% set statusLabels = {
    new: 'New'|t('simple-form'),
    read: 'Read'|t('simple-form'),
    archived: 'Archived'|t('simple-form'),
    spam: 'Spam'|t('simple-form'),
} %}
<span class="status {{ submission.readStatus }}">{{ statusLabels[submission.readStatus] ?? submission.readStatus }}</span>
```

### 3. Make the generic action-error actionable (P2)
Append guidance to the shared string. In `IntegrationsController.php:151/170/194` and
`NotificationsController.php:107/121`, plus the `data-error` template fallbacks
(`settings/_tabs/integrations.twig:19`, `forms/notifications/index.twig:40`, `forms/integrations/index.twig:41`):

```
'Couldn't complete that action. Please try again.'
```
(Or, better, action-specific verbs per the P2 finding.)

### 4. "Create" on new-record screens (P3)
`settings/integrations/edit.twig:84` and `forms/notifications/edit.twig:124`:

```twig
{{ (integration.id ? 'Save' : 'Create')|t('simple-form') }}   {# integrations #}
{{ (notification.id ? 'Save' : 'Create')|t('simple-form') }}  {# notifications #}
```

### 5. Normalize flash terminal punctuation (P3)
`FormsController.php:219` → `'Form saved successfully.'` (or drop periods everywhere — just be
consistent). Trivial.

### 6. `submissions/view.twig:43` date filter (P3)
`{{ submission.dateCreated|datetime('short') }}` for locale consistency with the rest of the CP.

---

## NEEDS-DECISION (not safe to apply blind)

- **Forms-index status column + action-menu consolidation** (P2) — UX/markup change; decide whether
  to add a derived status and collapse the four action columns into a menu.
- **`Email To` column** on the Forms index — keep, relabel, or replace with status now that
  notifications are a separate subsystem.
- **First-run nudge to Settings → Email** (P3) — small, but it's added copy/markup; confirm the wording.
- **Submission pagination strategy** (P3) — only matters at high volume; decide based on expected scale.
- **`draftRetentionDays` / other config-only settings** — decide whether any belong in the Privacy tab.

---

### Counts
- Findings: **11** (1 P1, 3 P2, 7 P3/nits).
- Quick wins ready to apply: **6**.
- Needs-decision: **5**.
