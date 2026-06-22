<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\elements\User;
use craft\web\View;
use fabianhaef\simpleform\elements\Form;

/**
 * Render-smoke the forms/edit "After Submit" section (#133). The unit/parity
 * gate doesn't render Twig, so a bad macro (e.g. the elementSelectField or the
 * toggle select) only surfaces here. A CP identity is set first because the
 * `_layouts/cp` header renders the current user's photo.
 *
 * @group requires-craft
 */
class FormEditPostSubmitRenderTest extends SimpleFormTestCase
{
    private function withIdentity(callable $fn): mixed
    {
        $user = new User();
        $user->username = 'sf_render_' . uniqid();
        $user->email = $user->username . '@example.test';
        $user->admin = true;
        $this->assertTrue(Craft::$app->getElements()->saveElement($user), implode(', ', $user->getFirstErrors()));

        $session = Craft::$app->getUser();
        $session->setIdentity($user);
        try {
            return $fn();
        } finally {
            $session->setIdentity(null);
        }
    }

    private function render(Form $form): string
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            return $this->withIdentity(static fn(): string => $view->renderTemplate('simple-form/forms/edit', [
                'form' => $form,
                'currentSite' => $site,
                'supportedSites' => [$site],
                'builderData' => '[]',
                'redirectEntry' => null,
                'volumes' => [],
                'isSourceSite' => true,
            ]));
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    public function testAfterSubmitSectionRenders(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Render', 'render_postsubmit');
        $html = $this->render($form);

        // The after-submit controls now live in the "Confirmation" tab pane
        // (the standalone "After Submit" heading was replaced by the tab label).
        $this->assertStringContainsString('id="confirmation"', $html);
        $this->assertStringContainsString('name="submitMessage"', $html);
        $this->assertStringContainsString('name="postSubmitAction"', $html);
        $this->assertStringContainsString('name="redirectUrl"', $html);
        $this->assertStringContainsString('name="errorMessage"', $html);
    }

    public function testSelectReflectsCurrentAction(): void
    {
        $this->requireCraft();

        $form = $this->createForm('RenderUrl', 'render_postsubmit_url');
        $form->postSubmitAction = 'url';
        $form->redirectUrl = '/thanks';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));

        $reloaded = Form::find()->id($form->id)->one();
        $html = $this->render($reloaded);

        // The url row is not hidden when the action is url; the entry row is.
        $this->assertMatchesRegularExpression('/id="sf-postsubmit-url"(?![^>]*class="hidden")/', $html);
        $this->assertMatchesRegularExpression('/id="sf-postsubmit-entry"[^>]*class="hidden"/', $html);
    }
}
