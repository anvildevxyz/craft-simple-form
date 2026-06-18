# PRD — Outbound Integrations Framework

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-18
**Tracking issue:** [#75](https://github.com/fabianhaef/craft-simple-form/issues/75)

---

## 1. Problem Statement

Simple Form captures submissions and emails a notification, but the data dead-ends
there. Once a submission is saved, there is **no way to push it anywhere else** — no
webhook, no CRM, no email-marketing list, no Slack ping. Every real-world form is part
of a larger flow ("new lead → HubSpot", "signup → Mailchimp audience", "support request
→ Slack channel", "anything → our own endpoint"), and today the only escape hatches are
the developer-facing `EVENT_AFTER_SUBMISSION_SAVE` PHP event or polling via GraphQL/MCP.

Outbound integrations are the single biggest functional gap versus mature form plugins
(Formie, Freeform), and the most common reason a team would reach for one of those
instead. The plugin already has the right seam — a transport-agnostic
`SubmissionService::submit()` that fires before/after events — so the work is to build a
**first-class, configurable integrations layer** on top of it rather than asking every
site to write custom event listeners.

## 2. Goals

- Provide a **pluggable integration architecture**: a small interface + registry so new
  connectors (webhook, CRM, marketing, chat) are added without touching the submission
  path.
- Ship a **generic outbound Webhook integration** as the reference implementation and
  immediate value (covers Zapier/Make/n8n and any custom endpoint).
- Let creators **configure integrations per form** in the CP (enable, map form fields →
  the integration's expected payload, set credentials/target).
- **Dispatch asynchronously** off `EVENT_AFTER_SUBMISSION_SAVE` via a queue job so a slow
  or failing third party never blocks or fails the visitor's submission.
- Make dispatch **observable and resilient**: per-attempt logging, retry on failure,
  visible success/error status in the CP.
- Keep secrets **env-aware** (reuse the `EnvAttributeParserBehavior` pattern already used
  for reCAPTCHA keys) and never persist plaintext credentials in project config output.

## 3. Non-Goals (v1)

- **Inbound** integrations (importing external data into forms/submissions).
- A large catalog of pre-built CRM/marketing connectors. v1 ships the **framework + the
  generic Webhook connector**; named connectors (HubSpot, Mailchimp, Slack) are
  fast-follow slices built *on* the framework, not gated by it.
- Field-mapping transformations beyond simple field→key mapping (no formulas, no
  conditional routing) — deferred.
- OAuth-based connectors. v1 connectors authenticate with **API keys / bearer tokens /
  signed webhooks**; OAuth handshakes (e.g. full Salesforce) are out of scope.
- Two-way sync or delivery-status callbacks from the third party.

## 4. Users & Use Cases

- **Marketer / site owner:** "When the contact form is submitted, POST it to our Zapier
  catch-hook" or "add the email to our Mailchimp audience" — configured in the CP, no
  developer.
- **Agency developer:** wants to add a bespoke connector (e.g. an internal CRM) by
  implementing one interface and registering it via an event, the same soft-dep pattern
  used for MCP and field types.
- **Ops / support:** wants a Slack message in `#leads` on every submission, and wants to
  see in the CP whether the last dispatch succeeded.

---

## 5. Proposed Solution

### 5.1 Architecture — interface + registry, dispatched off the existing event

```
Submission saved
  └─ Plugin::EVENT_AFTER_SUBMISSION_SAVE  (already fired today)
       └─ IntegrationsService listener
            └─ for each enabled integration on the form:
                 └─ queue SendIntegrationJob(integrationId, submissionId)
                      └─ Integration::send(Submission, settings) → IntegrationResult
                           └─ log attempt; retry on failure (queue-native)
```

- **`IntegrationTypeInterface`** (mirrors `fields/FieldType` + `mcp/tools/ToolInterface`
  conventions): `handle()`, `displayName()`, `settingsHtml()`, `defineSettingsRules()`,
  `send(Submission $submission, array $settings): IntegrationResult`. Connectors are
  stateless transformers — config comes in, an HTTP/SDK call goes out, a result comes
  back.
- **`IntegrationTypeRegistry`** service (mirrors `FieldTypeRegistry`): core types
  registered in `init()`; third parties add their own via a new
  `Plugin::EVENT_REGISTER_INTEGRATION_TYPES` event (soft-dep friendly, same shape as the
  craft-mcp `EVENT_REGISTER_TOOLS` pattern). craft-mcp itself stays optional.
- **`IntegrationsService`** owns: listing/saving per-form integration configs, listening
  on `EVENT_AFTER_SUBMISSION_SAVE`, enqueuing one `SendIntegrationJob` per enabled
  integration, and recording results.

### 5.2 Data model

Two new tables (the field/submission `config`-JSON trick doesn't fit here — integrations
are first-class, per-form, multi-instance, and need their own status rows):

- **`simpleform_integrations`** — one row per configured integration instance:
  `id`, `formId` (FK → forms, cascade delete), `type` (handle, e.g. `webhook`),
  `name`, `enabled` (bool), `settings` (JSON; secrets stored as env refs / encrypted),
  `sortOrder`, dateCreated/Updated/uid.
- **`simpleform_integration_logs`** — one row per dispatch attempt:
  `id`, `integrationId` (FK, cascade), `submissionId` (FK, set-null on submission delete),
  `status` (`success` | `failed` | `pending`), `attempts`, `responseCode`,
  `message` (truncated response/error), dateCreated.

Settings JSON shape is connector-defined and validated by the connector's
`defineSettingsRules()`. Secret attributes route through `EnvAttributeParserBehavior`
so `$WEBHOOK_SECRET`-style env refs work and plaintext never lands in exported project
config.

### 5.3 Reference connector — generic Webhook (v1 must-have)

`WebhookIntegration` settings:
- **URL** (required, env-aware).
- **Method** — `POST` (default) | `PUT`.
- **Payload format** — `json` (default) | `form-encoded`.
- **Field mapping** — optional `submissionFieldHandle → payloadKey` map; default is the
  full submission as `{ handle: value, ... }` plus form/site/timestamp metadata.
- **Secret** (optional, env-aware) — when set, sign the body with HMAC-SHA256 and send
  `X-SimpleForm-Signature` so the receiver can verify authenticity.

`send()` issues the HTTP request with a bounded timeout via Craft's Guzzle client,
returns `IntegrationResult::success($code)` on 2xx, `::failure($code, $body)` otherwise.

### 5.4 Dispatch, async & resilience

- Listener on `EVENT_AFTER_SUBMISSION_SAVE` enqueues a `SendIntegrationJob` per enabled
  integration — **never inline**, so third-party latency/outages can't fail or delay the
  visitor's POST (Twig, REST, and GraphQL submit all funnel through the same event, so
  all three get integrations for free).
- The job is **idempotent-ish**: keyed by `(integrationId, submissionId)`; it writes a
  `pending` log row, calls `send()`, updates the row to `success`/`failed`.
- **Retries** use Craft's native queue retry (`canRetry()`/`getTtr()`); after max
  attempts the log row stays `failed` with the last response captured.
- A `dispatchIntegrationsSynchronously` plugin setting (default off) runs inline for
  local/dev debugging.

### 5.5 CP UI

- **Per-form "Integrations" tab** on the form edit screen: a list of configured
  integrations (name, type, enabled toggle, last-dispatch status badge) with add/edit/
  delete. "Add integration" → choose a registered type → render its `settingsHtml()`.
- **Submission detail:** a "Integrations" panel showing each integration's latest log row
  for that submission (status, response code, time) with a **"Resend"** action that
  re-enqueues `SendIntegrationJob`.
- Settings render with env-aware `autosuggestField` for secret/URL fields, matching the
  existing reCAPTCHA settings pattern.

### 5.6 Permissions

Extend `SimpleFormPermissions` with a `manageIntegrations` permission gating the
Integrations tab and resend action, alongside the existing form/submission permissions.

### 5.7 GraphQL & MCP exposure

- **GraphQL:** read-only `integrations` field on `FormType` (type, name, enabled — **no
  secrets**) so headless clients can introspect what's wired up. No mutation in v1.
- **MCP:** a read-only `ListIntegrationsTool` (or extend `GetFormTool`) surfaces
  configured integrations and recent dispatch health to an AI agent — **never exposing
  secret settings**, consistent with the existing scope model. Write/config via MCP is
  deferred (config-with-credentials over MCP needs its own auth review).

### 5.8 Extensibility contract (third-party connectors)

A developer ships a connector by implementing `IntegrationTypeInterface` and registering
it:

```php
Event::on(
    Plugin::class,
    Plugin::EVENT_REGISTER_INTEGRATION_TYPES,
    fn(RegisterIntegrationTypesEvent $e) => $e->types[] = HubSpotIntegration::class,
);
```

This is the same registration ergonomics as field types and MCP tools, so the named
connectors (HubSpot/Mailchimp/Slack) become independent fast-follow slices that need
**zero changes to the core submission path**.

---

## 6. Acceptance Criteria

- [ ] A form can have **one or more integrations** configured in the CP, each with its
      own type, name, enabled flag, and type-specific settings.
- [ ] The bundled **Webhook** connector POSTs a configurable JSON/form-encoded payload to
      a target URL, optionally HMAC-signed, and records the response.
- [ ] Dispatch happens **asynchronously via the queue** off `EVENT_AFTER_SUBMISSION_SAVE`;
      a failing/slow endpoint never fails or delays the visitor's submission. Verified
      across Twig, REST, and GraphQL submit paths.
- [ ] Failed dispatches **retry** via the native queue and end in a visible `failed` log
      with the captured response; the **Resend** action re-enqueues a dispatch.
- [ ] Secrets are **env-aware** and never appear in exported project config or in
      GraphQL/MCP output.
- [ ] A third party can register a **custom integration type** via
      `EVENT_REGISTER_INTEGRATION_TYPES` without modifying core.
- [ ] Deleting a form cascades to its integrations and logs; deleting a submission leaves
      logs intact (submissionId nulled).
- [ ] `manageIntegrations` permission gates the tab and resend.
- [ ] GraphQL `FormType.integrations` exposes config (no secrets); MCP can list
      integrations + recent dispatch health (no secrets).
- [ ] Unit + integration tests cover registry/dispatch/retry; smoke tests cover
      "configure webhook → submit form → payload received → log shows success → break
      endpoint → log shows failed + retry → resend".

## 7. Implementation Slices (suggested)

1. **Framework core** — `IntegrationTypeInterface`, `IntegrationTypeRegistry`,
   `IntegrationResult`, `EVENT_REGISTER_INTEGRATION_TYPES`; two migrations
   (`simpleform_integrations`, `simpleform_integration_logs`); `IntegrationsService`
   skeleton + unit tests. No UI, no dispatch yet.
2. **Async dispatch** — listen on `EVENT_AFTER_SUBMISSION_SAVE`, `SendIntegrationJob`,
   log-row lifecycle, queue retry, `dispatchIntegrationsSynchronously` setting;
   integration tests on all three submit paths.
3. **Webhook connector** — `WebhookIntegration` (URL/method/format/mapping/HMAC),
   Guzzle call, result mapping; unit tests with a mocked transport.
4. **CP UI** — per-form Integrations tab (CRUD + enable toggle + status badge),
   submission-detail Integrations panel with Resend, `manageIntegrations` permission.
5. **GraphQL + MCP exposure** — `FormType.integrations` (no secrets); MCP
   list/health tool (no secrets).
6. **Smoke tests + docs** — `IntegrationsCest`, end-user + extension-author docs,
   CHANGELOG; add `stimmt/craft-mcp` to composer `suggest` while touching composer.

> Fast-follow (separate issues, built on this framework, **not** part of v1):
> Slack/Discord connector, Mailchimp/ActiveCampaign connector, HubSpot/Pipedrive
> connector.

## 8. Risks & Mitigations

- **Third-party latency/outage degrading UX** → dispatch is queue-async by default; the
  submission is committed and the visitor responded to before any outbound call.
- **Secret leakage** → env-aware attributes, encryption at rest, and explicit exclusion
  from GraphQL/MCP/project-config output; covered by an assertion test.
- **Retry storms / duplicate sends** → bounded queue retries; jobs keyed by
  `(integrationId, submissionId)`; idempotency left to the receiver via the signed
  payload + stable submission UID.
- **Scope creep into a connector zoo** → v1 is framework + Webhook only; named connectors
  are independent slices gated behind the stable interface.
- **SSRF via user-supplied webhook URL** → document that integration config is an
  admin/trusted-editor capability (gated by `manageIntegrations`); consider an optional
  host allowlist setting as a follow-up.
