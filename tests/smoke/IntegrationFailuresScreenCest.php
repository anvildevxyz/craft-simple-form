<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\controllers\IntegrationsController;
use anvildev\simpleform\integrations\DispatchStatus;
use anvildev\simpleform\Plugin;
use Craft;
use craft\elements\User;
use SmokeTester;
use yii\web\Response;

/**
 * The dead-letter "Dispatch failures" screen renders with its data. Regression
 * guard for the routing bug where /settings/integrations/failures fell through
 * to template routing (no controller), so the template's `failures` variable was
 * undefined — now routed to actionFailures() which supplies it.
 *
 * @author Anvil Dev
 * @since 2.17.0
 */
class IntegrationFailuresScreenCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * actionFailures() renders the template to a 200 with a seeded failed
     * dispatch — proving the action supplies the `failures` the template needs.
     */
    public function testFailuresScreenRendersWithData(SmokeTester $I): void
    {
        $form = $this->createForm('Failures ' . uniqid(), 'failScreen' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submissionId = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada'])['submission']->id;

        $integration = $this->createIntegration('webhook', 'Fail Hook ' . uniqid(), ['url' => 'https://example.test/hook']);
        Plugin::getInstance()->getIntegrations()
            ->logDispatch((int) $integration->id, $submissionId, DispatchStatus::FAILED, 1, 500, 'boom');

        $response = $this->asAdmin(static function(): Response {
            return (new IntegrationsController('integrations', Plugin::getInstance()))->actionFailures();
        });

        $I->assertInstanceOf(Response::class, $response);
        $I->assertSame(200, $response->statusCode, 'the failures screen renders with its data');
    }

    /**
     * It also renders cleanly with zero failures (empty-state path).
     */
    public function testFailuresScreenRendersEmpty(SmokeTester $I): void
    {
        $response = $this->asAdmin(static function(): Response {
            return (new IntegrationsController('integrations', Plugin::getInstance()))->actionFailures();
        });

        $I->assertSame(200, $response->statusCode, 'the empty failures screen still renders');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Run $work with a freshly-seeded admin as the active identity.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function asAdmin(callable $work): mixed
    {
        $user = new User();
        $user->admin = true;
        $user->email = 'fail-admin-' . uniqid() . '@example.test';
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
