# Simple Form

A lightweight, translatable form builder for Craft CMS. Create forms in the Control Panel, render them in your templates, and manage submissions—with full multi-site translation support.

## Features

- **Form Builder**: Create and manage forms with an intuitive CP interface
- **8 Field Types**: Text, Email, Textarea, Select, Checkbox, Radio, Date, Number
- **Translatable**: Form titles, descriptions, and field labels translate per site
- **Submissions**: Store and manage form submissions as native Craft elements
- **Email Notifications**: Auto-send submission emails with translatable templates
- **Twig API**: Render forms with `{{ craft.simpleForm.form(handle) }}`
- **PHP API**: Extensible classes for custom form rendering and handling

## Installation

1. Install the plugin via Composer:
   ```bash
   composer require fabianhaef/craft-simple-form
   ```

2. Install the plugin in Craft:
   ```bash
   php craft plugin/install simple-form
   ```

3. Run migrations:
   ```bash
   php craft migrate/all
   ```

## Quick Start

### Create a Form in CP

1. Navigate to **Simple Form > Forms**
2. Click **New Form**
3. Enter a name, handle, and email recipient
4. Add fields (Text, Email, etc.)
5. Save

### Render in Twig

```twig
{{ craft.simpleForm.form('contact') }}
```

### Build Custom Forms (PHP API)

```php
$form = \fabianhaef\simpleform\elements\Form::find()
    ->handle('contact')
    ->one();

$fields = $form->getFields();
// Render fields however you like...
```

## Documentation

Developer documentation lives in **[`docs/`](docs/README.md)** — testing,
smoke tests, and permissions reference. Start with
[Running tests](docs/testing/RUNNING_TESTS.md).

See the [issues](https://github.com/fabianhaef/craft-simple-form/issues) for the
development roadmap and feature details.

## License

MIT
