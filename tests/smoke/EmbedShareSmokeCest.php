<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Plugin;
use anvildev\simpleform\web\assets\form\FormAsset;
use Craft;
use craft\web\View;
use SmokeTester;

/**
 * Embed & share smoke tests (#247): the standalone page wraps the form in a full
 * HTML document, and the embed loader script is present with its markers.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class EmbedShareSmokeCest extends BaseSmokeCest
{
    public function testStandalonePageWrapsTheForm(SmokeTester $I): void
    {
        $handle = 'embedSmoke' . uniqid();
        $form = $this->createForm('Lead', $handle);
        $this->createField((int) $form->id, 'text', 'name', 'Name');
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        $formHtml = Plugin::getInstance()->getFormRender()->renderForm($handle);
        $page = $view->renderTemplate('simple-form/standalone', ['form' => $form, 'formHtml' => $formHtml]);
        $view->setTemplateMode($mode);

        $I->assertStringContainsString('<!DOCTYPE html>', $page);
        $I->assertStringContainsString('class="simple-form"', $page);
        $I->assertStringContainsString('name="formHandle" value="' . $handle . '"', $page);
        $I->assertStringContainsString('class="sf-standalone-main"', $page);
        $I->assertStringContainsString('</html>', $page);
    }

    public function testEmbedLoaderScriptIsServable(SmokeTester $I): void
    {
        $path = FormAsset::distPath('js/embed.js');
        $I->assertFileExists($path);

        $js = (string) file_get_contents($path);
        // The three embed modes + the height-sync contract the standalone page uses.
        $I->assertStringContainsString('data-sf-embed', $js);
        $I->assertStringContainsString('data-sf-mode', $js);
        $I->assertStringContainsString('sf-embed-panel', $js);
        $I->assertStringContainsString('simpleform:height', $js);
    }
}
