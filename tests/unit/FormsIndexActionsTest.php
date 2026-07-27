<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * #279 — the forms-index row actions post via Craft's `.formsubmit` with
 * `data-form="false"`. The index has no fullPageForm, so without the explicit
 * opt-out Craft resolves the posting form via `closest('form')` and the click
 * silently no-ops (Delete previously used unbound `data-method` attributes and
 * did nothing at all). Source-level guards, same style as FieldBuilderTest.
 */
class FormsIndexActionsTest extends TestCase
{
    private function indexTemplate(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/templates/forms/index.twig');
    }

    public function testRowAndStencilActionsBuildTheirOwnForm(): void
    {
        $code = $this->indexTemplate();

        // Each action posts through formsubmit + data-form="false" — the index
        // has no fullPageForm, so without the opt-out the click silently no-ops.
        foreach (['simple-form/forms/new-from-stencil', 'simple-form/forms/duplicate', 'simple-form/forms/delete'] as $action) {
            $pos = strpos($code, 'data-action="' . $action . '"');
            $this->assertNotFalse($pos, "$action trigger missing");
            $context = substr($code, max(0, $pos - 500), 1000);
            $this->assertStringContainsString('formsubmit', $context, "$action trigger is not .formsubmit");
            $this->assertStringContainsString('data-form="false"', $context, "$action trigger lacks data-form=\"false\"");
        }
    }

    public function testDeleteUsesCraftConfirmNotDeadAttributes(): void
    {
        $code = $this->indexTemplate();

        // The old markup used unbound data-method/onclick that nothing reads.
        $this->assertStringNotContainsString('data-method', $code);
        $this->assertStringNotContainsString('onclick', $code);
        $this->assertStringContainsString('data-confirm', $code);
    }

    public function testDeleteActionRedirectsNonAjaxRequests(): void
    {
        $code = (string) file_get_contents(__DIR__ . '/../../src/controllers/FormsController.php');

        // actionDelete must not answer a full-page formsubmit POST with raw JSON.
        $deletePos = strpos($code, 'public function actionDelete');
        $this->assertNotFalse($deletePos);
        $body = substr($code, $deletePos, 1500);
        $this->assertStringContainsString('getAcceptsJson()', $body);
        $this->assertStringContainsString("redirect('simple-form/forms')", $body);
    }
}
