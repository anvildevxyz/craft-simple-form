# Simple Form — examples

Copy-paste starting points for the most common ways to extend Simple Form. Each
is minimal but working — read it, copy it into your own plugin or module, and
adapt. For the full contracts see the [developer API](../docs/twig-and-api.md)
and [render templates](../docs/render-templates.md) guides.

> Tip: the `simple-form/make/*` console commands generate equivalent stubs for
> you — see [Scaffolding generators](../docs/twig-and-api.md#console-commands).

| Example | Extends / implements | Register with | Guide |
| --- | --- | --- | --- |
| [`fieldtype/ColorField.php`](fieldtype/ColorField.php) | `fields\FieldType` | `EVENT_REGISTER_FIELD_TYPES` | [field types](../docs/field-types.md) |
| [`integration/JsonWebhookIntegration.php`](integration/JsonWebhookIntegration.php) | `integrations\IntegrationTypeInterface` | `EVENT_REGISTER_INTEGRATION_TYPES` | [integrations](../docs/integrations.md) |
| [`captcha/MathCaptchaProvider.php`](captcha/MathCaptchaProvider.php) | `captcha\CaptchaProviderInterface` | `EVENT_REGISTER_CAPTCHA_PROVIDERS` | [spam protection](../docs/spam-protection.md) |
| [`theme/field.twig`](theme/field.twig) | a render partial override | a form's *Custom template path* | [render templates](../docs/render-templates.md) |

## Registering an extension

The PHP examples carry the registration snippet in their class doc-block. In
short, from your own plugin/module `init()`:

```php
use fabianhaef\simpleform\Plugin;
use yii\base\Event;

Event::on(
    Plugin::class,
    Plugin::EVENT_REGISTER_FIELD_TYPES,
    fn($e) => $e->types[] = \modules\simpleform\examples\ColorField::class,
);
```

The same pattern applies to `EVENT_REGISTER_INTEGRATION_TYPES` (add to
`$e->types`) and `EVENT_REGISTER_CAPTCHA_PROVIDERS` (add to `$e->providers`). See
[Register events](../docs/twig-and-api.md#register-events-extending-the-plugin)
for every extension point.

## Using the theme override

Copy `theme/field.twig` into your site, e.g. `templates/_simple-form/field.twig`,
then point a form at it (its *Custom template path* = `_simple-form`) or set the
global *Default render template path*. Resolution is per-partial, so you can
override just `field.twig` and inherit everything else.

> The example namespace is `modules\simpleform\examples` to signal "your code".
> Change it to your own module/plugin namespace when you copy a file in.
