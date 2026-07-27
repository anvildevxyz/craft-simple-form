# Controller optimisation audit — `src/controllers/`

Behaviour-preserving optimisation opportunities across all 11 controller files.
Research only — no source was modified.

Scope: `AuditController`, `FieldsController`, `FormsController`,
`IntegrationsController`, `McpController`, `NotificationsController`,
`SettingsController`, `SimpleFormControllerTrait`, `SubmissionEditController`,
`SubmissionsController`, `SubmitController`.

All findings are checked against the hard constraints: no change to public I/O,
side-effects, error behaviour, runtime ordering, request-param handling,
response envelopes, or validation order; ECS-clean (Craft style, PHPDoc/early
returns preserved); PHPStan level 7 safe; no new deps; no cross-file moves.

## Summary

- **HIGH-confidence findings: 1**
- **MED-confidence findings: 1**
- **LOW-confidence findings: 1**
- **Total: 3**

The directory is already lean (it has passed 5 prior cleanup audits). Most
`foreach` loops here carry side-effects (DB lookups, queue pushes, memoised
`??=` with `continue`, in-place reference mutation) or early-return-with-effect,
so they are correctly **excluded**. The few genuine opportunities are listed
below; the rest of this document records what was inspected and deliberately
left alone.

## Findings table

| # | File:lines | Kind | Confidence |
|---|------------|------|------------|
| 1 | `SettingsController.php:182-189` | Redundant repeated pure static call (`Scopes::all()` ×3 → 1 local) | HIGH |
| 2 | `SubmissionsController.php:203-206` | `foreach` → `array_column` (id-keyed name map) | MED |
| 3 | `IntegrationsController.php:30-36` | Redundant single-use intermediate `$service` | LOW |

---

## HIGH-CONFIDENCE

### Finding 1 — `SettingsController::renderTab` calls `Scopes::all()` three times

**File:** `src/controllers/SettingsController.php:182-189`

**Current code:**

```php
if ($tab === 'mcp') {
    $vars['mcpTokens'] = Plugin::getInstance()->getMcpTokenManager()->allTokens();
    $vars['mcpScopes'] = Scopes::all();
    // Single source of truth for scope labels (was duplicated + stale in
    // an inline template macro and the translation catalog).
    $vars['mcpScopeLabels'] = array_combine(
        Scopes::all(),
        array_map(Scopes::label(...), Scopes::all()),
    );
    // Plaintext secret is only ever surfaced once, immediately after
    // creation, via the flash set in actionCreateMcpToken().
    $vars['mcpNewSecret'] = Craft::$app->getSession()->getFlash('mcpNewSecret');
}
```

**Replacement:**

```php
if ($tab === 'mcp') {
    $vars['mcpTokens'] = Plugin::getInstance()->getMcpTokenManager()->allTokens();
    $scopes = Scopes::all();
    $vars['mcpScopes'] = $scopes;
    // Single source of truth for scope labels (was duplicated + stale in
    // an inline template macro and the translation catalog).
    $vars['mcpScopeLabels'] = array_combine(
        $scopes,
        array_map(Scopes::label(...), $scopes),
    );
    // Plaintext secret is only ever surfaced once, immediately after
    // creation, via the flash set in actionCreateMcpToken().
    $vars['mcpNewSecret'] = Craft::$app->getSession()->getFlash('mcpNewSecret');
}
```

**Why behaviour-identical:**
`Scopes::all()` returns a hard-coded constant array
(`[FORMS_MANAGE, SUBMISSIONS_READ, SUBMISSIONS_EXPORT]`, verified in
`src/mcp/Scopes.php:47-54`) — it is a pure, deterministic static with no
arguments, no side-effects, and no per-call variation. Calling it once and
reusing the value yields byte-identical arrays for `mcpScopes`, the
`array_combine` keys, and the `array_map` source. `array_combine` still pairs
the same keys with the same labels in the same order. No ordering, error, or
I/O change.

**Benefit:** Eliminates two redundant function calls + two array allocations
per `mcp`-tab render; reads clearer (one named source of truth for the scope
list, which matches the existing "single source of truth" intent comment).

**Confidence:** HIGH. ECS-clean (plain local, no golfing). PHPStan L7 safe:
`$scopes` is inferred as `list<string>`, exactly what `array_combine` /
`array_map` already consume.

---

## MED-CONFIDENCE

### Finding 2 — `SubmissionsController::actionView` builds an id→name map by hand

**File:** `src/controllers/SubmissionsController.php:202-206`

**Current code:**

```php
$integrations = $form ? $integrationsService->getIntegrationsForForm((int) $form->id) : [];
$integrationNames = [];
foreach ($integrations as $integration) {
    $integrationNames[(int) $integration->id] = $integration->name;
}
```

**Replacement:**

```php
$integrations = $form ? $integrationsService->getIntegrationsForForm((int) $form->id) : [];
$integrationNames = array_column($integrations, 'name', 'id');
```

**Why (almost) behaviour-identical:**
`getIntegrationsForForm()` returns `list<IntegrationModel>` (verified
`IntegrationsService.php:67-76`) whose `id` (`?int`) and `name` (`string`) are
public properties, so `array_column($integrations, 'name', 'id')` reads them
directly and produces an `id => name` map in the same iteration order. The loop
is a pure transform: no side-effects, no early return.

**Caveat (why MED, not HIGH):** the manual loop casts the key with `(int)
$integration->id`. For persisted integration rows `id` is always a non-null
int, so `array_column` (which uses the raw `id` value) yields identical integer
keys. The only divergence would be a hypothetical `id === null` row, where the
loop produces key `0` but `array_column` produces key `""`. That state does not
occur for rows returned by this query, but because the cast is technically
load-bearing for an (unreachable) edge case, this is graded MED rather than
HIGH. If absolute key-identity for a null id must be preserved, leave as-is.

**Benefit:** Two lines instead of four; expresses intent (column re-key)
directly; one C-level call instead of a PHP loop.

**Confidence:** MED. ECS-clean. PHPStan L7: `array_column` over
`list<IntegrationModel>` is well-typed; result is `array<int, string>` (the
template treats it as an int-keyed lookup, unchanged).

---

## LOW-CONFIDENCE

### Finding 3 — `IntegrationsController::actionSettingsIndex` single-use `$service`

**File:** `src/controllers/IntegrationsController.php:28-38`

**Current code:**

```php
public function actionSettingsIndex(): Response
{
    $service = Plugin::getInstance()->getIntegrations();

    return $this->renderTemplate('simple-form/settings/index', [
        'selectedSettingsSubnavItem' => 'integrations',
        'integrations' => $service->getAllIntegrations(),
        'typeNames' => Plugin::getInstance()->getIntegrationTypeRegistry()->getAllTypes(),
        'failedDispatchCount' => $service->countFailedDispatches(),
    ]);
}
```

**Observation:** `$service` here is the only place in the class that resolves
the integrations service *without* the private `service()` helper
(`IntegrationsController.php:40-43`), even though `$service` is used twice. This
is a stylistic inconsistency, not an inefficiency — `$service` is correctly
reused for its two calls, so there is no redundant lookup to remove. Replacing
the local with two `$this->service()` calls would *add* a redundant resolution,
so that is **not** recommended.

**Why listed:** Only as a note that the existing local is already the efficient
form. **No change recommended** — included for completeness so a future pass
does not "DRY" this into a slower double `service()` call.

**Confidence:** LOW (informational; net effect of any edit here is neutral or
negative). **Do not change.**

---

## Inspected and deliberately NOT flagged

These were considered and rejected as unsafe or non-improving:

- **`AuditController::actionIndex` (31-39)** — the `foreach` calls
  `getUserById()` (a DB lookup) and memoises with `??=` + `continue`. Has
  side-effects / early-continue; not a pure transform. Keep.
- **`FormsController::renderEdit` phoneCountries (394-397)** — builds from the
  *key and value* of `DialCodes::all()` plus a `DialCodes::label($iso)` call;
  `array_map` cannot consume keys cleanly and would be less readable. Keep.
- **`FormsController` `$stencils` / `$volumes` / `relationSources` mapping** —
  already use `array_map` + `array_values`. Optimal.
- **`FieldsController::actionReorder` `$ordered` loop (160-164)** — filter +
  index arithmetic (`$index + 1`) with an `isset` guard; `array_*` would not be
  clearer and the index semantics are easier to verify as a loop. Keep.
- **`FieldsController::actionReorder` update loop (183-188)** — issues a DB
  `update` per row inside a transaction; side-effecting, ordering-sensitive.
  Keep.
- **`NotificationsController::fieldOptions` (180-184)** — already
  `array_map('strval', array_column(...))`. Optimal.
- **`SettingsController::actionSave` (81-97)** — `array_flip` + `isset`
  dispatch is idiomatic and the loop reads each posted body-param exactly once
  in the required order. No safe consolidation. Keep.
- **`SettingsController` `array_flip(self::BOOL_FIELDS)` etc.** — could be
  module-level constants, but that changes nothing observable and ECS/clarity
  gain is nil. Keep.
- **`IntegrationsController::actionResendAll` (251-262)** and
  **`actionResend`** — `$queue` already hoisted; loop pushes jobs
  (side-effects) with a `continue` guard. Keep.
- **`SubmissionsController::actionView` elementLinks loop (210-215)** and
  **`actionIndex` param reads (89-93)** — the param reads feed both the template
  redisplay *and* `buildFilteredQuery`, which is shared with the CSV export;
  hoisting would change a shared method's contract / param-read ordering. Keep.
- **`SubmissionEditController::actionUpdate` (78-103)** — the two `foreach`
  loops perform upload validation and asset creation (side-effects), and the
  first short-circuits via `$fileErrors`. Ordering- and effect-sensitive. Keep.
- **`SubmitController` / `McpController`** — every branch is an observable
  request/response path (status codes, JSON-RPC envelopes, rate-limit ordering);
  no pure-transform loops. Keep.
