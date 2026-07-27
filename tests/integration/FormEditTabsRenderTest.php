<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use Craft;
use craft\elements\User;
use craft\web\View;

/**
 * Render-smoke the forms/edit tab strip (tabbed-editor UX work): the screen is
 * split into native CP tab panes (Build / Details / Confirmation / Rules) plus
 * navigation tabs to the Notifications + Integrations sibling screens, and the
 * built-in Email Settings block has been removed (email config now lives on the
 * Notifications screen). The unit gate doesn't render Twig, so a broken tabs
 * dict or stray Email field only surfaces here.
 *
 * @group requires-craft
 */
class FormEditTabsRenderTest extends SimpleFormTestCase
{
    private function withIdentity(callable $fn): mixed
    {
        $user = new User();
        $user->username = 'sf_tabs_' . uniqid();
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

    public function testProFeaturesInUseBannerRendersWhenPresent(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Downgraded', 'render_downgrade');

        $site = Craft::$app->getSites()->getCurrentSite();
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            $html = $this->withIdentity(static fn(): string => $view->renderTemplate('simple-form/forms/edit', [
                'form' => $form,
                'currentSite' => $site,
                'supportedSites' => [$site],
                'builderData' => '[]',
                'redirectEntry' => null,
                'volumes' => [],
                'isSourceSite' => true,
                'proFeaturesInUse' => ['conditional logic', 'multi-page forms'],
            ]));
        } finally {
            $view->setTemplateMode($mode);
        }

        $this->assertStringContainsString('Standard features in use', $html);
        $this->assertStringContainsString('conditional logic', $html);
        $this->assertStringContainsString('multi-page forms', $html);

        // The banner stays absent for an ordinary (Pro / no-Pro-usage) editor.
        $plain = $this->render($form);
        $this->assertStringNotContainsString('Standard features in use', $plain);
    }

    public function testTabStripAndPanesRender(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Tabbed', 'render_tabs');
        $html = $this->render($form);

        // The native CP tab strip is present...
        $this->assertStringContainsString('id="tabs"', $html);

        // ...and each in-page pane is rendered with the id the tab anchor targets.
        // Ids are sf-* namespaced so they can't collide with reserved Craft CP
        // ids (an unprefixed `details` would inherit the meta sidebar width).
        foreach (['sf-build', 'sf-details', 'sf-confirmation', 'sf-rules'] as $pane) {
            $this->assertStringContainsString('id="' . $pane . '"', $html);
        }

        // The field builder lives in the Build pane; core settings still render.
        $this->assertStringContainsString('class="sf-builder"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="handle"', $html);
    }

    public function testNotificationsAndIntegrationsAreNavTabs(): void
    {
        $this->requireCraft();

        $form = $this->createForm('TabbedNav', 'render_tabs_nav');
        $html = $this->render($form);

        // For a saved form the strip links out to the sibling screens.
        $this->assertStringContainsString('/forms/' . $form->id . '/notifications', $html);
        $this->assertStringContainsString('/forms/' . $form->id . '/integrations', $html);
    }

    public function testEmailSettingsInputsAreGone(): void
    {
        $this->requireCraft();

        // Even a form carrying legacy email values must not re-expose the inputs.
        $form = $this->createForm('TabbedEmail', 'render_tabs_email', null, null, 'to@example.test', 'Subj');
        $html = $this->render($form);

        $this->assertStringNotContainsString('name="emailTo"', $html);
        $this->assertStringNotContainsString('name="emailSubject"', $html);
        $this->assertStringNotContainsString('name="emailReplyTo"', $html);
        $this->assertStringNotContainsString('name="emailBody"', $html);
    }
}
