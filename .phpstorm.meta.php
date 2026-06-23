<?php

/**
 * PhpStorm metadata for Simple Form — improves IDE autocomplete and type
 * inference for plugin consumers. PhpStorm reads this file automatically (also
 * when the plugin is installed under `vendor/`); it has no runtime effect.
 *
 * @see https://www.jetbrains.com/help/phpstorm/ide-advanced-metadata.html
 */

namespace PHPSTORM_META;

// `Craft::$app->getPlugins()->getPlugin('simple-form')` (and ArrayAccess form)
// resolve to the concrete Plugin, so its getXxxService() accessors autocomplete.
override(\craft\services\Plugins::getPlugin(0), map([
    'simple-form' => \fabianhaef\simpleform\Plugin::class,
]));

// `Craft::$app->getPlugins()->getPluginInfo('simple-form')` etc. stay as-is.

// The CraftVariable behavior `craft.simpleForm` resolves to SimpleFormVariable.
// (Helps PHP-side access; Twig autocomplete additionally needs the Craft CMS
// PhpStorm plugin or a @var hint in the template.)
override(\yii\base\Component::__get(0), map([
    'simpleForm' => \fabianhaef\simpleform\web\twig\variables\SimpleFormVariable::class,
]));
