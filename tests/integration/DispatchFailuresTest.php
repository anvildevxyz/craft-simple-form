<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\controllers\IntegrationsController;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\DispatchStatus;
use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Response;

/**
 * The dispatch-failures dead-letter view: failed dispatches surface, self-clear
 * on a later success, and can be bulk re-queued.
 *
 * @group requires-craft
 */
class DispatchFailuresTest extends SimpleFormTestCase
{
    /** @return array{0: IntegrationModel, 1: Submission} */
    private function seed(string $handle): array
    {
        $form = $this->createForm('Reliability', $handle);

        $integration = new IntegrationModel();
        $integration->type = 'webhook';
        $integration->name = 'Ops hook';
        $integration->enabled = true;
        $integration->settings = ['url' => 'https://example.test/hook'];
        $this->assertTrue(Plugin::getInstance()->getIntegrations()->saveIntegration($integration));

        $sub = new Submission();
        $sub->formId = (int) $form->id;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = [];
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));

        return [$integration, $sub];
    }

    public function testFailedDispatchSurfacesThenSelfClearsOnSuccess(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();
        [$integration, $sub] = $this->seed('dead_letter_form');

        $service->logDispatch((int) $integration->id, (int) $sub->id, DispatchStatus::FAILED, 3, 500, 'boom');

        $this->assertSame(1, $service->countFailedDispatches());
        $failures = $service->getFailedDispatches();
        $this->assertCount(1, $failures);
        $this->assertSame((int) $integration->id, $failures[0]['integrationId']);
        $this->assertSame((int) $sub->id, $failures[0]['submissionId']);
        $this->assertSame('Ops hook', $failures[0]['integrationName']);
        $this->assertSame(500, $failures[0]['responseCode']);
        $this->assertSame('boom', $failures[0]['message']);

        // A later successful attempt for the same pair clears it from the list.
        $service->logDispatch((int) $integration->id, (int) $sub->id, DispatchStatus::SUCCESS, 4, 200, 'OK');
        $this->assertSame(0, $service->countFailedDispatches());
        $this->assertSame([], $service->getFailedDispatches());
    }

    public function testOnlyLatestAttemptCountsPerPair(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();
        [$integration, $sub] = $this->seed('latest_attempt_form');

        // Earlier success, then a later failure → the pair IS failed (latest wins).
        $service->logDispatch((int) $integration->id, (int) $sub->id, DispatchStatus::SUCCESS, 1, 200, 'OK');
        $service->logDispatch((int) $integration->id, (int) $sub->id, DispatchStatus::FAILED, 2, 502, 'later boom');

        $this->assertSame(1, $service->countFailedDispatches());
        $this->assertSame(502, $service->getFailedDispatches()[0]['responseCode']);
    }

    public function testResendAllControllerRequeuesEachFailure(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();
        [$integration, $sub] = $this->seed('resend_all_form');
        $service->logDispatch((int) $integration->id, (int) $sub->id, DispatchStatus::FAILED, 3, 500, 'boom');

        $request = Craft::$app->getRequest();
        $request->setBodyParams([]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new IntegrationsController('integrations', Plugin::getInstance());
        $controller->enableCsrfValidation = false;

        // Runs without error; re-queues the job. The failure stays listed until a
        // later success is logged (the job hasn't run yet).
        $controller->actionResendAll();
        $this->assertSame(1, $service->countFailedDispatches());
    }
}
