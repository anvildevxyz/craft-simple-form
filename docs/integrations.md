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

When a signing secret is configured, the request carries an
`X-SimpleForm-Signature: sha256=<hex>` header. Recompute it over the raw body and
compare:

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
hash_equals($expected, $request->getHeaderLine('X-SimpleForm-Signature'));
```

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
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\events\RegisterIntegrationTypesEvent;
use fabianhaef\simpleform\integrations\IntegrationTypeInterface;
use fabianhaef\simpleform\integrations\IntegrationResult;
use fabianhaef\simpleform\elements\Submission;
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
