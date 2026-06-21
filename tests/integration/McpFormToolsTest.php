<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\web\Response;
use fabianhaef\simpleform\controllers\McpController;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\FormPresenter;
use fabianhaef\simpleform\Plugin;

/**
 * Integration coverage for the #65 form-management MCP tools. Each test drives
 * the real {@see McpController} with a forms:manage-scoped token and asserts the
 * effect via the element/DB layer — the same path a real MCP client exercises.
 *
 * @group requires-craft
 */
class McpFormToolsTest extends SimpleFormTestCase
{
    protected function _before(): void
    {
        parent::_before();
        if (class_exists(\Craft::class) && Craft::$app !== null) {
            $plugin = Plugin::getInstance();
            $values = $plugin->getSettings()->getAttributes();
            if (empty($values['defaultEmailSender'])) {
                $values['defaultEmailSender'] = 'test@example.com';
                Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
            }
            $values = $plugin->getSettings()->getAttributes();
            $values['enableMcp'] = true;
            Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
        }
    }

    /** @param list<string> $scopes */
    private function issueToken(array $scopes, string $label = 'Forms client'): string
    {
        return Plugin::getInstance()->getMcpTokenManager()->createToken($label, $scopes)['secret'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the tools/call result envelope
     */
    private function callTool(string $name, array $arguments, string $bearer): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ];

        $request = Craft::$app->getRequest();
        $request->setRawBody((string)json_encode($payload));
        $request->setBodyParams([]);
        $request->headers->set('Authorization', "Bearer $bearer");
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new McpController('mcp', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $response = $controller->actionIndex();

        return is_array($response->data) ? $response->data : [];
    }

    public function testCreateFormPersistsAndRoundTrips(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        $res = $this->callTool('create_form', [
            'name' => 'Agent Form',
            'handle' => 'agentForm',
            'title' => 'Agent Form Title',
            'emailTo' => 'ops@example.com',
        ], $token);

        $this->assertFalse($res['result']['isError']);
        $created = $res['result']['structuredContent']['form'];
        $this->assertSame('agentForm', $created['handle']);

        // Persisted via the element layer.
        $form = Form::find()->siteId('*')->handle('agentForm')->one();
        $this->assertInstanceOf(Form::class, $form);
        $this->assertSame('Agent Form', $form->name);

        // get_form round-trips it.
        $get = $this->callTool('get_form', ['handle' => 'agentForm'], $token);
        $this->assertFalse($get['result']['isError']);
        $this->assertSame((int)$form->id, $get['result']['structuredContent']['form']['id']);
        $this->assertSame('ops@example.com', $get['result']['structuredContent']['form']['emailTo']);
    }

    public function testInvalidCreateFormReturnsValidationErrors(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        // Missing required name/handle: must fail like the CP (in-band errors), not 500.
        $res = $this->callTool('create_form', ['title' => 'No name or handle'], $token);

        $this->assertTrue($res['result']['isError']);
        $errors = $res['result']['structuredContent']['errors'];
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('handle', $errors);
    }

    public function testDuplicateHandleReturnsValidationError(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $this->createForm('Existing', 'dupHandle');

        $res = $this->callTool('create_form', ['name' => 'Other', 'handle' => 'dupHandle'], $token);

        $this->assertTrue($res['result']['isError']);
        $this->assertArrayHasKey('handle', $res['result']['structuredContent']['errors']);
    }

    public function testUpdateFormPersists(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $form = $this->createForm('Before', 'updForm');

        $res = $this->callTool('update_form', [
            'id' => (int)$form->id,
            'name' => 'After',
            'emailSubject' => 'New subject',
        ], $token);

        $this->assertFalse($res['result']['isError']);

        $fresh = Form::find()->siteId('*')->id($form->id)->one();
        $this->assertSame('After', $fresh->name);
        $this->assertSame('New subject', $fresh->emailSubject);
    }

    public function testAddUpdateReorderDeleteField(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $form = $this->createForm('Field Form', 'fieldForm');
        $formId = (int)$form->id;

        // add_field
        $add1 = $this->callTool('add_field', [
            'formId' => $formId,
            'type' => 'text',
            'handle' => 'firstName',
            'label' => 'First Name',
            'required' => true,
        ], $token);
        $this->assertFalse($add1['result']['isError']);
        $fieldId1 = $add1['result']['structuredContent']['fieldId'];

        $add2 = $this->callTool('add_field', [
            'formId' => $formId,
            'type' => 'email',
            'handle' => 'emailAddr',
            'label' => 'Email',
        ], $token);
        $fieldId2 = $add2['result']['structuredContent']['fieldId'];

        $this->assertSame(2, $this->fieldCount($formId));

        // update_field
        $upd = $this->callTool('update_field', [
            'fieldId' => $fieldId1,
            'label' => 'Given Name',
            'required' => false,
        ], $token);
        $this->assertFalse($upd['result']['isError']);
        $row = (new Query())->from('{{%simpleform_fields}}')->where(['id' => $fieldId1])->one();
        $this->assertSame(0, (int)$row['required']);

        // reorder_fields (swap order)
        $reorder = $this->callTool('reorder_fields', [
            'formId' => $formId,
            'fieldIds' => [$fieldId2, $fieldId1],
        ], $token);
        $this->assertFalse($reorder['result']['isError']);
        $sort2 = (new Query())->select(['sortOrder'])->from('{{%simpleform_fields}}')->where(['id' => $fieldId2])->scalar();
        $sort1 = (new Query())->select(['sortOrder'])->from('{{%simpleform_fields}}')->where(['id' => $fieldId1])->scalar();
        $this->assertLessThan((int)$sort1, (int)$sort2);

        // delete_field requires confirm
        $refused = $this->callTool('delete_field', ['fieldId' => $fieldId2], $token);
        $this->assertTrue($refused['result']['isError']);
        $this->assertSame(2, $this->fieldCount($formId));

        $deleted = $this->callTool('delete_field', ['fieldId' => $fieldId2, 'confirm' => true], $token);
        $this->assertFalse($deleted['result']['isError']);
        $this->assertSame(1, $this->fieldCount($formId));
    }

    public function testAddFieldInvalidTypeReturnsValidationError(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $form = $this->createForm('Bad Field', 'badFieldForm');

        // select field with no options must fail validation (parity with the CP).
        $res = $this->callTool('add_field', [
            'formId' => (int)$form->id,
            'type' => 'select',
            'handle' => 'choice',
            'label' => 'Choice',
            'config' => [],
        ], $token);

        $this->assertTrue($res['result']['isError']);
        $this->assertArrayHasKey('config', $res['result']['structuredContent']['errors']);
    }

    public function testDeleteFormRequiresConfirm(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $form = $this->createForm('Doomed', 'doomedForm');
        $formId = (int)$form->id;

        // Without confirm: refused, form still exists.
        $refused = $this->callTool('delete_form', ['id' => $formId], $token);
        $this->assertTrue($refused['result']['isError']);
        $this->assertNotNull(Form::find()->siteId('*')->id($formId)->one());

        // With confirm: deleted.
        $deleted = $this->callTool('delete_form', ['id' => $formId, 'confirm' => true], $token);
        $this->assertFalse($deleted['result']['isError']);
        $this->assertNull(Form::find()->siteId('*')->id($formId)->one());
    }

    public function testResolveByIdOrHandleFindsFormById(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Resolve By Id', 'resolveById');

        $resolved = FormPresenter::resolveByIdOrHandle(['id' => (int)$form->id]);

        $this->assertInstanceOf(Form::class, $resolved);
        $this->assertSame((int)$form->id, (int)$resolved->id);
    }

    public function testResolveByIdOrHandleFindsFormByHandle(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Resolve By Handle', 'resolveByHandle');

        $resolved = FormPresenter::resolveByIdOrHandle(['handle' => 'resolveByHandle']);

        $this->assertInstanceOf(Form::class, $resolved);
        $this->assertSame((int)$form->id, (int)$resolved->id);
    }

    public function testResolveByIdOrHandleReturnsErrorWhenNeitherGiven(): void
    {
        $this->requireCraft();

        $result = FormPresenter::resolveByIdOrHandle([]);

        $this->assertSame(['isError' => true, 'error' => 'Provide either "id" or "handle".'], $result);
    }

    public function testResolveByIdOrHandleReturnsNotFoundError(): void
    {
        $this->requireCraft();

        $result = FormPresenter::resolveByIdOrHandle(['handle' => 'definitelyMissingForm']);

        $this->assertSame(['isError' => true, 'error' => 'Form not found.'], $result);
    }

    public function testTokenWithoutFormsManageIsRejected(): void
    {
        $this->requireCraft();
        // A submissions-only token must not be able to manage forms.
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);

        $res = $this->callTool('create_form', ['name' => 'Nope', 'handle' => 'nopeForm'], $token);

        $this->assertArrayHasKey('error', $res);
        $this->assertSame(-32001, $res['error']['code']);
        $this->assertNull(Form::find()->siteId('*')->handle('nopeForm')->one());
    }

    private function fieldCount(int $formId): int
    {
        return (int)(new Query())->from('{{%simpleform_fields}}')->where(['formId' => $formId])->count();
    }
}
