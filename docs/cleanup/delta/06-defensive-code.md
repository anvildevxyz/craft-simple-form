# 06 — Defensive Code / Exception Handling (DELTA)

Plugin: Simple Form (Craft CMS 5)
Scope: PHP source changed since `c5b8fe7` (delta pass), WIP files excluded.
Date: 2026-06-22
Mode: research-only (no source modified).

Builds on `docs/cleanup/06-defensive-code.md` (full pass, 2026-06-21: 0 High,
0 Medium, 6 Low — all "keep"). Commit `963ac70` already de-swallowed the
genuine-error Throwable catches; this pass does not re-flag those.

---

## Method

64 changed PHP files in scope (4 WIP files excluded: `FormsController`,
`elements/Form`, `db/FormQuery`, `FormRenderService`). I diffed `c5b8fe7..HEAD`
and isolated **added** lines only:

- New `try` / `catch` blocks: **2** (both in `PaymentsService`).
- New defensive guards (`isset` / `is_array` / `is_string` / `=== null` /
  `?? default`): ~90 added lines across helpers, services, controllers, GQL.

---

## 1. Critical assessment

The delta is **clean for this concern.** Two patterns dominate, both correct:

1. **The only new try/catch are payment-gateway IO** (`PaymentsService::authorizeForSubmit`).
   These are exactly the "third-party integration / payment gateway / must not
   crash the request" category the brief says to KEEP. Both fail closed (return
   an `error` result so the caller persists nothing), both log, and the narrow
   one already catches the specific `PaymentException`. See details below.

2. **Every new defensive guard sits over untyped/serialized/external data** —
   decoded JSON field `config`, `mixed` GraphQL arguments, raw request params,
   DB-row arrays, and submission `$data`. None guards a value the type system
   already proves non-null/typed. Removing any of them would either reintroduce
   a real `TypeError`/`Undefined key` risk or break PHPStan L7 (the gate).

No error-hiding, no catch-and-continue masking bugs, no redundant guard on a
typed non-null value. **0 High, 0 Medium, 0 Low patches.**

---

## 2. New try/catch — reviewed, KEEP both

### `PaymentsService::authorizeForSubmit()` — `catch (\Throwable)` around gateway/order setup
`src/services/PaymentsService.php:144-151`

```php
try {
    $gateway = $this->gateway();
    $donation = $this->donation();
    $order = $this->buildOrder($gateway, $donation, $amount, $this->submitterEmail($form, $data));
} catch (\Throwable $e) {
    Craft::error('Payment setup failed (#116): ' . $e->getMessage(), 'simple-form');
    return $this->result('', 0, $amount, null, Craft::t('simple-form', 'Payments are not available right now. Please try again later.'));
}
```

- Wraps Commerce gateway/donation/order construction — third-party plugin IO
  that can throw a range of types (missing gateway, misconfig, DB). `\Throwable`
  breadth is justified by the unbounded Commerce surface. Fails closed with a
  generic, credential-free user message; logs at `error`. **KEEP.**

### `PaymentsService::authorizeForSubmit()` — `catch (PaymentException)` around the charge
`src/services/PaymentsService.php:158-164`

```php
try {
    \craft\commerce\Plugin::getInstance()->getPayments()->processPayment($order, $paymentForm, $redirect, $transaction);
} catch (\craft\commerce\errors\PaymentException $e) {
    Craft::warning('Payment declined (#116): ' . $e->getMessage(), 'simple-form');
    return $this->result('', 0, $amount, null, Craft::t('simple-form', 'Your payment could not be processed.'));
}
```

- Narrow, typed catch (the right exception, not a broad `\Throwable`) on the
  actual charge call. Declines fail closed; logs at `warning`. Textbook payment
  handling. **KEEP.**

---

## 3. New defensive guards — spot-checked, all legitimate

Representative samples (all KEEP — operate on untyped/external input):

| Site | Source of value | Why the guard is required |
|---|---|---|
| `ConditionalEvaluator.php:123` `is_array($ruleSet) ? … : []` | `$conditional['rules'] ?? []` from decoded JSON config | `$conditional` is untyped array data; rules may be malformed. |
| `FormMutations.php:271` `is_array($inputValues) ? … : []`; `:277` `!is_array($entry)` | `mixed $inputValues` (raw GraphQL arg) | Parameter is `mixed` by contract; client-supplied. |
| `FieldQueryHelper.php` / `FieldSyncService.php` `is_array($config) ? $config : []` | decoded field `config` JSON | Stored JSON, not type-guaranteed. |
| `AkismetService` / `DenylistService` / `SubmissionBodyRenderer` `is_array($value) ? implode(...) : (string)$value` | submission field values | External submission data, scalar-or-array. |
| `SafeUrl.php` `$parts === false`, `!isset($parts['scheme'],'host')` | `parse_url()` output | `parse_url` can return `false` / partial; this is the SSRF/scheme guard. |
| `SubmissionCsv.php` `is_array($entry) && in_array($entry['type'] ?? null, …)` | stored asset-reference rows | Serialized data shape validation. |
| `NotificationsService` `isset($row['subject']) ? (string)… : null` | DB result row | Nullable columns; defensive cast is correct. |
| `PaymentsService.php:188-189` `isset($config['minAmount']) && is_numeric(...)` | decoded Payment field config | Optional config keys over untyped JSON. |

These match exactly the "KEEP" categories in the brief (untrusted input, external
data, serialized config) and several are also required to satisfy PHPStan L7.
Removing them is neither safe nor gate-compatible.

---

## 4. Verdict

**0 high-confidence patches.** The delta introduces only payment-gateway IO
try/catch (correct, fail-closed, logged) and defensive guards over
untyped/serialized/external data (all required and/or gate-load-bearing). Nothing
to remove. Consistent with the full-pass finding that this dimension is already
clean.
