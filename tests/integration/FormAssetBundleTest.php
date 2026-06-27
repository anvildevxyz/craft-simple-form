<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\Plugin;
use anvildev\simpleform\TwigExtension;
use anvildev\simpleform\web\assets\form\FormAsset;
use Craft;

/**
 * @group requires-craft
 */
class FormAssetBundleTest extends SimpleFormTestCase
{
    private bool $originalInline = false;

    protected function tearDown(): void
    {
        Plugin::getInstance()->getSettings()->inlineFormAssets = $this->originalInline;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalInline = Plugin::getInstance()?->getSettings()->inlineFormAssets ?? false;
    }

    public function testRenderRegistersAssetBundleByDefault(): void
    {
        $this->requireCraft();
        Plugin::getInstance()->getSettings()->inlineFormAssets = false;

        $form = $this->createForm('Assets', 'assetsForm', 'Assets Form');
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $view = Craft::$app->getView();
        $this->assertArrayNotHasKey(
            FormAsset::class,
            $view->assetBundles,
            'Bundle should not be registered before a form is rendered'
        );

        $html = (new TwigExtension())->renderForm('assetsForm');

        // The bundle is registered only once a form is output.
        $this->assertArrayHasKey(FormAsset::class, $view->assetBundles);

        // The form markup itself is unchanged in structure, and (in bundle mode)
        // carries no inline <style>/<script> of its own.
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('name="field_' . $fieldId . '"', $html);
        $this->assertStringContainsString('Full Name', $html);
        $this->assertStringNotContainsString('<style>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testInlineFlagEmitsInlineAssets(): void
    {
        $this->requireCraft();
        Plugin::getInstance()->getSettings()->inlineFormAssets = true;

        $form = $this->createForm('Inline', 'inlineForm', 'Inline Form');
        $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $html = (new TwigExtension())->renderForm('inlineForm');

        // Inline escape hatch: CSS + JS travel with the markup, identical classes/behaviour.
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('.simple-form', $html);
        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString('simple-form-submit-btn', $html);
        $this->assertStringContainsString('<form', $html);
    }
}
