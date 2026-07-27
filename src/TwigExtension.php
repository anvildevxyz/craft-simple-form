<?php

namespace anvildev\simpleform;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Registers the `{{ simpleForm(handle, options) }}` Twig function.
 *
 * Rendering is delegated to {@see \anvildev\simpleform\services\FormRenderService},
 * which resolves the form's (overridable) Twig theme. With no custom template
 * path configured the output is identical to the built-in default theme.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class TwigExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('simpleForm', [$this, 'renderForm'], [
                'is_safe' => ['html'],
                'needs_environment' => false,
            ]),
        ];
    }

    /**
     * Render a form to HTML via the resolved theme.
     *
     * @param array<string, mixed> $options render options (submitText, class, id,
     *        attributes, theme)
     * @throws \Throwable from the underlying View render
     */
    public function renderForm(string $handle, array $options = []): string
    {
        return Plugin::getInstance()->getFormRender()->renderForm($handle, $options);
    }
}
