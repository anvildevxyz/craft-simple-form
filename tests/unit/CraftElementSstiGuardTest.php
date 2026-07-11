<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the Craft Element integration `titleTemplate` SSTI fix.
 *
 * The template is editor-authored under the (non-admin) `manageIntegrations`
 * permission and rendered per submission, so it MUST go through the forced-
 * sandbox {@see \anvildev\simpleform\services\SafeRenderService} seam — never
 * the raw `View::renderString()`, which would expose `craft.app`, the database
 * and the filesystem and turn the permission into full site takeover (CWE-94).
 *
 * Kept as a source-level assertion (like the other pure guards in this suite):
 * the sandbox itself is exercised in the render/integration suite, while this
 * fast check prevents the seam from silently regressing back to a raw render.
 */
class CraftElementSstiGuardTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(
            __DIR__ . '/../../src/integrations/CraftElementIntegration.php',
        );
    }

    public function testTitleTemplateDoesNotUseRawRenderString(): void
    {
        $this->assertStringNotContainsString(
            'getView()->renderString(',
            $this->source(),
            'titleTemplate must not be rendered with the unsandboxed View::renderString()',
        );
    }

    public function testTitleTemplateRoutesThroughSafeRender(): void
    {
        $this->assertStringContainsString(
            'getSafeRender()->render(',
            $this->source(),
            'titleTemplate must render through the forced-sandbox SafeRenderService',
        );
    }
}
