<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use Craft;
use craft\elements\User;
use craft\web\View;

/**
 * Render-smoke the CP screens added in the dashboard/element-index work (#cp):
 * the Dashboard landing page, the form edit screen's Stats tab + Preview button,
 * and the Forms index signal columns. The unit gate doesn't render Twig, so a
 * broken variable contract only surfaces here.
 *
 * @group requires-craft
 */
class CpScreensRenderTest extends SimpleFormTestCase
{
    private function withIdentity(callable $fn): mixed
    {
        $user = new User();
        $user->username = 'sf_cp_' . uniqid();
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

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $template, array $vars): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            return $this->withIdentity(static fn(): string => $view->renderTemplate($template, $vars));
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    public function testDashboardEmptyStateRenders(): void
    {
        $this->requireCraft();

        $html = $this->render('simple-form/dashboard/index', [
            'formCount' => 0,
            'enabledFormCount' => 0,
            'stats' => ['total' => 0, 'new' => 0, 'read' => 0, 'archived' => 0, 'spam' => 0],
            'today' => 0,
            'last7' => 0,
            'perDay' => [],
            'chartDays' => 30,
            'perForm' => [],
            'recent' => [],
            'failedDispatches' => 0,
            'canManageForms' => true,
            'hasAnySubmissions' => false,
        ]);

        // One inviting zilch panel instead of a grid of zero tiles.
        $this->assertStringContainsString('zilch', $html);
        $this->assertStringContainsString('No submissions yet.', $html);
        $this->assertStringContainsString('New Form', $html);
        $this->assertStringNotContainsString('sf-dash-tiles', $html);
        // No chart or needs-attention pane on a clean install.
        $this->assertStringNotContainsString('Needs attention', $html);
    }

    public function testDashboardWithActivityRendersChartAttentionAndTopForms(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Dashboard Form', 'dash_form_' . uniqid());

        $html = $this->render('simple-form/dashboard/index', [
            'formCount' => 1,
            'enabledFormCount' => 1,
            'stats' => ['total' => 5, 'new' => 2, 'read' => 1, 'archived' => 0, 'spam' => 2],
            'today' => 1,
            'last7' => 4,
            'perDay' => [['date' => '2026-06-27', 'count' => 1], ['date' => '2026-06-28', 'count' => 3]],
            'chartDays' => 30,
            'perForm' => [['formId' => (int) $form->id, 'name' => 'Dashboard Form', 'count' => 5]],
            'byWeekday' => [0, 0, 0, 0, 1, 3, 0],
            'recent' => [],
            'failedDispatches' => 2,
            'canManageForms' => true,
            'hasAnySubmissions' => true,
        ]);

        $this->assertStringContainsString('Submissions over time', $html);
        $this->assertStringContainsString('sf-bar-chart', $html);
        // The chart carries a date axis and a total/peak summary (not just bars).
        $this->assertStringContainsString('sf-chart-axis', $html);
        $this->assertStringContainsString('peak', $html);
        // The by-weekday breakdown renders labelled horizontal bars.
        $this->assertStringContainsString('By weekday', $html);
        $this->assertStringContainsString('sf-hbar-fill', $html);
        // Needs-attention (side-column pane) surfaces both unread submissions and failed dispatches.
        $this->assertStringContainsString('Needs attention', $html);
        $this->assertStringContainsString('sf-attention', $html);
        $this->assertStringContainsString('status=new', $html);
        $this->assertStringContainsString('integrations/failures', $html);
        // Top forms lists the seeded form.
        $this->assertStringContainsString('Dashboard Form', $html);
    }

    public function testFormEditStatsTabAndPreviewRender(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Stats Form', 'stats_form_' . uniqid());

        $html = $this->render('simple-form/forms/edit', [
            'form' => $form,
            'currentSite' => Craft::$app->getSites()->getCurrentSite(),
            'supportedSites' => [Craft::$app->getSites()->getCurrentSite()],
            'builderData' => '[]',
            'redirectEntry' => null,
            'volumes' => [],
            'isSourceSite' => true,
            'stats' => [
                'breakdown' => ['total' => 7, 'new' => 7, 'read' => 0, 'archived' => 0, 'spam' => 0],
                'last' => null,
            ],
        ]);

        // The Stats pane and its tab anchor render with the figures.
        $this->assertStringContainsString('id="sf-stats"', $html);
        $this->assertStringContainsString('#sf-stats', $html);
        // The Preview button opens the form's real front-end page.
        $this->assertStringContainsString('simple-form/form/' . $form->handle, $html);
        $this->assertStringContainsString('Preview', $html);
        // Jump-off links to the form's submissions + report.
        $this->assertStringContainsString('/report', $html);
    }

    public function testFormEditOmitsStatsTabWhenNoStatsProvided(): void
    {
        $this->requireCraft();

        // The edit screen must still render for callers that don't pass `stats`
        // (e.g. other render paths/tests) — the Stats tab is then simply absent.
        $form = $this->createForm('No Stats Form', 'nostats_form_' . uniqid());

        $html = $this->render('simple-form/forms/edit', [
            'form' => $form,
            'currentSite' => Craft::$app->getSites()->getCurrentSite(),
            'supportedSites' => [Craft::$app->getSites()->getCurrentSite()],
            'builderData' => '[]',
            'redirectEntry' => null,
            'volumes' => [],
            'isSourceSite' => true,
        ]);

        $this->assertStringNotContainsString('id="sf-stats"', $html);
        // The Build pane still renders, proving the screen didn't error.
        $this->assertStringContainsString('id="sf-build"', $html);
    }

    public function testFormsIndexShowsSignalColumns(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Signal Form', 'signal_form_' . uniqid());

        $html = $this->render('simple-form/forms/index', [
            'forms' => [$form],
            'currentSite' => Craft::$app->getSites()->getCurrentSite(),
            'stencils' => [],
            'formStats' => [
                (int) $form->id => ['count' => 3, 'spam' => 1, 'last' => null],
            ],
        ]);

        $this->assertStringContainsString('Submissions', $html);
        $this->assertStringContainsString('Last submission', $html);
        // The count links through to that form's filtered submissions.
        $this->assertStringContainsString('formId=' . $form->id, $html);
    }
}
