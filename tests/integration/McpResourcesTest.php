<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\web\Response;
use fabianhaef\simpleform\controllers\McpController;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\Plugin;

/**
 * Integration coverage for the #66 MCP resources: the form:// schema resource
 * (forms:manage) and the submissions:// dataset resource (submissions:read),
 * scope-aware listing, and deny-by-default reads.
 *
 * @group requires-craft
 */
class McpResourcesTest extends SimpleFormTestCase
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
    private function issueToken(array $scopes, string $label = 'Res client'): string
    {
        return Plugin::getInstance()->getMcpTokenManager()->createToken($label, $scopes)['secret'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params, string $bearer): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];

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

    /** @param array<string, mixed> $data */
    private function seedSubmission(int $formId, array $data, string $status = 'new'): Submission
    {
        $submission = new Submission();
        $submission->formId = $formId;
        $submission->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $submission->data = $data;
        $submission->readStatus = $status;
        $this->assertTrue(Craft::$app->getElements()->saveElement($submission), 'Submission should save');

        return $submission;
    }

    public function testInitializeAdvertisesResourcesCapability(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        $res = $this->rpc('initialize', [], $token);

        $this->assertArrayHasKey('resources', $res['result']['capabilities']);
        $this->assertFalse($res['result']['capabilities']['resources']['subscribe']);
        $this->assertFalse($res['result']['capabilities']['resources']['listChanged']);
    }

    public function testFormsManageTokenSeesFormResourceAndCanReadSchema(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $form = $this->createForm('Contact', 'contactForm');
        $this->createField((int)$form->id, 'text', 'fullName', 'Full Name', true);
        $this->createField((int)$form->id, 'email', 'email', 'Email', false);

        // List: form:// resource is present.
        $list = $this->rpc('resources/list', [], $token);
        $uris = array_column($list['result']['resources'], 'uri');
        $this->assertContains('form://contactForm', $uris);

        // Read: returns the schema, matching the tool-layer presenter.
        $read = $this->rpc('resources/read', ['uri' => 'form://contactForm'], $token);
        $this->assertArrayNotHasKey('error', $read);
        $contents = $read['result']['contents'][0];
        $this->assertSame('form://contactForm', $contents['uri']);
        $this->assertSame('application/json', $contents['mimeType']);

        $schema = json_decode($contents['text'], true);
        $this->assertSame('contactForm', $schema['handle']);
        $handles = array_column($schema['fields'], 'handle');
        $this->assertContains('fullName', $handles);
        $this->assertContains('email', $handles);
    }

    public function testSubmissionsResourceHiddenAndDeniedWithoutSubmissionsRead(): void
    {
        $this->requireCraft();
        // forms:manage only — NO submissions:read.
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $form = $this->createForm('Private', 'privateForm');
        $this->seedSubmission((int)$form->id, ['msg' => 'secret']);

        // Not listed (scope-aware visibility).
        $list = $this->rpc('resources/list', [], $token);
        $uris = array_column($list['result']['resources'], 'uri');
        $this->assertNotContains('submissions://privateForm', $uris);

        // Denied on read (deny-by-default, independent of listing).
        $read = $this->rpc('resources/read', ['uri' => 'submissions://privateForm'], $token);
        $this->assertArrayHasKey('error', $read);
        $this->assertSame(-32001, $read['error']['code']);
        $this->assertStringNotContainsString('submissions:read', $read['error']['message']);
    }

    public function testSubmissionsReadTokenSeesAndReadsDataset(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);
        $form = $this->createForm('Survey', 'surveyForm');
        $this->seedSubmission((int)$form->id, ['name' => 'Alice']);
        $this->seedSubmission((int)$form->id, ['name' => 'Bob']);

        // Listed.
        $list = $this->rpc('resources/list', [], $token);
        $uris = array_column($list['result']['resources'], 'uri');
        $this->assertContains('submissions://surveyForm', $uris);

        // Read: returns the dataset.
        $read = $this->rpc('resources/read', ['uri' => 'submissions://surveyForm'], $token);
        $this->assertArrayNotHasKey('error', $read);
        $payload = json_decode($read['result']['contents'][0]['text'], true);
        $this->assertSame('surveyForm', $payload['form']);
        $this->assertSame(2, $payload['total']);
        $names = array_column(array_column($payload['submissions'], 'data'), 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testReadingUnknownFormResourceIsAnError(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        $read = $this->rpc('resources/read', ['uri' => 'form://doesNotExist'], $token);

        $this->assertArrayHasKey('error', $read);
    }
}
