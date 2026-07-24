# Outbound Integrations

Simple Form can push every submission to external services — webhooks, and (via
the same framework) any connector you or a plugin registers. Dispatch happens
**asynchronously on Craft's queue**, so a slow or failing third party never
blocks or fails the visitor's submission.

## Configuring an integration (Control Panel)

1. Go to **Simple Form → Forms**, and click **Integrations** on the form's row.
2. Click **New integration** and choose a type (e.g. **Webhook**).
3. Fill in the type's settings and the **Name**, leave **Enabled** on, and **Save**.

Requires the **Manage form integrations** permission (nested under *Manage forms
and fields*).

## Bundled connectors

All connectors share the same async dispatch, retry, dispatch-log, and resend
framework described below — the only difference is where the submission lands.

| Connector | Type handle | What it does |
|-----------|-------------|--------------|
| **Webhook** | `webhook` | POST/PUT the submission JSON to any URL, optionally HMAC-SHA256 signed. |
| **Slack** | `slack` | Post a submission message to a Slack channel via incoming webhook. |
| **Discord** | `discord` | Post a submission message to a Discord channel via webhook. |
| **Mailchimp** | `mailchimp` | Subscribe the submitter to a Mailchimp audience. |
| **ActiveCampaign** | `activecampaign` | Create/update a contact in ActiveCampaign. |
| **HubSpot** | `hubspot` | Create/update a contact in HubSpot. |
| **Pipedrive** | `pipedrive` | Create a person/lead in Pipedrive. |
| **Google Sheets** | `google-sheets` | Append one row per submission to a worksheet ([details](#google-sheets)). |
| **Create Craft Element** | `craft-element` | Build a native Craft Entry or User from the submission ([details](#create-craft-element)). |

The Slack, Discord, Mailchimp, ActiveCampaign, HubSpot, and Pipedrive connectors
take the credentials/identifiers their service needs (a webhook URL, API key, or
token) plus a field mapping where relevant. Their secret settings are stored
encrypted and follow the same [security](#security) rules as the webhook signing
secret — env-aware, resolved only at dispatch, never exposed via GraphQL/MCP.

### Webhook settings

| Setting | Notes |
|---------|-------|
| **Webhook URL** | Target endpoint. Supports environment variables (`$MY_HOOK_URL`). |
| **HTTP method** | `POST` (default) or `PUT`. |
| **Payload format** | `JSON` (default) or form-encoded. |
| **Signing secret** | Optional. When set, the request body is HMAC-SHA256 signed (see below). Supports env vars. |

The webhook payload is:

```json
{
  "formHandle": "contact",
  "submissionId": 1234,
  "submissionUid": "…",
  "siteId": 1,
  "dateCreated": "2026-06-18T12:00:00+00:00",
  "data": { "fullName": "Ada", "emailAddress": "ada@example.test" }
}
```

`data` is keyed by **field handle**.

#### Verifying the signature

When a signing secret is configured, each request carries two headers:

- `X-SimpleForm-Timestamp: <unix-seconds>` — when the request was signed.
- `X-SimpleForm-Signature: sha256=<hex>` — HMAC-SHA256 of `<timestamp>.<rawBody>`.

Recompute the signature over `timestamp . '.' . rawBody`, compare in constant
time, and reject a stale timestamp to defend against replay:

```php
$timestamp = $request->getHeaderLine('X-SimpleForm-Timestamp');

// Reject anything older than your freshness window (e.g. 5 minutes).
if (abs(time() - (int) $timestamp) > 300) {
    // stale — drop it
}

$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
hash_equals($expected, $request->getHeaderLine('X-SimpleForm-Signature'));
```

### Google Sheets

Appends **one row per submission** to a configured worksheet via the Sheets v4
API (`spreadsheets.values.append`). Rows are written with `valueInputOption=RAW`,
so a submitted value can never inject a formula.

| Setting | Notes |
|---------|-------|
| **Authentication** | `Service account (JSON key)` or `OAuth2 (refresh token)`. |
| **Service-account JSON key** | The JSON key (or an env reference). Share the spreadsheet with the key's `client_email`. Required in service-account mode. |
| **OAuth client ID / secret / refresh token** | Long-lived OAuth credentials for a connected Google account. The refresh token is required in OAuth mode. |
| **Spreadsheet** | The spreadsheet ID, or a full `…/spreadsheets/d/<ID>/edit` URL (the ID is extracted). |
| **Worksheet** | The tab name to append to (e.g. `Sheet1`). Blank = first tab. |
| **Field → column mapping** | An ordered table mapping each submission field handle to a column header. **Row order is the column order.** At least one row is required. |
| **Write a header row** | On the first append to an *empty* sheet, write the column headers as the first row. |

The mapping also accepts a few **synthetic columns**: `sf:submissionDate`,
`sf:formName`, and `sf:submissionId`. File fields export as their stored URL;
multi-value fields are comma-joined.

All secrets (service-account key, client secret, refresh token) are **stored
encrypted**, env-aware, resolved only at dispatch, and **never** echoed back in
the CP or exposed via GraphQL/MCP. A 401 mid-dispatch refreshes the access token
once and retries.

> v1 scope: append only — no read-back, row update/delete, or worksheet creation.

### Create Craft Element

Builds a native Craft **Entry** or **User** from a submission. Unlike the external
connectors there is no HTTP call — the element is saved through Craft's element
API — but it still runs through the same async dispatch, retry, and dispatch-log
framework, so a validation failure surfaces as a retryable failed log row while
the submission itself stays saved.

| Setting | Notes |
|---------|-------|
| **Element type** | `Entry` or `User`. |
| **Section / Entry type** | (Entry) where the entry is created. |
| **Title template** | (Entry) a Twig template rendered against the submission values; wins over a mapped `title`. |
| **Author** | (Entry) the submitting user (when logged in) or a fixed author. |
| **Entry status** | (Entry) `live`, `pending`, or `disabled`. |
| **User group** | (User) the group the new user is assigned to. |
| **User status** | (User) `active`, `pending`, or `suspended`. |
| **Field mapping** | Maps each submission handle onto a native attribute (`title`/`slug` for entries; `email`/`username`/`fullName` for users) **or** a custom field handle on the target. |

The entry is created on the submission's site (the section's propagation settings
then govern fan-out). The created element is **linked back** to the submission, so
a **Resend** is idempotent — it links the existing element instead of creating a
duplicate.

## How dispatch works

- On a successful submission, one queue job is pushed **per enabled integration**.
- Each attempt is recorded in a dispatch log (status, response code, attempt #).
- Failed attempts **retry** via the queue (up to 3 attempts total).
- The submission detail screen shows each integration's dispatch history with a
  **Resend** button that re-queues a dispatch.
- For local debugging, the **Dispatch integrations synchronously** setting runs
  dispatch inline during the request instead of on the queue.

## Reading integrations (GraphQL & MCP)

Integration **configuration is never exposed with its settings/secrets.** Only
name, type, and enabled state are readable:

- **GraphQL** — `SimpleForm.integrations { name type enabled }`.
- **MCP** — the `list_integrations` tool (scope `forms:manage`) returns each
  integration's name/type/enabled plus dispatch **health** (attempt counts and
  last status), never its settings.

## Security

- Secret settings are **env-aware** and resolved only at dispatch time; they are
  never rendered in exported project config, GraphQL, or MCP output.
- Configuring integrations is an admin/trusted-editor capability
  (`manageIntegrations`). Treat the webhook URL as trusted input.

## Writing a custom connector

Implement `IntegrationTypeInterface` and register the class on
`Plugin::EVENT_REGISTER_INTEGRATION_TYPES`:

```php
use anvildev\simpleform\Plugin;
use anvildev\simpleform\events\RegisterIntegrationTypesEvent;
use anvildev\simpleform\integrations\IntegrationTypeInterface;
use anvildev\simpleform\integrations\IntegrationResult;
use anvildev\simpleform\elements\Submission;
use yii\base\Event;

class MyConnector implements IntegrationTypeInterface
{
    public static function handle(): string { return 'my-connector'; }
    public static function displayName(): string { return 'My Connector'; }

    public function settingsHtml(array $settings): string { /* Cp::*FieldHtml(...) */ return ''; }
    public function defineSettingsRules(): array { return [[['apiKey'], 'required']]; }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        // $settings are already env-parsed. Do the outbound call, then:
        return IntegrationResult::success(200, 'ok');
        // or IntegrationResult::failure($code, $message) to trigger a retry.
    }
}

Event::on(
    Plugin::class,
    Plugin::EVENT_REGISTER_INTEGRATION_TYPES,
    fn(RegisterIntegrationTypesEvent $e) => $e->types[] = MyConnector::class,
);
```

Settings inputs are rendered inside a `settings` namespace, so name your fields
plainly (`apiKey`) — they arrive back as `settings.apiKey`. Returning a
`failure()` (or throwing) marks the attempt failed and lets the queue retry.
