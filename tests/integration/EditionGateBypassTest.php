<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\controllers\FieldsController;
use anvildev\simpleform\controllers\IntegrationsController;
use anvildev\simpleform\Editions;
use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use craft\web\Response;

/**
 * Regression coverage for two edition-gate bypasses in CP write paths the
 * Solo/Pro split originally missed: adding a Pro field type through
 * FieldsController::actionAdd, and swapping an existing Solo-allowed integration
 * to a Pro type through IntegrationsController::actionSave.
 *
 * @group requires-craft
 */
class EditionGateBypassTest extends SimpleFormTestCase
{
    private ?string $originalEdition = null;

    protected function tearDown(): void
    {
        if ($this->originalEdition !== null) {
            Plugin::getInstance()->edition = $this->originalEdition;
            $this->originalEdition = null;
        }
        parent::tearDown();
    }

    private function setSolo(): void
    {
        $plugin = Plugin::getInstance();
        $this->originalEdition ??= $plugin->edition;
        $plugin->edition = Editions::SOLO;
    }

    public function testFieldsControllerRejectsProFieldTypeOnSolo(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Gate Fields', 'gateFieldsForm');
        $formId = (int) $form->id;

        $this->setSolo();

        // A Pro field type is rejected and not persisted...
        $this->postFieldAdd($formId, 'rating', 'score', 'Score');
        $this->assertSame(0, $this->fieldCount($formId), 'a Pro field must not be addable on Solo');

        // ...while a core field type still adds.
        $this->postFieldAdd($formId, 'text', 'fullName', 'Full name');
        $this->assertSame(1, $this->fieldCount($formId));
    }

    public function testIntegrationSaveRejectsProTypeSwapOnSolo(): void
    {
        $this->requireCraft();
        $this->createForm('Gate Integrations', 'gateIntegrationsForm');

        // A Solo-allowed webhook integration (created while effectively Pro).
        $integration = new IntegrationModel();
        $integration->type = 'webhook';
        $integration->name = 'Ops hook';
        $integration->enabled = true;
        $integration->settings = ['url' => 'https://example.test/hook'];
        $this->assertTrue(Plugin::getInstance()->getIntegrations()->saveIntegration($integration));
        $id = (int) $integration->id;

        $this->setSolo();

        // Editing it and swapping the type to a Pro integration (slack) must be
        // rejected — the stored type stays webhook.
        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'integrationId' => $id,
            'type' => 'slack',
            'name' => 'Ops hook',
            'enabled' => '1',
            'settings' => ['webhookUrl' => 'https://hooks.slack.test/x'],
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new IntegrationsController('integrations', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $controller->actionSave();

        $stored = (string) (new Query())
            ->select(['type'])
            ->from('{{%simpleform_integrations}}')
            ->where(['id' => $id])
            ->scalar();
        $this->assertSame('webhook', $stored, 'a Solo site must not be able to swap an integration to a Pro type');
    }

    private function postFieldAdd(int $formId, string $type, string $handle, string $label): void
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formId' => $formId,
            'type' => $type,
            'handle' => $handle,
            'label' => $label,
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new FieldsController('fields', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $controller->actionAdd();
    }

    private function fieldCount(int $formId): int
    {
        return (int) (new Query())->from('{{%simpleform_fields}}')->where(['formId' => $formId])->count();
    }
}
