# Concern #7 — Deprecated, Legacy & Fallback Code (Assessment)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2)
**Version floor:** `craftcms/cms: ^5.0`, `php: ^8.2`
**Phase:** 1 — ASSESSMENT ONLY (no source edits made)
**Scope:** 72 PHP files under `src/`
**Date:** 2026-06-14

---

## Summary

The plugin is brand-new and **pre-1.0** (CHANGELOG shows only an `[Unreleased]`
section; git history is the initial scaffold + feature slices; a single init
migration `m240614_000001_init.php`). For a codebase of this age, the
expectation is that *any* "legacy" construct is either accidental scaffolding
cruft or a mis-labelled comment — there is no prior released version to be
backward-compatible with.

That expectation holds. After an exhaustive sweep for `@deprecated`, `legacy`,
`fallback`, `BC`/`back-compat`, `version_compare`/`getVersion`,
`method_exists`/`class_exists`/`function_exists` polyfills, dead feature flags,
`class_alias`, "TODO remove", and "for now / just in case" markers, **the
codebase contains no deprecated, version-gated, or redundant-dual-path legacy
code.**

Concretely:

- **0** `@deprecated` markers.
- **0** `version_compare` / `Craft::$app->getVersion` / `::VERSION` checks — so
  there are **no** Craft-version-gated branches that could be dead against the
  `^5.0` floor.
- **0** PHP-version gates — nothing keyed below `^8.2`.
- **0** BC aliases (`class_alias`), compatibility wrappers, or "old way vs new
  way" forks.
- **1** `class_exists` call (`FieldTypeRegistry`) — a *defensive registration
  assertion*, **not** a polyfill (analysed below).
- All settings flags (`enableHoneypot`, `enableCaptcha`, `enableMcp`,
  `cacheFormStructure`, `inlineFormAssets`) are read **and meaningfully
  branched** — there are no dead feature flags.
- The element layer (`Form`, `Submission`), propagation trait
  (`HasPropagation`), and field base (`FieldType`) use only the modern Craft 5
  native API (e.g. the `craft\enums\PropagationMethod` enum) with no compat
  shims.

The handful of string matches for "fallback" / "legacy" / "old" are all
**legitimate runtime behaviour**, not back-compat code. Each is itemised below
so reviewers don't re-flag them, plus one cosmetic observation.

There is, however, **one genuine redundant-duplicate-path** that falls squarely
inside this concern's mandate ("where two code paths exist for one job, collapse
to the single correct one"): the `getOptions()` method is **copied verbatim
across three field-type classes** and should be hoisted to the shared base. See
**H1**.

**HIGH-confidence redundant-duplicate items: 1 (H1).**
**HIGH-confidence dead / version-gated / deprecated items: 0.**

---

## Findings

### HIGH — redundant duplicate path (collapse to one)

#### H1 — `getOptions()` duplicated verbatim across three field types
**Files / lines (identical bodies):**
- `src/fields/SelectFieldType.php:37-56`
- `src/fields/RadioFieldType.php:37-56`
- `src/fields/CheckboxFieldType.php:41-60`

All three define a byte-for-byte identical `protected function getOptions(): array`:
```php
protected function getOptions(): array
{
    $options = $this->config['options'] ?? [];
    if (is_string($options)) {
        $options = json_decode($options, true) ?? [];
    }
    // Convert array of {label, value} objects to keyed array
    $result = [];
    if (is_array($options)) {
        foreach ($options as $opt) {
            if (is_array($opt) && isset($opt['value'], $opt['label'])) {
                $result[$opt['value']] = $opt['label'];
            } elseif (is_object($opt) && isset($opt->value, $opt->label)) {
                $result[$opt->value] = $opt->label;
            }
        }
    }
    return $result;
}
```
**Why it's redundant:** This is one job (normalise the `options` config into a
`value => label` map) implemented three times. It is *not* polymorphic — the
three copies behave identically; each subclass's `validate()` and
`renderInput()` consume the same shape. The three other field types
(`Text`, `Email`, `Textarea`, `Number`, `Date`) have no options and don't need
it.

**Single clean path to keep:** Hoist exactly one copy to the shared base
`src/fields/FieldType.php` as `protected function getOptions(): array` and delete
all three subclass overrides. No behavioural change; the subclasses already call
`$this->getOptions()`. (Phase-2 edit — not performed here.)

**Confidence: HIGH.** Provably identical text; the option-bearing field types
are the only callers; collapsing is behaviour-preserving.

---

### Non-issues (matched the search terms but are legitimate — do NOT remove)

These are recorded so a future audit doesn't waste effort on them.

#### N1 — `FieldQueryHelper.php:72` — "malformed/legacy" JSON guard
```php
$config = $row['config'] ? json_decode($row['config'], true) : [];
// Guard against malformed/legacy values that don't decode to an array.
if (!is_array($config)) { $config = []; }
```
**Verdict:** KEEP. This is defensive normalisation of a free-form JSON DB column,
not a back-compat branch for an older schema. `json_decode` can legitimately
return a non-array (scalar/`null`) for hand-edited or partial data; the guard
ensures downstream `array` typing holds. The word "legacy" in the comment is
descriptive, not a version gate. **Confidence: HIGH (it is NOT legacy code).**

#### N2 — `FieldTypeRegistry.php:40` — `class_exists` guard
```php
if (!class_exists($class)) {
    throw new \InvalidArgumentException("Field type class does not exist: $class");
}
```
**Verdict:** KEEP. Not a polyfill / version shim. It validates the
`class-string` passed to `registerFieldType()` (which is the public extension
point other plugins use to register field types) and fails fast with a clear
message. There is no alternate code path — it only throws. **Confidence: HIGH
(NOT legacy).**

#### N3 — `McpServer.php:238` — MCP "backwards compatibility" text block
```php
// Per MCP: structured content SHOULD also be serialised into a text
// content block for backwards compatibility.
return $this->result($id, [
    'content' => [['type' => 'text', 'text' => ...json_encode($structured)...]],
    'structuredContent' => $structured,
    ...
]);
```
**Verdict:** KEEP. "Backwards compatibility" here refers to the **MCP wire
protocol spec** (clients that don't read `structuredContent` fall back to the
text block), not to this plugin's own history. Both keys are part of one
response, not two selectable code paths. Removing the text block would break
spec-compliant clients. **Confidence: HIGH (required by spec).**

#### N4 — `TwigExtension.php:116-135` — asset bundle vs. inline escape hatch
```php
if (!$settings->inlineFormAssets) {
    try { $view->registerAssetBundle(FormAsset::class); return ''; }
    catch (\Throwable $e) { /* fall through to inline */ }
}
$css = @file_get_contents(FormAsset::distPath('css/simple-form.css')) ?: '';
$js  = @file_get_contents(FormAsset::distPath('js/simple-form.js')) ?: '';
return '<style>'.$css.'</style><script>'.$js.'</script>';
```
**Verdict:** KEEP (both paths). This *is* a genuine dual path, but it is **not
legacy** — it is two intentionally-supported delivery modes for one job:
(a) cache-bustable asset bundle (default, the performant path), and (b) inline
output, selected by the `inlineFormAssets` setting **or** when no web `View`
can publish assets (console/test/email contexts where `registerAssetBundle`
throws). The `FormAsset::distPath()` helper exists solely to let the inline
branch read the *same* `dist/` files the bundle serves, so there is no
duplicated content — single source, two delivery modes. Collapsing it would
remove a documented feature and break asset-less render contexts. **Confidence:
HIGH (legitimate feature, not duplication).**

#### N5 — `EmailService.php:39-40, 64-70` — sender + subject fallback chains
```php
$fromEmail = $this->getSettings()->getSenderEmail() ?? (is_string($parsedFromEmail) ? $parsedFromEmail : null);
...
// Use configured subject or fallback
if ($form->emailSubject) { return $form->emailSubject; }
return Craft::t('simple-form', 'New Submission: {formTitle}', [...]);
```
**Verdict:** KEEP. These are ordinary precedence chains (plugin sender setting →
Craft system mail settings; per-form subject → translated default), not a
primary-path-plus-redundant-duplicate. Each tier resolves a *different* source.
This is normal configuration layering. **Confidence: HIGH (NOT legacy).**

#### N6 — `SiteHelper.php:12-49` — two site-resolution methods
`getSiteForRequest()` (reads `?site=` query param, for GET/CP screens) and
`getSiteFromPost()` (reads posted `siteId`, for form submits) look superficially
like a dual path but resolve from **different request inputs** for different
flows. They share the private `applySite()` sink. Not duplication, not legacy.
**Confidence: HIGH (NOT legacy).**

#### N7 — `FormStructureService.php:172-183` — `cachingEnabled()` guard
Disables the structure cache in `devMode`, when `cacheFormStructure` is off, or
when Craft runs a `DummyCache`. This is a single guard with three correct
conditions, not an "old vs new" fork. The non-cached path is the same query
logic without the cache wrapper, not a separate implementation. **Confidence:
HIGH (NOT legacy).**

---

### Cosmetic observation (not legacy, not actionable for this concern)

#### C0 — `CaptchaService.php:44 & 53` — `Craft::$app->getRequest()` fetched twice
The method fetches the request once at line 44 (only when `$token === null`, to
read the posted token) and again at line 53 (for the verify call). This is a
trivial duplicate accessor call, **not** a deprecated/legacy/dual-path
construct, so it is **out of scope for concern #7**. It is a cosmetic
efficiency/clarity nit (resolve the request once) better handled under a
"simplification/efficiency" concern. **Confidence: LOW (out of scope here).**

#### C1 — `Plugin.php:43` — `schemaVersion = '2.0.0'` on a pre-1.0 plugin
```php
public string $schemaVersion = '2.0.0';
```
The plugin has a **single** init migration and no prior release, yet declares
schema version `2.0.0`. This is not deprecated/legacy code and has no dead code
path attached — Craft only uses it to decide whether to run pending migrations.
It is flagged only as a mild inconsistency (a fresh plugin would normally start
at `1.0.0`). **No removal recommended under concern #7;** note it for whoever
owns versioning. **Confidence: LOW (cosmetic, out of scope).**

---

## High-confidence implementation checklist

1. **[H1] Collapse the triplicated `getOptions()` into the base class.**
   - Add `protected function getOptions(): array { ... }` (the identical body) to
     `src/fields/FieldType.php`.
   - Delete the override from `src/fields/SelectFieldType.php:37-56`,
     `src/fields/RadioFieldType.php:37-56`, and
     `src/fields/CheckboxFieldType.php:41-60`.
   - Behaviour-preserving; run `composer test` + `composer check` to confirm.

There are **no** provably-dead version gates, BC aliases, `@deprecated` markers,
polyfills, dead feature flags, or version-gated branches to remove — H1 is the
only structural cleanup, and it is a duplicate-path collapse rather than legacy
removal.

*Out of scope for concern #7 (recorded for other concerns, not on this
checklist):* C0 (duplicate `getRequest()` call — efficiency) and C1
(`schemaVersion` cosmetic).
