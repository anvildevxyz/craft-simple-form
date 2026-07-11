<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\integrations\DispatchStatus;
use anvildev\simpleform\Plugin;
use Craft;
use craft\elements\User;
use SmokeTester;
use yii\web\Response;

/**
 * CP Dashboard / Overview landing page (#255): the "needs attention" panel and
 * per-form quick links reflect the real submission + dispatch state.
 *
 * The dashboard's numbers are assembled inline by
 * {@see \anvildev\simpleform\controllers\DashboardController::actionIndex()} from
 * {@see \anvildev\simpleform\services\ReportsService} and
 * {@see \anvildev\simpleform\services\IntegrationsService::countFailedDispatches()}.
 * Those exact service calls are the real data path the screen renders, so the
 * data-panel tests assert them directly; a second test drives the whole
 * controller action (template render included) under an authenticated admin.
 *
 * @author Fabian Haefliger
 * @since 2.17.0
 */
class DashboardCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * The needs-attention data the dashboard assembles — the new-submission
     * count and the failed-dispatch (dead-letter) count — reflects a seeded
     * new submission plus a failed integration dispatch.
     */
    public function testNeedsAttentionReflectsNewSubmissionsAndFailedDispatches(SmokeTester $I): void
    {
        $reports = Plugin::getInstance()->getReports();
        $integrations = Plugin::getInstance()->getIntegrations();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $newBefore = $reports->statusBreakdown($siteId)['new'];
        $failedBefore = $integrations->countFailedDispatches();

        // A brand-new submission (readStatus defaults to NEW) is the first
        // needs-attention signal.
        $form = $this->createForm('Dash', 'dashNeeds' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submissionId = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada'])['submission']->id;

        // A FAILED dispatch of that submission is the second signal (dead-letter).
        $integration = $this->createIntegration('webhook', 'Dash Hook ' . uniqid(), ['url' => 'https://example.test/hook']);
        $integrations->logDispatch((int) $integration->id, $submissionId, DispatchStatus::FAILED, 1, 500, 'boom');

        $reports->invalidateCache();

        $I->assertSame($newBefore + 1, $reports->statusBreakdown($siteId)['new'], 'the new submission raises the new count');
        $I->assertSame($failedBefore + 1, $integrations->countFailedDispatches(), 'the failed dispatch raises the dead-letter count');
    }

    /**
     * The per-form quick-link data ({@see ReportsService::perFormTotals()}, the
     * dashboard's `perForm` list) surfaces the seeded form with its submission
     * total.
     */
    public function testPerFormQuickLinksSurfaceSeededForm(SmokeTester $I): void
    {
        $reports = Plugin::getInstance()->getReports();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->createForm('Dash Totals', 'dashTotals' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada']);
        $this->submitRequest($form->handle, ['field_' . $fieldId => 'Grace']);

        $reports->invalidateCache();

        $row = null;
        foreach ($reports->perFormTotals($siteId) as $candidate) {
            if ($candidate['formId'] === (int) $form->id) {
                $row = $candidate;
                break;
            }
        }

        $I->assertNotNull($row, 'the seeded form appears in the per-form quick links');
        $I->assertSame(2, $row['count'], 'its quick link carries the correct submission total');
        $I->assertSame('Dash Totals', $row['name']);
    }

    /**
     * End-to-end controller coverage: with an authenticated admin identity the
     * dashboard action assembles its data and renders its CP template to a 200.
     */
    public function testActionIndexRendersForAdmin(SmokeTester $I): void
    {
        $form = $this->createForm('Dash Render', 'dashRender' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada']);

        $response = $this->asAdmin(static function(): Response {
            $controller = new \anvildev\simpleform\controllers\DashboardController('dashboard', Plugin::getInstance());
            return $controller->actionIndex();
        });

        $I->assertInstanceOf(Response::class, $response, 'the dashboard action returns a web response');
        $I->assertSame(200, $response->statusCode, 'the dashboard renders successfully for an admin');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Run $work with a freshly-seeded admin as the active identity, restoring the
     * prior identity afterwards.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function asAdmin(callable $work): mixed
    {
        $user = new User();
        $user->admin = true;
        $user->email = 'dash-admin-' . uniqid() . '@example.test';
        $user->username = $user->email;
        Craft::$app->getElements()->saveElement($user);

        $session = Craft::$app->getUser();
        $previous = $session->getIdentity();
        try {
            $session->setIdentity($user);
            return $work();
        } finally {
            $session->setIdentity($previous);
        }
    }
}
