# PRD — Integration: Google Sheets

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#141](https://github.com/fabianhaef/craft-simple-form/issues/141)

---

## 1. Problem Statement

Simple Form ships outbound connectors for Webhook, Slack, Discord, Mailchimp,
ActiveCampaign, HubSpot, and Pipedrive (`src/integrations`). The single most-requested
"no-code ops" destination for form submissions is missing: **Google Sheets**. Non-technical
form owners live in spreadsheets — they want every submission to land as a new row they can
sort, filter, pivot, and share, without standing up a database view or learning the CP
exporter.

This PRD adds a Google Sheets integration connector that appends one row per submission to a
configured spreadsheet/worksheet, with a field→column mapping UI, OAuth2 or service-account
auth, and the same async-dispatch / retry / dispatch-log guarantees every other connector
already gets from `SendIntegrationJob` and `IntegrationsService`.

## 2. Goals

- A new `GoogleSheetsIntegration` implementing `IntegrationTypeInterface`, registered via the
  existing `RegisterIntegrationTypesEvent` / `IntegrationTypeRegistry` — **no change to the
  submission path**.
- Two auth modes: **service account** (JSON key, simplest for a single org-owned sheet) and
  **OAuth2** (user grants access to their own Drive). Tokens/keys stored encrypted, never
  exposed via GraphQL or MCP.
- A `settingsHtml()` config UI: pick spreadsheet + worksheet, map each form field to a
  column, choose whether to auto-create a header row.
- A `send()` that appends the mapped submission as a row via the Sheets API
  (`spreadsheets.values.append`).
- Full reuse of the existing async dispatch: enqueued off `EVENT_AFTER_SUBMISSION_SAVE`,
  retried via `SendIntegrationJob::canRetry()`, every attempt recorded as a dispatch-log row
  with a secret-scrubbed message, resendable from the CP.

## 3. Non-Goals (v1)

- No two-way sync / reading rows back, no updating/deleting existing rows.
- No formula/formatting injection, no per-column data-type coercion beyond stringification.
- No automatic worksheet creation per form (owner selects an existing worksheet).
- No bulk backfill of historical submissions into a sheet (resend covers one-offs).
- No Google Sheets-hosted file uploads — file fields export as the asset URL string.

## 4. Users & Use Cases

- **Marketer**: connects the team's shared "Leads 2026" sheet; every contact-form submission
  appears as a row their sales team already watches.
- **Event organiser**: maps an RSVP form to a sheet, sorts by date, shares a read-only link.
- **Developer**: uses a service-account key (env-referenced) so the integration survives the
  developer leaving and never depends on a personal Google login.
- **Reviewer**: a row failed to append (sheet renamed) → sees the failed dispatch-log row,
  fixes the worksheet name, hits **Resend**.

## 5. Proposed Solution

### 5.1 New abstract + connector

Google's auth model differs enough from the marketing/CRM connectors that a dedicated base is
warranted, mirroring `AbstractMarketingIntegration` / `AbstractCrmIntegration`:

- `AbstractGoogleIntegration` — holds the OAuth2 / service-account token plumbing
  (access-token fetch + refresh, JWT signing for service accounts) so a future Google Drive /
  Calendar connector can reuse it. Uses Guzzle via the existing `ApiConnector` client seam.
- `GoogleSheetsIntegration extends AbstractGoogleIntegration implements IntegrationTypeInterface`:
  - `handle(): 'google-sheets'`
  - `displayName(): 'Google Sheets'`
  - `settingsHtml(array $settings): string`
  - `defineSettingsRules(): array`
  - `send(Submission $submission, array $settings): IntegrationResult`

Registered in `Plugin` alongside the others via `EVENT_REGISTER_INTEGRATION_TYPES`.

### 5.2 Auth + token storage

Two `authMode` options in settings: `service_account` | `oauth`.

- **Service account**: owner pastes the JSON key (or, preferred, an env reference to it).
  At `send()` time we mint a short-lived OAuth2 bearer by signing a JWT with the key's private
  key and exchanging it at the token endpoint; the access token is cached (per the key's
  `client_email`) until expiry. The sheet must be shared with the service account's email.
- **OAuth2**: a "Connect Google account" button in `settingsHtml` kicks off a standard
  authorization-code flow against a controller action
  (`integrations/google/oauth-callback`); the returned refresh token is stored on the
  integration. Access tokens are refreshed on demand.

**Secret handling**: the JSON key and OAuth refresh token are stored in the integration's
settings blob, which is already encrypted at rest (migration
`m260620_000001_encrypt_integration_secrets`). They must be **excluded** from any GraphQL type
and MCP tool output — the integration settings are never serialised to those surfaces; a
review confirms the secret keys are filtered (same posture as existing API keys).

### 5.3 Spreadsheet / worksheet + mapping UI (`settingsHtml`)

After auth is established, `settingsHtml` renders:

1. **Spreadsheet** — text field for the spreadsheet ID (or full URL, from which we extract the
   ID). Optional "Test connection" button validates access and lists worksheet tabs.
2. **Worksheet** — dropdown of tab names fetched from `spreadsheets.get`, or a free-text tab
   name fallback when the live fetch is unavailable.
3. **Field → column mapping** — reuse the established mapping pattern from
   `ApiConnector::mappedFields()` (`mappingKey` → `[handle => target]`). Targets are column
   headers (e.g. "Email", "Message"). Include synthetic fields: submission date, source IP
   (if recorded), form name.
4. **Header row** — checkbox "Write a header row on first append if the sheet is empty."

`defineSettingsRules()` requires the auth credential for the chosen mode, the spreadsheet ID,
and a non-empty mapping; rejects malformed JSON keys.

### 5.4 `send()` implementation

```php
public function send(Submission $submission, array $settings): IntegrationResult
{
    $values = $this->mappedFields($submission, $settings, 'columnMapping'); // header => value
    $row = $this->orderRow($values, $settings['columns']);                  // stable column order
    $token = $this->accessToken($settings);                                 // mint/refresh
    $response = $this->httpClient()->post(
        "https://sheets.googleapis.com/v4/spreadsheets/{$id}/values/{$range}:append",
        [ 'query' => ['valueInputOption' => 'RAW'], 'headers' => ['Authorization' => "Bearer {$token}"],
          'json' => ['values' => [$row]] ]
    );
    return $this->resultFromResponse($response); // reuses ApiConnector mapping
}
```

- Non-2xx → `IntegrationResult::failure()` with the (scrubbed) status + message, which makes
  `SendIntegrationJob` throw and the queue retry (up to 3 attempts).
- 401 with an expired token → refresh once and retry inline before failing.
- Rate-limit / 429 → fail with the response code so the queue backs off and retries.
- File fields → asset URL string; arrays (checkboxes) → comma-joined.

### 5.5 Error handling & logging

All outcomes flow through the existing dispatch-log mechanism (`IntegrationsService::runOnce`
→ log row). The generic queue-facing exception stays opaque (F16/CWE-209) while the detailed,
secret-scrubbed reason goes to the log row — identical to the current connectors. No Google
response body or token ever surfaces in the queue UI.

## 6. Acceptance Criteria

- [ ] `GoogleSheetsIntegration` appears in the CP "Add integration" type picker.
- [ ] Service-account auth: pasting/env-referencing a valid JSON key mints an access token and
      a "Test connection" succeeds; the JSON key is stored encrypted.
- [ ] OAuth2 auth: "Connect Google account" completes the code flow and stores a refresh token
      encrypted.
- [ ] Worksheet dropdown lists the spreadsheet's tabs; free-text fallback works when the live
      fetch fails.
- [ ] Field→column mapping persists and drives row order; synthetic fields (date, form name)
      are mappable.
- [ ] On submission, a correctly-ordered row is appended to the configured worksheet, async
      via `SendIntegrationJob`.
- [ ] "Write header row" appends a header on first write to an empty sheet only.
- [ ] A failed append (renamed worksheet, revoked access) produces a failed dispatch-log row
      with a scrubbed message and is retried, then resendable from the CP.
- [ ] An expired access token is refreshed and the append retried without surfacing an error.
- [ ] Neither the JSON key, OAuth tokens, nor any Google response body appear in GraphQL types,
      MCP tool output, or the queue exception message.
- [ ] PHPStan L7 + ECS clean; `Craft::t('simple-form', …)` for all UI strings.

## 7. Testing

### Unit
- `mappedFields()` → ordered row, including synthetic fields and array/file coercion.
- Service-account JWT assembly + token-exchange request shape (mock HTTP client).
- OAuth refresh-token → access-token exchange (mock).
- `send()` success → `IntegrationResult::success` with response code; non-2xx →
  `failure`; 401 → refresh-then-retry path.
- Secret-scrubbing: failure message never contains the bearer token or key.
- `defineSettingsRules()`: missing credential / spreadsheet ID / mapping rejected; malformed
  JSON key rejected.

### craft-smoke-test scenarios
1. Add a Google Sheets integration with a (test) service-account key, configure spreadsheet +
   worksheet + a two-column mapping; verify the config saves and the key is not echoed back in
   plaintext.
2. Submit the form; verify a `SendIntegrationJob` is enqueued and a successful dispatch-log row
   is recorded (HTTP mocked at the boundary in CI).
3. Configure a worksheet name that doesn't exist; submit; verify a *failed* dispatch-log row
   with a generic queue exception, then hit **Resend** after fixing the name and verify success.
4. Inspect the integration via the GraphQL schema and (if enabled) MCP tools; verify no
   credential fields are exposed.
5. Submit via the GraphQL `submitForm` mutation; verify the same async append occurs.

## 8. Open Questions

- Ship our own Google Cloud OAuth client (managed redirect) or require the owner to register
  their own OAuth client and paste client ID/secret? Self-registered is more secure/portable
  for a distributed plugin but adds setup friction — leaning self-registered, documented.
- Do we pull in `google/apiclient` (heavy) or hand-roll the JWT + 3 REST calls over Guzzle?
  Leaning hand-rolled to keep the dependency footprint small (consistent with "simple").
- `valueInputOption`: `RAW` (safe, no formula injection) vs. `USER_ENTERED` (dates/numbers
  parsed)? Defaulting to `RAW` for safety; expose as an advanced toggle?
- Should column order be locked at config time, or re-derived from the live header row on each
  send (resilient to manual column reordering in the sheet)? Leaning config-time for v1.
