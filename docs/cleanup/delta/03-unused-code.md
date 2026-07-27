# Delta Cleanup 03 — Unused / Dead Code

**Plugin:** Simple Form (Craft CMS 5)
**Scope:** DELTA only — PHP source changed since `c5b8fe7` (excluding WIP files: `FormsController.php`, `elements/Form.php`, `elements/db/FormQuery.php`, `services/FormRenderService.php`, `src/templates/`, `tests/`).
**Date:** 2026-06-22
**Mode:** Research-only. No source modified.

---

## 1. Critical Assessment

**The delta contains zero removable dead code.** Every new/changed `public` method, constant, and class added since `c5b8fe7` is referenced by a live caller — internal code, a registered service path, the element/query/template API, a console route, the queue/GC sweep, or the test suite.

### Method

- `composer phpstan` is **green at level 7** (285 files, no errors). Level 7 already flags unused **private/protected** methods/properties and unused `use` imports — so those entire categories are clean across the delta and were not re-hunted (none to report). knip is JS-only and does not apply.
- The only fertile ground is **unused `public` methods/constants/classes** (PHPStan never flags these — they could be dynamically dispatched). I extracted every `+`-added `public`/`protected function`/`const` in the non-WIP delta via `git diff c5b8fe7 HEAD`, then grepped the **whole** plugin (`src/`, `src/templates/*.twig`, `tests/` unit+integration+smoke) plus DI/event/registry/route wiring for each, ruling out every dynamic-dispatch path before judging.
- Framework-dispatched symbols (`safeUp`/`safeDown` migrations, element hook `attributeHtml()`, `__construct`) are auto-live and not individually grepped.

### Delta public-symbol inventory (all verified LIVE)

| Symbol | File:line | Proof of use |
|---|---|---|
| `actionExpirePayments()` | `console/.../SubmissionsController.php:94` | Craft console auto-route (`simple-form/submissions/expire-payments`); documented ops/cron command; calls `expirePending()`. Live. |
| `Submission::PAYMENT_CANCELED` | `elements/Submission.php:26` | Used at `Submission.php:227` (match) + `PaymentsService.php:36` (`STATUS_CANCELED`). Live. |
| `Submission::attributeHtml()` | `elements/Submission.php` | Element framework hook. Live. |
| `SubmissionQuery::paymentStatus()` | `elements/db/SubmissionQuery.php:67` | **Public element-query filter API** (`Submission::find()->paymentStatus(...)`), mirrors sibling `status()`; property consumed in `beforePrepare()`. Same category as the protected `SimpleFormVariable` template API — must NOT be flagged. Live. |
| `SubmissionQuery::orderId()` | `elements/db/SubmissionQuery.php:77` | Same as above — public query filter API. Live. |
| `SafeUrl::isSafeRedirectUrl()` | `helpers/SafeUrl.php` | `SubmissionService.php:1119` + `tests/unit/SafeUrlTest.php`. Live. |
| `SafeUrl::isAcceptableRedirectTemplate()` | `helpers/SafeUrl.php` | `elements/Form.php:502` + tests. Live. |
| `SafeUrl::resolveHostIps()` | `helpers/SafeUrl.php` | `SafeUrl.php:197` (`self::resolveHostIps`). Live. |
| `SafeUrl::guzzlePinDnsOptions()` | `helpers/SafeUrl.php` | `integrations/support/ApiConnector.php:110`. Live. |
| `SiteHelper::getSiteForRequest()` | `helpers/SiteHelper.php` | `FormsController.php:32,59`. Live. |
| `SiteHelper::getSiteFromPost()` | `helpers/SiteHelper.php` | `FieldsController.php:29,102`, `FormsController.php:92`. Live. |
| `PaymentsService::STATUS_CANCELED` | `services/PaymentsService.php:36` | `PaymentsService.php:254` + `tests/integration/PaymentsServiceTest.php`. Live. |
| `PaymentsService::authorizeForSubmit()` | `services/PaymentsService.php` | `SubmissionService.php:274` + smoke test. Live. |
| `PaymentsService::amountOutOfBoundsMessage()` | `services/PaymentsService.php` | `PaymentsService.php:136` + integration/smoke tests. Live. |
| `PaymentsService::markCanceled()` | `services/PaymentsService.php` | `PaymentsService.php:282` + integration test. Live. |
| `PaymentsService::expirePending()` | `services/PaymentsService.php` | `Plugin.php:261` (GC), `SubmissionsController.php:96` (console) + tests. Live. |
| `PaymentsService::paymentFormHtml()` | `services/PaymentsService.php` | `fields/PaymentFieldType.php:66`. Live. |
| `FieldModel::__construct` / `FormModel::__construct` | models | Constructors. Live. |

---

## 2. High-confidence patch list

**None.** No removable dead symbol exists in the delta.

---

## 3. Low confidence — SKIP

| Symbol | File:line | Why a candidate, why skip |
|---|---|---|
| `Submission::isPaid()` | `elements/Submission.php:58` | **LOW confidence — skip.** Zero references in `src/`, `src/templates/*.twig`, or `tests/` (grep-confirmed). Its sibling `isAwaitingPayment()` (line 51) has many internal callers; `isPaid()` is the only one of the pair never called. **But it is a `public` accessor on an _element_** → callable from end-user Twig as `submission.isPaid` and from third-party code, exactly the public-template-API category the prior audit ruled un-flaggable (`SimpleFormVariable` methods). It reads as a deliberate status-accessor companion to `isAwaitingPayment()`. Removing it risks breaking out-of-repo templates with no internal benefit. **Do not remove.** |

---

## 4. Verdict

**0 high-confidence removals.** The delta is clean; the single internal-caller-less symbol (`Submission::isPaid()`) is public element/template API and must not be removed.
