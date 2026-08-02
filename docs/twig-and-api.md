# Twig, PHP, GraphQL & MCP API (Developer Integration)

Simple Form is built to be embedded and automated, not just clicked through the
control panel. The same form definition is reachable from Twig templates, plain
PHP, a headless GraphQL schema, and a token-authenticated MCP server, and the
submission lifecycle is open through a small set of events.

This guide is the developer's map of those entry points. For restyling the
front-end markup (overridable Twig partials, the render context, class hooks)
and the [front-end JavaScript events](render-templates.md#front-end-javascript-events)
see [Custom Render Templates](render-templates.md) — this page focuses on the
*programmatic* (server-side) surface.

Every name and signature below is taken from the shipped source; nothing here is
aspirational.

## Twig API

Two complementary front-ends are registered:

- the global `simpleForm()` Twig **function** (rendering only), and
- the `craft.simpleForm.*` **variable** (rendering + element queries + editing).

### `simpleForm(handle, options)`

The shorthand for "render this whole form here":

```twig
{{ simpleForm('contact') }}
{{ simpleForm('contact', { class: 'my-form', submitText: 'Send' }) }}
```

Output is HTML-safe (the function is registered with `is_safe: ['html']`). It
delegates to the same render service as `craft.simpleForm.render()`, so the
options are identical: `submitText`, `class`, `id`, `attributes` (extra `<form>`
attributes) and `theme` (override the resolved template path for this one
render; an empty string forces the built-in default partials).

### `craft.simpleForm.*`

The variable adds element lookups, render granularity and front-end editing.

#### Looking up forms

```twig
{# A single Form element by handle or id (null if not found). #}
{% set form = craft.simpleForm.form('contact') %}
{{ form.title }} — {{ form.description }}

{# A FormQuery you can configure and iterate. #}
{% for form in craft.simpleForm.forms({ limit: 5 }).all() %}
  <a href="#{{ form.handle }}">{{ form.title }}</a>
{% endfor %}
```

| Method | Returns | Notes |
| --- | --- | --- |
| `form(handleOrId)` | `Form\|null` | Numeric arg → id lookup, otherwise handle. |
| `forms(criteria = {})` | `FormQuery` | `criteria` is applied with `Craft::configure`. |
| `submissions(criteria = {})` | `SubmissionQuery` | Same pattern for submissions. |

`submissions()` gives your own (trusted) templates query access to stored
submissions — e.g. a members-only "your submissions" page:

```twig
{% set mine = craft.simpleForm.submissions({
    formId: form.id,
    userId: currentUser.id,
    limit: 10,
}).all() %}
```

This is a site-template convenience with full read access — GraphQL
deliberately has no equivalent (see [GraphQL](#graphql)).

#### Edition helpers

```twig
{% if craft.simpleForm.isStandard() %} … {% endif %}
```

| Method | Returns | Notes |
| --- | --- | --- |
| `edition()` | `string` | The active edition handle: `solo` or `pro`. |
| `isStandard()` | `bool` | Whether the active edition is Standard. |
| `can(capability)` | `bool` | Whether the active edition may use a capability, e.g. `craft.simpleForm.can('conditionalLogic')`. Handles: `conditionalLogic`, `multiPage`, `saveAndContinue`, `conversational`, `quiz`, `partialCapture`, `pdf`, `governance`, `devTools` — see [Editions](editions.md). |

#### Rendering

```twig
{# Whole form (same output as simpleForm()). Returns Markup. #}
{{ craft.simpleForm.render('contact', { class: 'my-form' }) }}

{# Hand-authored single-step layout — the plugin still emits the plumbing. #}
{{ craft.simpleForm.formStart('contact') }}
    {{ craft.simpleForm.field('contact', 'name') }}
    {{ craft.simpleForm.field('contact', 'email') }}
{{ craft.simpleForm.formEnd('contact') }}
```

| Method | Emits |
| --- | --- |
| `render(handle, options = {})` | The full form via the resolved theme. |
| `formStart(handle, options = {})` | Opening `<form>` + CSRF + honeypot + hidden `formHandle`. |
| `formEnd(handle, options = {})` | Captcha + submit button + assets + `</form>`. |
| `field(handle, fieldHandle, options = {})` | One field group via the `field` partial, keeping its required/conditional `data-*` attributes. |

`formStart` / `formEnd` are **single-step only**; for multi-step forms use the
whole-form `render()`, which drives the step navigation. The security-sensitive
values (CSRF input, honeypot, captcha) are pre-rendered `Markup` built inside the
render service — a theme places them but can never rebuild them. See
[render-templates.md](render-templates.md) for the full render context, the
field-row shape, class hooks and asset handling.

#### Front-end editing

For "edit your submission" links (e.g. in an autoresponder), a submission can be
re-rendered as a pre-filled, editable form:

```twig
{# Editable, pre-filled copy of a submission's form. Anonymous callers pass the
   token from the tokenized link; a logged-in owner needs none. #}
{{ craft.simpleForm.editForm(submission, { token: craft.app.request.getParam('t') }) }}

{# A secure, tokenized edit URL for that submission. #}
{% set url = craft.simpleForm.editUrl(submission) %}
```

| Method | Returns | Notes |
| --- | --- | --- |
| `editForm(submission, options = {})` | `Markup` | `submission` may be a `Submission` or its id. `options.token` drives the anonymous path; `submitText` overrides the button. Renders an HTML comment when the submission/edit is unavailable. |
| `editUrl(submission, path = null)` | `string\|null` | Issues (or rotates) the submission's edit token and embeds `id` + `t` on the edit page (`path`, else the `editPath` setting). Returns `null` when the form does not allow editing or no edit path is configured. |

Authorization (allowEditing + edit window + token/owner) is always re-checked
server-side on submit — these helpers only render the UI.

## PHP API

The plugin ships a [`.phpstorm.meta.php`](../.phpstorm.meta.php) so
`Craft::$app->getPlugins()->getPlugin('simple-form')` resolves to the concrete
`Plugin` in PhpStorm — its `getSubmissionService()`, `getFormStructure()` and the
other service accessors then autocomplete with full return types.

### Loading a form and its fields

`Form` is a standard Craft element, so the element query is the entry point:

```php
use anvildev\simpleform\elements\Form;

$form = Form::find()->handle('contact')->one();
```

The form's title, description and field labels are **per-site** content, so query
on the site you want resolved (the Twig and GraphQL layers default to the current
/ primary site for you). The resolved field set for a given form + site is built
by the form-structure service:

```php
use anvildev\simpleform\Plugin;

$siteId = Craft::$app->getSites()->getCurrentSite()->id;
$fields = Plugin::getInstance()->getFormStructure()->getFieldSet($form->id, $siteId);
```

### Submitting and editing programmatically

`SubmissionService` is the shared save core — the Twig submit controller, the
GraphQL mutations and the MCP tools all route through it, so validation, spam
protection, conditional logic, the lifecycle events and the notification email
behave identically no matter the caller:

```php
use anvildev\simpleform\Plugin;

$service = Plugin::getInstance()->getSubmissionService();

$result = $service->submit($form, $values, [
    'honeypot'     => '',          // non-empty = silently dropped as spam
    'captchaToken' => $token,      // verified when captcha is enabled
    'skipCaptcha'  => false,
    'siteId'       => $siteId,
    'userId'       => $userId,     // or null for a guest
]);
// $result = ['submission' => Submission|null, 'errors' => array|null, 'data' => array]
```

The genuinely public entry points:

| Method | Purpose |
| --- | --- |
| `submit(Form $form, array $values, array $context = [])` | Validate + save a new submission (`$values` keyed by field id). |
| `update(Submission $submission, array $values, array $context = [])` | Re-validate + save an edit through the same core. |
| `authorizeEdit(Submission $submission, ?string $token, ?int $currentUserId)` | Resolve the edit actor (token or owner) or `null` if unauthorized. |
| `resolvePostSubmit(Form $form, Submission $submission, array $data)` | The per-form post-submit behaviour (e.g. `redirectUrl`). |
| `getSubmission(int $submissionId)` | Load a submission by id. |
| `updateStatus(int $submissionId, string $status)` | Change a submission's status. |
| `userHasReachedLimit(Form $form, ?int $userId)` | Per-user submission cap check. |
| `isRateLimited(?string $ip)` | The shared per-IP abuse throttle. |

`createFromRequest()` exists for the controller path (it reads the current
request); prefer `submit()` for your own integrations.

## GraphQL

The schema exposes form *definitions* and a submit/update path. Submission data
is deliberately **not** queryable — there is no submissions query and no
submission object type, so a read token can never read what people submitted.

A committed SDL of the Simple Form types lives at
[`docs/reference/schema.graphql`](reference/schema.graphql) so a headless client
has the schema without booting Craft (the running schema is authoritative;
regenerate with `php craft graphql/print-schema --full-schema=1`).

### Schema components (scopes)

| Component | Gates |
| --- | --- |
| `simpleForms:read` | The `simpleForm` / `simpleForms` queries. |
| `simpleFormSubmissions:create` | The `submitForm` mutation. |
| `simpleFormSubmissions:edit` | The `updateSubmission` mutation. |

### Queries

```graphql
query {
  simpleForm(handle: "contact", siteId: 1) {
    id
    handle
    name
    title
    description
    siteId
    fields { id }            # the form's fields, in display order
    integrations { }         # name / type / enabled only — never settings or secrets
  }
  simpleForms(siteId: 1) { handle title }
}
```

`siteId` defaults to the primary site. The form type is `SimpleForm`; fields are
`SimpleFormField`.

### Mutations

A form's fields are dynamic, so values are posted as a fixed-shape list of
`SimpleFormFieldValueInput` — one entry per field, identified by its `fieldId`
(the same id surfaced as `field_<id>` in the rendered markup):

```graphql
mutation {
  submitForm(
    handle: "contact"
    values: [
      { fieldId: 12, value: "Ada" }
      { fieldId: 13, value: "ada@example.com" }
      { fieldId: 14, values: ["news", "events"] }   # checkbox-style: takes precedence over `value`
    ]
    honeypot: ""              # leave empty; non-empty is silently dropped
    captchaToken: "…"         # required when captcha is on (see bypass below)
  ) {
    success
    submissionId
    redirectUrl
    message                   # resolved confirmation text; matches the AJAX response
    quizScore                 # quiz results — all null when the form isn't a quiz
    quizMaxScore
    quizPercentage            # 0–100
    quizGrade                 # grade-band label, or null without bands
    errors { key messages }
  }
}
```

On a [quiz-mode](quiz-and-surveys.md) form the payload carries the submission's
score, so a headless client can show the result immediately.

> **File fields can't be submitted over GraphQL.** `SimpleFormFieldValueInput`
> accepts only `String` / `[String]` values — there is no upload scalar and no
> multipart handling in the mutation. Submit forms with File Upload (or
> Signature) fields through the rendered front end or a plain HTTP POST to the
> submit endpoint, which accept multipart uploads.

`updateSubmission` edits an existing submission through the same save core,
authorized by a valid edit `token` **or** an authenticated owner:

```graphql
mutation {
  updateSubmission(id: 42, token: "…", values: [{ fieldId: 12, value: "Ada L." }]) {
    success
    submissionId
    errors { key messages }
  }
}
```

Both mutations report invalid input via the `errors` list rather than a hard
failure, and never leak the edit token or captcha secret in the payload. Spam
handling is enforced server-side: the honeypot is always honoured, captcha is
required by default, and a per-IP throttle is shared with the front-end path. The
captcha requirement is only waived for trusted server-to-server callers when the
operator sets `allowGraphqlCaptchaBypass`.

## Events

### Submission lifecycle

`SubmissionEvent` is fired around every save (front-end, GraphQL or MCP):

```php
use anvildev\simpleform\Plugin;
use anvildev\simpleform\events\SubmissionEvent;
use yii\base\Event;

Event::on(
    Plugin::class,
    Plugin::EVENT_AFTER_SUBMISSION_SAVE,
    function(SubmissionEvent $e): void {
        $e->submission;   // the Submission element
        $e->form;         // the Form element
        $e->data;         // the saved field data (array|null)
        $e->isNew;        // true for a create, false for an edit
    }
);
```

| Constant | When |
| --- | --- |
| `Plugin::EVENT_BEFORE_SUBMISSION_SAVE` | Before the submission is persisted. |
| `Plugin::EVENT_AFTER_SUBMISSION_SAVE` | After it is persisted (the plugin's own outbound integrations dispatch off this). |

### Workflow & partial-capture events

Two follow-up hooks fire on `Plugin::class` for features where the plugin
deliberately sends nothing itself:

```php
use anvildev\simpleform\Plugin;
use anvildev\simpleform\events\WorkflowTransitionEvent;
use anvildev\simpleform\events\PartialCaptureEvent;
use yii\base\Event;

// After a submission moves between workflow stages — e.g. notify the assignee
// or dispatch an integration on "approved".
Event::on(Plugin::class, Plugin::EVENT_SUBMISSION_TRANSITIONED, function(WorkflowTransitionEvent $e): void {
    $e->submission;  // the Submission element
    $e->from;        // previous stage handle (string|null)
    $e->to;          // new stage handle (string)
    $e->user;        // the acting User, or null for a programmatic move
});

// After a passive partial is captured — build your own abandonment follow-up
// (a CRM ping, a "you left something behind" email); the plugin sends nothing.
Event::on(Plugin::class, Plugin::EVENT_PARTIAL_CAPTURED, function(PartialCaptureEvent $e): void {
    $e->form;    // the Form element
    $e->values;  // the field_<id> => value map captured so far
    $e->siteId;  // the capture site
    $e->token;   // the partial's plaintext token (only its hash is persisted)
});
```

| Constant | When | Guide |
| --- | --- | --- |
| `Plugin::EVENT_SUBMISSION_TRANSITIONED` | After a submission moves between workflow stages (not for the automatic initial-stage placement). | [Submissions](submissions.md#submission-approval-workflow) |
| `Plugin::EVENT_PARTIAL_CAPTURED` | After a [passive partial](building-forms.md#passive-partial-capture-abandoned-attempts) is stored. | [Building forms](building-forms.md#passive-partial-capture-abandoned-attempts) |

### Lifecycle seam events (modify / cancel)

Five seam events let you reach into rendering, validation, notification and
dispatch without forking the plugin. Each is fired on `Plugin::class` and — for
performance — only fires when a handler is attached, so the default fast paths
(including the field-set cache) are untouched.

```php
use anvildev\simpleform\Plugin;
use anvildev\simpleform\events\DefineFieldSetEvent;
use anvildev\simpleform\events\ModifyRenderContextEvent;
use anvildev\simpleform\events\BeforeValidateSubmissionEvent;
use anvildev\simpleform\events\BeforeSendNotificationEvent;
use anvildev\simpleform\events\BeforeIntegrationDispatchEvent;
use yii\base\Event;

// Add / remove / reorder a form's resolved fields for a site.
Event::on(Plugin::class, Plugin::EVENT_DEFINE_FIELD_SET, function(DefineFieldSetEvent $e): void {
    $e->fields = array_filter($e->fields, fn($row) => $row['name'] !== 'internal');
});

// Add to or rewrite the Twig render context.
Event::on(Plugin::class, Plugin::EVENT_MODIFY_RENDER_CONTEXT, function(ModifyRenderContextEvent $e): void {
    $e->context['brand'] = 'acme';
});

// Normalize or augment submitted values before validation/storage.
Event::on(Plugin::class, Plugin::EVENT_BEFORE_VALIDATE, function(BeforeValidateSubmissionEvent $e): void {
    if (isset($e->valuesByHandle['email'])) {
        $e->valuesByHandle['email'] = strtolower(trim($e->valuesByHandle['email']));
    }
});

// Rewrite recipients or suppress a notification (covers the emailTo path too,
// where $e->notification is null).
Event::on(Plugin::class, Plugin::EVENT_BEFORE_SEND_NOTIFICATION, function(BeforeSendNotificationEvent $e): void {
    if (($e->submissionData['field_12'] ?? '') === 'internal') {
        $e->send = false;
    }
});

// Adjust settings or skip a single outbound dispatch (a skip is a successful no-op).
Event::on(Plugin::class, Plugin::EVENT_BEFORE_INTEGRATION_DISPATCH, function(BeforeIntegrationDispatchEvent $e): void {
    if ($e->submission->getStatus() === 'spam') {
        $e->send = false;
    }
});
```

| Event | Fired from | Mutate | Cancel |
| --- | --- | --- | --- |
| `EVENT_DEFINE_FIELD_SET` | `FormStructureService::getFieldSet()` | `$e->fields` | — |
| `EVENT_MODIFY_RENDER_CONTEXT` | `FormRenderService::buildContext()` | `$e->context` | — |
| `EVENT_BEFORE_VALIDATE` | `SubmissionService` (every channel, create + edit) | `$e->valuesByHandle` | — |
| `EVENT_BEFORE_SEND_NOTIFICATION` | `EmailService` (per notification) | `$e->recipients` | `$e->send = false` |
| `EVENT_BEFORE_INTEGRATION_DISPATCH` | `IntegrationsService::runOnce()` | `$e->settings` | `$e->send = false` |

### Register events (extending the plugin)

Four register events let third parties extend the plugin. All are fired on
`Plugin::class`:

```php
use anvildev\simpleform\Plugin;
use anvildev\simpleform\events\RegisterFieldTypesEvent;
use anvildev\simpleform\events\RegisterIntegrationTypesEvent;
use anvildev\simpleform\events\RegisterCaptchaProvidersEvent;
use anvildev\simpleform\events\RegisterStencilsEvent;
use anvildev\simpleform\stencils\Stencil;
use yii\base\Event;

// Add a custom field type.
Event::on(
    Plugin::class,
    Plugin::EVENT_REGISTER_FIELD_TYPES,
    fn(RegisterFieldTypesEvent $e) => $e->types[] = MyFieldType::class,
);

// Add an outbound-integration connector.
Event::on(
    Plugin::class,
    Plugin::EVENT_REGISTER_INTEGRATION_TYPES,
    fn(RegisterIntegrationTypesEvent $e) => $e->types[] = MyConnector::class,
);

// Add a captcha provider.
Event::on(
    Plugin::class,
    Plugin::EVENT_REGISTER_CAPTCHA_PROVIDERS,
    fn(RegisterCaptchaProvidersEvent $e) => $e->providers[] = MyCaptchaProvider::class,
);

// Contribute a form stencil (a pre-built form template).
Event::on(
    Plugin::class,
    Plugin::EVENT_REGISTER_STENCILS,
    fn(RegisterStencilsEvent $e) => $e->stencils[] = new Stencil([
        'handle' => 'feedback',
        'name'   => 'Feedback',
        'fields' => [/* … */],
    ]),
);
```

| Event | Add to | Implement |
| --- | --- | --- |
| `EVENT_REGISTER_FIELD_TYPES` | `$e->types[]` | `fields\FieldType` (subclass) |
| `EVENT_REGISTER_INTEGRATION_TYPES` | `$e->types[]` | `integrations\IntegrationTypeInterface` |
| `EVENT_REGISTER_CAPTCHA_PROVIDERS` | `$e->providers[]` | `captcha\CaptchaProviderInterface` |
| `EVENT_REGISTER_STENCILS` | `$e->stencils[]` | a `stencils\Stencil` instance |

Calling `Plugin::getInstance()->getFieldTypeRegistry()->registerFieldType(MyField::class)`
from your own `init()` still works and is equivalent; `EVENT_REGISTER_FIELD_TYPES`
is the recommended, uniform entry point.

## MCP server (Model Context Protocol)

> **Standard edition.** The MCP server requires [Standard](editions.md). See [Editions](editions.md).


Simple Form ships a transport-agnostic MCP server so an AI agent can manage forms
and analyse submissions over JSON-RPC 2.0. It is exposed at `simple-form/mcp`.

**Off by default.** The endpoint returns 404 unless the `enableMcp` setting is
turned on, and every request must carry a bearer token. Tokens live in their own
`simpleform_mcp_tokens` table — not in plugin settings or project config, so the
keyed hashes never sync into git or across environments — and are stored
hash-only: the plaintext secret is shown once at creation and never persisted.
A token can be given an optional **expiry** (in days) when it is created; an
expired token is rejected at authentication. No expiry means it never expires.
Creating or revoking a token requires an admin with an elevated session.

**Request/response only — no SSE streaming.** The endpoint implements the
POST (request → JSON response) half of MCP's *Streamable HTTP* transport.
Server-initiated messages over a GET/SSE stream are **intentionally not
implemented**; every shipped tool is a bounded request/response call, so
clients should not open a stream connection.

### Scopes

Authorization is deny-by-default: every tool declares the single scope it
requires, and a token only runs a tool when its scope set contains that scope.

| Scope | Grants |
| --- | --- |
| `forms:manage` | Read + create/update/delete forms and their fields. Never submission data. |
| `submissions:read` | Query / view / stats over stored submissions. |
| `submissions:export` | Bulk export of submissions (distinct from `read`). |

Submission scopes are deliberately separate from `forms:manage`, so a
forms-only integration can never read or export submissions.

### Tools

17 tools, each gated by the single scope in its section. Destructive tools
require an explicit confirmation argument.

**Form management** — scope `forms:manage`:

| Tool | Does |
| --- | --- |
| `list_forms` | List the forms in this install (id, handle, name, field count). |
| `get_form` | Full definition of one form (metadata + resolved fields) by id or handle. Never submission data. |
| `create_form` | Create a form, running the same validation and events as the CP. |
| `update_form` | Update a form's metadata (name, recipient, messages, …). |
| `delete_form` | Delete a form and all its fields across every site. **Destructive.** |
| `add_field` | Add a field to a form (same validation + multi-site behaviour as the builder). |
| `update_field` | Update a field's label, handle, required flag, help text, or config. |
| `reorder_fields` | Reorder a form's fields. |
| `delete_field` | Delete a single field from a form. **Destructive.** |
| `list_integrations` | List a form's outbound integrations (name / type / enabled + recent dispatch status). **Never** secrets or credentials. |

**Submissions** — scope `submissions:read` (or `submissions:export` for export):

| Tool | Scope | Does |
| --- | --- | --- |
| `query_submissions` | `submissions:read` | Query submissions with filters: form, status, date range, field-value match. |
| `get_submission` | `submissions:read` | Full detail of one submission by id, including its field values. |
| `submission_stats` | `submissions:read` | Aggregate counts: total, per-status, per-form, and over time. |
| `export_submissions` | `submissions:export` | Bulk export of matching submissions (same filters as `query_submissions`). |

**AI insight** — scope `submissions:read` (these return raw material for the
calling model to reason over; they don't call any LLM themselves):

| Tool | Does |
| --- | --- |
| `summarize_submissions` | Return the free-text corpus of matching submissions for the client to summarize. |
| `categorize_submissions` | Group matching submissions so the client can categorize them. |
| `detect_spam_patterns` | Flag likely-spam submissions using explainable heuristics (duplicate content, link floods, …). |

### Resources

Two read resources are exposed: a **form-schema** resource and a
**submissions-dataset** resource (gated by the same submission scopes). No tool
or resource ever exposes integration settings, API keys, or other secrets.

## Console commands

All commands live under the `simple-form/` namespace:

| Command | Does |
| --- | --- |
| `simple-form/doctor` | Read-only configuration + data health check. |
| `simple-form/cache/warm` | Pre-build the form-structure cache for every form (all sites). |
| `simple-form/cache/clear` | Invalidate the cached structure for every form. |
| `simple-form/forms/export --form=<handle> [--out=path.json]` | Export a form definition to JSON. |
| `simple-form/forms/import <path.json> [--mode=rename\|replace\|abort]` | Import a form definition from JSON. |
| `simple-form/forms/apply [--dry-run] [--prune]` | Create or **id-stably update** the forms defined in `config/simple-form/forms/*.json` (matched by handle, so submissions survive). See [Forms as code](forms-as-code.md). |
| `simple-form/forms/status` | Report which forms are config-managed vs database-only. |
| `simple-form/submissions/purge --days=<n> [--form=<handle>]` | Delete (or anonymize) submissions older than `--days`. |
| `simple-form/submissions/export [--form=<handle>] [--out=<path>]` | Export submissions as CSV to stdout or a file. |
| `simple-form/submissions/export-by-email --email=<address> [--out=<path>]` | Export every submission from one email address — a GDPR subject-access response. |
| `simple-form/submissions/erase-by-email --email=<address> [--anonymize] [--dryRun]` | Erase every submission from one address — a right-to-erasure request. `--dryRun` reports the count and changes nothing. |
| `simple-form/submissions/expire-payments` | Cancel submissions whose payment stayed pending past the TTL (also runs on GC). See [Payments](payments.md). |
| `simple-form/integrations/redispatch …` | Re-queue integration dispatch for a submission (all enabled, or one `--integration`). |
| `simple-form/make/field-type [Class] [--namespace=] [--path=]` | Scaffold a custom field type (extends `fields\FieldType`) from a working stub. |
| `simple-form/make/integration [Class] [--namespace=] [--path=]` | Scaffold a custom outbound integration (implements `IntegrationTypeInterface`). |
| `simple-form/make/theme [--path=]` | Copy the built-in render partials into a `templates/` folder to theme. |

Run any command with `--help` for its full option list.

The `make/*` generators write a ready-to-edit stub and print the `Event::on(…)`
line that registers it. Pair them with the copy-paste [`examples/`](../examples/).
