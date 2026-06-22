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
        return $this->withSandbox(
            View::TEMPLATE_MODE_SITE,
            $allowedClasses,
            fn(View $view): string => $view->renderString($template, $variables, View::TEMPLATE_MODE_SITE),
        );
    }

    /**
     * Render an author-supplied Twig *template file* under the forced sandbox.
     *
     * Mirrors {@see render()} but resolves a template path (CP mode) instead of an
     * inline string, so an overridable layout file (e.g. a form's `pdf.twig`)
     * cannot reach `craft.app`, the database or the filesystem either.
     *
     * @param array<string, mixed> $variables template variables
     * @param array<int, class-string> $allowedClasses classes the policy may
     *   expose method/property access for, on top of Craft's defaults
     * @throws \Throwable when the sandbox rejects the template or rendering fails
     */
    public function renderTemplate(string $template, array $variables = [], array $allowedClasses = []): string
    {
        return $this->withSandbox(
            View::TEMPLATE_MODE_CP,
            $allowedClasses,
            fn(View $view): string => $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP),
        );
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Run $render with the Twig sandbox forced on for the given template mode and
     * a security policy scoped to additionally allow $allowedClasses, restoring
     * the original policy and sandbox state afterwards.
     *
     * @param string $mode a {@see View} TEMPLATE_MODE_* constant
     * @param array<int, class-string> $allowedClasses
     * @param callable(View): string $render
     * @throws \Throwable when the sandbox rejects the template or rendering fails
     */
    private function withSandbox(string $mode, array $allowedClasses, callable $render): string
    {
        $view = Craft::$app->getView();
        $twig = $view->getTwig($mode);
        /** @var SandboxExtension $sandbox */
        $sandbox = $twig->getExtension(SandboxExtension::class);

        $base = $sandbox->getSecurityPolicy();
        $basePolicy = $base instanceof SecurityPolicy ? $base : null;
        if ($basePolicy !== null && $allowedClasses !== []) {
            $allowedClasses = array_merge($basePolicy->getAllowedClasses(), $allowedClasses);
        }

        $scoped = new SecurityPolicy(
            $basePolicy?->getAllowedTags() ?? [],
            $basePolicy?->getAllowedFilters() ?? [],
            $basePolicy?->getAllowedFunctions() ?? [],
            $basePolicy?->getAllowedMethods() ?? [],
            $basePolicy?->getAllowedProperties() ?? [],
            $allowedClasses,
        );

        $wasSandboxed = $sandbox->isSandboxed();
        $sandbox->setSecurityPolicy($scoped);
        $sandbox->enableSandbox();
        try {
            return $render($view);
        } finally {
            if (!$wasSandboxed) {
                $sandbox->disableSandbox();
            }
            $sandbox->setSecurityPolicy($base);
        }
    }
}
