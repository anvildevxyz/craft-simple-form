# Concern 6 — Unjustified defensive try/catch & fallbacks

**Audit date:** 2026-06-27
**Scope:** all of `src/`, with focused scrutiny on net-new code (coupons #246, payments #116,
workflow #248, address autocomplete #250, logic jumps #245, conversational theme).

## Verdict

**The codebase is already clean for this concern.** This is among the most disciplined error-handling
profiles I have reviewed. There are ~30 PHP catch sites and ~20 JS catch sites, and every single one
falls into a *justified* category:

1. **External/fallible IO that degrades gracefully** — Commerce gateway (`PaymentsService`), mailer
   (`EmailService`), PDF render (`PdfService`), Akismet/reCAPTCHA/Google HTTP (`AkismetService`,
   `captcha/*`, `integrations/*`), MIME sniffing (`FileFieldType`), template existence
   (`FormRenderService`), secret decryption (`IntegrationsService`). All log a warning and return a
   safe fallback.
2. **Transaction rollback + rethrow** (adds real meaning, does *not* swallow): `FormCloneService:266`,
   `FieldSyncService:501`, `FormPortabilityService:165`.
3. **Best-effort side channels that must never break the primary op** — audit log
   (`AuditService:56`), `forms/apply` after `up` (`Plugin.php:374`), partial-capture
   (`SubmitController:307`). Each is explicitly commented as best-effort.
4. **Parsing untrusted/stored input** — sandboxed Twig render (`HtmlFieldType`, `EmailService`),
   stored-config normalization (`FieldModel`, `CompositeFieldType`, `WorkflowService`), CSV date
   parse (`SubmissionCsv:396`).
5. **Front-end graceful degradation (real UX feature)** — geocoder fetch `.catch`, `JSON.parse` of
   data-attributes, `sessionStorage`/`postMessage`/`execCommand`/`setPointerCapture` guards in
   `web/assets/form/dist/js/simple-form.js`.

The net-new code is notably tight. PaymentsService catches the *specific*
`craft\commerce\errors\PaymentException` for declines (177/201) and a broad `\Throwable` only around
setup, with coupon reservation correctly released on decline. WorkflowService.transition and the
workflow controllers do **no** swallowing — they return `false`/set a session error and propagate
`NotFoundHttpException`. There is even an explicit anti-defensive comment at `Submission.php:130`
("there is no try/catch here: a genuine query/infrastructure failure is [allowed to surface]"),
showing the authors deliberately avoid over-catching.

The `?? []` / `is_array()` guards in net-new code (`CompositeFieldType`, `WorkflowService`,
`PaymentsService`) all sit on POST data or stored project-config JSON — i.e. genuinely untrusted /
possibly-malformed values, not values that are always set. None are redundant.

## Recommendations

### High confidence
None. Removing or tightening any catch site here would either re-introduce a crash path on external
IO / untrusted input, or strip rollback semantics. Nothing qualifies.

### Medium confidence
None.

### Low confidence (observations only — recommend NO change)

- **`src/fields/FileFieldType.php:134`** — `isExecutableContent()` returns `false` (treats the upload
  as *not* dangerous) when `FileHelper::getMimeType()` throws. This is a fail-*open* posture on a
  security check. The `\Throwable` catch itself is justified (MIME sniffing on an untrusted temp file
  can throw), so this is **not** an error-hiding issue in the sense of this concern. Whether the
  fallback should be fail-closed is a *security-posture* decision that belongs to that concern, not a
  defensive-code cleanup. Recommend leaving it; flagging only for cross-concern awareness.
  **Risk if changed:** could start rejecting legitimate uploads whose MIME can't be sniffed.

- **`src/web/assets/form/dist/js/simple-form.js:270`** — `Formula.evaluate` failure falls back to
  `result = 0` for a live calculation field. Defensible (a malformed live formula shouldn't break the
  form), and the server re-validates. No change.

## Bottom line
No actionable cleanup for Concern 6. The error-handling discipline is exemplary and should be left
intact. Do not "tidy" any of these catches — each one is load-bearing.
