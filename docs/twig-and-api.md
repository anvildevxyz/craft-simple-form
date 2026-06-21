# Twig, PHP, GraphQL & MCP API (Developer Integration)

Simple Form is built to be embedded and automated, not just clicked through the
control panel. The same form definition is reachable from Twig templates, plain
PHP, a headless GraphQL schema, and a token-authenticated MCP server, and the
submission lifecycle is open through a small set of events.

This guide is the developer's map of those entry points. For restyling the
front-end markup (overridable Twig partials, the render context, class hooks)
see [Custom Render Templates](render-templates.md) — this page focuses on the
*programmatic* surface.

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

### Loading a form and its fields

`Form` is a standard Craft element, so the element query is the entry point:

```php
use fabianhaef\simpleform\elements\Form;

$form = Form::find()->handle('contact')->one();
```

The form's title, description and field labels are **per-site** content, so query
on the site you want resolved (the Twig and GraphQL layers default to the current
/ primary site for you). The resolved field set for a given form + site is built
by the form-structure service:

```php
use fabianhaef\simpleform\Plugin;

$siteId = Craft::$app->getSites()->getCurrentSite()->id;
$fields = Plugin::getInstance()->getFormStructure()->getFieldSet($form->id, $siteId);
```

### Submitting and editing programmatically

`SubmissionService` is the shared save core — the Twig submit controller, the
GraphQL mutations and the MCP tools all route through it, so validation, spam
protection, conditional logic, the lifecycle events and the notification email
behave identically no matter the caller:

```php
use fabianhaef\simpleform\Plugin;

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
    errors { key messages }
  }
}
```

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
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\events\SubmissionEvent;
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

### Register events (extending the plugin)

Three register events let third parties extend the plugin. All are fired on
`Plugin::class`:

```php
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\events\RegisterIntegrationTypesEvent;
use fabianhaef\simpleform\events\RegisterCaptchaProvidersEvent;
use fabianhaef\simpleform\events\RegisterStencilsEvent;
use fabianhaef\simpleform\stencils\Stencil;
use yii\base\Event;

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
| `EVENT_REGISTER_INTEGRATION_TYPES` | `$e->types[]` | `integrations\IntegrationTypeInterface` |
| `EVENT_REGISTER_CAPTCHA_PROVIDERS` | `$e->providers[]` | `captcha\CaptchaProviderInterface` |
| `EVENT_REGISTER_STENCILS` | `$e->stencils[]` | a `stencils\Stencil` instance |

Custom field types are registered internally via the field-type registry
(`Plugin::getInstance()->getFieldTypeRegistry()->registerFieldType(MyField::class)`),
not through a register event — call it from your own plugin's `init()`.

## MCP server (Model Context Protocol)

Simple Form ships a transport-agnostic MCP server so an AI agent can manage forms
and analyse submissions over JSON-RPC 2.0. It is exposed at `simple-form/mcp`.

**Off by default.** The endpoint returns 404 unless the `enableMcp` setting is
turned on, and every request must carry a bearer token. Tokens are stored
hash-only (`mcpTokens` setting) — the plaintext secret is shown once at creation
and never persisted.

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

- **Form management** (`forms:manage`): `list_forms`, `get_form`, `create_form`,
  `update_form`, `delete_form`, `add_field`, `update_field`, `reorder_fields`,
  `delete_field`, and read-only `list_integrations` (name/type/enabled only —
  never secrets).
- **Submissions** (`submissions:read` / `submissions:export`):
  `query_submissions`, `get_submission`, `submission_stats`,
  `export_submissions`.
- **AI insight** (`submissions:read`): `summarize_submissions`,
  `categorize_submissions`, `detect_spam_patterns`.

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
| `simple-form/submissions/purge --days=<n> [--form=<handle>]` | Delete (or anonymize) submissions older than `--days`. |
| `simple-form/submissions/export [--form=<handle>] [--out=<path>]` | Export submissions as CSV to stdout or a file. |
| `simple-form/integrations/redispatch …` | Re-queue integration dispatch for a submission (all enabled, or one `--integration`). |

Run any command with `--help` for its full option list.
