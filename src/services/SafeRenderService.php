<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\web\twig\SecurityPolicy;
use craft\web\View;
use Twig\Extension\SandboxExtension;
use yii\base\Component;

/**
 * Renders admin/editor-authored Twig strings with the Twig sandbox FORCED on,
 * the single safe-render seam shared by the notification email body
 * ({@see EmailService}) and the HTML layout block
 * ({@see \fabianhaef\simpleform\fields\HtmlFieldType}).
 *
 * Craft's own `renderSandboxedString()` is a no-op unless the operator sets the
 * global `enableTwigSandbox` config (default false), so we cannot rely on it.
 * Instead this service explicitly enables the {@see SandboxExtension} for one
 * render and swaps in a {@see SecurityPolicy} derived from Craft's twig-sandbox
 * config (safe tags / filters / functions), optionally widening the allowed
 * classes for legitimate model access. `craft.app`, the database, the
 * filesystem and arbitrary classes stay out of reach, and the original policy
 * and sandbox state are always restored.
 */
class SafeRenderService extends Component
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Render an author-supplied Twig string under the forced sandbox.
     *
     * @param array<string, mixed> $variables template variables
     * @param array<int, class-string> $allowedClasses classes the policy may
     *   expose method/property access for, on top of Craft's defaults
     * @throws \Throwable when the sandbox rejects the template or rendering fails
     */
    public function render(string $template, array $variables = [], array $allowedClasses = []): string
    {
        $view = Craft::$app->getView();
        $twig = $view->getTwig(View::TEMPLATE_MODE_SITE);
        /** @var SandboxExtension $sandbox */
        $sandbox = $twig->getExtension(SandboxExtension::class);

        $base = $sandbox->getSecurityPolicy();
        if ($base instanceof SecurityPolicy && $allowedClasses !== []) {
            $allowedClasses = array_merge($base->getAllowedClasses(), $allowedClasses);
        }

        $scoped = new SecurityPolicy(
            $base instanceof SecurityPolicy ? $base->getAllowedTags() : [],
            $base instanceof SecurityPolicy ? $base->getAllowedFilters() : [],
            $base instanceof SecurityPolicy ? $base->getAllowedFunctions() : [],
            $base instanceof SecurityPolicy ? $base->getAllowedMethods() : [],
            $base instanceof SecurityPolicy ? $base->getAllowedProperties() : [],
            $allowedClasses,
        );

        $wasSandboxed = $sandbox->isSandboxed();
        $sandbox->setSecurityPolicy($scoped);
        $sandbox->enableSandbox();
        try {
            return $view->renderString($template, $variables, View::TEMPLATE_MODE_SITE);
        } finally {
            if (!$wasSandboxed) {
                $sandbox->disableSandbox();
            }
            $sandbox->setSecurityPolicy($base);
        }
    }
}
