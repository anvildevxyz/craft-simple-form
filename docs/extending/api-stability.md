# API Stability & Backward Compatibility

This page states what Simple Form considers **public API** — the surface other
plugins and projects may build on with a backward-compatibility promise — and
what is **internal** and may change at any time. If you depend only on the public
surface, a minor or patch upgrade will not break you.

## Versioning policy

Simple Form follows [Semantic Versioning](https://semver.org). For the **public
API** below:

| Release | Meaning for the public API |
| --- | --- |
| **Patch** (`1.0.x`) | Bug fixes only. No signature changes. |
| **Minor** (`1.x.0`) | New, backward-compatible additions (new events, methods, optional params, field types). Existing signatures keep working. |
| **Major** (`x.0.0`) | Breaking changes, each called out in [`upgrading.md`](../upgrading.md). |

A change that only affects the **internal** surface is *not* considered breaking
and can land in a minor or patch release.

## Public API (backward-compatibility guaranteed)

These are stable. Breaking any of them requires a major version and an entry in
the upgrade guide.

| Surface | What's covered |
| --- | --- |
| **Elements** | `elements\Form`, `elements\Submission` and their query classes (`elements\db\FormQuery`, `elements\db\SubmissionQuery`) — public properties and methods. |
| **Events** | Every `Plugin::EVENT_*` constant and the `events\*` event classes dispatched with them (see [Events](../twig-and-api.md#events)). |
| **Service accessors** | `Plugin::getInstance()->getXxx()` accessors (`getSubmissionService`, `getFormStructure`, `getFormRender`, `getEmailService`, `getIntegrations`, `getFieldTypeRegistry`, …) and the *documented* public methods of the services they return (see [PHP API](../twig-and-api.md#php-api)). |
| **Extension points** | `fields\FieldType` (base class), `integrations\IntegrationTypeInterface`, `captcha\CaptchaProviderInterface`, `stencils\Stencil`, `pdf\PdfEngineInterface`, `mcp\tools\ToolInterface`, `mcp\resources\ResourceProviderInterface`. |
| **Twig API** | The `simpleForm()` function and the `craft.simpleForm.*` variable (`web\twig\variables\SimpleFormVariable`). |
| **GraphQL** | The schema components (scopes) and the types/queries/mutations captured in [`reference/schema.graphql`](../reference/schema.graphql). |
| **MCP** | The tool names, their scopes, and the resource handles documented in [Twig & API](../twig-and-api.md#mcp-server-model-context-protocol). |
| **Console** | The `simple-form/*` command names and their documented options. |
| **Render contract** | The overridable theme partials and the documented render-context keys (see [Custom Render Templates](../render-templates.md)). |
| **Front-end JS events** | The `simpleform:*` CustomEvents and their `detail` shapes (see [render-templates.md](../render-templates.md#front-end-javascript-events)). |

## Internal (no compatibility promise)

Everything else may change without notice, even in a patch release. Do not depend
on it. This explicitly includes:

- `helpers\*`, `jobs\*`, `migrations\*`, records (`records\*`), and any
  `services\*` method not documented as public.
- `controllers\*`, `web\assets\*` internals, and CP templates.
- `gql\resolvers\*` and the GraphQL *type class* internals (the schema **shape**
  in the SDL is public; the PHP classes that build it are not).
- Database table names and columns, cache keys, and the persisted JSON shape of
  a submission's `data`.
- Anything marked `@internal` or `private`/`protected`.

## Guidance

- Prefer the events and interfaces over reaching into services.
- When you need a value the public API doesn't expose, open an issue rather than
  depending on an internal — that's how the public surface grows.
- Pin a version range you've tested against (`^1.0`).
