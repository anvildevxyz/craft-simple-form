<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * #287 — a plain (no-JS) form POST must round-trip as HTML: flashed
 * message/errors + redirect back, never raw JSON in the visitor's browser.
 * The bundled script marks its requests (X-Requested-With + Accept), so the
 * controller keeps answering those with JSON. Source-level guards, matching
 * the other template/controller contract tests here.
 */
class SubmitNoJsFallbackTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/' . $relativePath);
    }

    public function testControllerBranchesOnJsonNegotiation(): void
    {
        $code = $this->source('controllers/SubmitController.php');

        // Only an explicit text/html Accept (a plain browser form POST) takes
        // the HTML path; fetch defaults (*/*), missing Accept, and the bundled
        // script's X-Requested-With all keep the JSON contract, so existing
        // headless clients are unaffected.
        $this->assertStringContainsString("str_contains((string) \$request->getHeaders()->get('Accept'), 'text/html')", $code);
        $this->assertStringContainsString('!$acceptsHtml || $request->getAcceptsJson() || $request->getIsAjax()', $code);

        // The HTML branch exists for both outcomes and redirects back.
        $this->assertStringContainsString('private function htmlSuccess(', $code);
        $this->assertStringContainsString('private function htmlErrors(', $code);
        $this->assertStringContainsString('private function redirectBack(', $code);

        // Flashes are namespaced per form handle so two forms on one page
        // can't cross-talk.
        $this->assertStringContainsString('simpleForm:success:', $code);
        $this->assertStringContainsString('simpleForm:errors:', $code);

        // The no-JS success path still honors the form's redirect action and
        // the offsite payment redirect.
        $this->assertMatchesRegularExpression(
            '/if \(!\$wantsJson\) \{\s+if \(!empty\(\$post\[\'redirectUrl\'\]\)\) \{\s+return \$this->redirect\(\$post\[\'redirectUrl\'\]\);/',
            $code,
        );
        $this->assertStringContainsString("redirect(\$result['paymentRedirectUrl'])", $code);
    }

    public function testRenderContextCarriesTheFlashes(): void
    {
        $code = $this->source('services/FormRenderService.php');

        $this->assertStringContainsString("getFlash(\"simpleForm:success:{\$form->handle}\"", $code);
        $this->assertStringContainsString("getFlash(\"simpleForm:errors:{\$form->handle}\"", $code);
        // Console/queue renders must never touch the session.
        $this->assertStringContainsString('getIsConsoleRequest()', $code);
        $this->assertStringContainsString("'flashSuccess' =>", $code);
        $this->assertStringContainsString("'flashErrors' =>", $code);
    }

    public function testFormTemplateRendersTheRoundTrip(): void
    {
        $code = $this->source('templates/_form/form.twig');

        $this->assertStringContainsString('simple-form-success', $code);
        $this->assertStringContainsString('role="status"', $code);
        // Errors render through the documented errors partial (theme seam).
        $this->assertStringContainsString('include partials.errors with { errors: flashErrors }', $code);
    }

    public function testBundledScriptMarksItsSubmitAsAjax(): void
    {
        $js = $this->source('web/assets/form/dist/js/simple-form.js');

        // The main submit fetch must send both signals, or the controller
        // would answer the JS path with a redirect instead of JSON.
        $this->assertStringContainsString('"X-Requested-With": "XMLHttpRequest"', $js);
        $this->assertStringContainsString('"Accept": "application/json"', $js);
    }
}
