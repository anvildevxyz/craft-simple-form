<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\web\Response;
use fabianhaef\simpleform\controllers\McpController;
use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\SubmissionQueryBuilder;
use fabianhaef\simpleform\Plugin;

/**
 * Integration coverage for the #64 submission MCP tools and their scope
 * isolation: query/get/export/stats, and the privacy boundary that a
 * forms:manage-only token can neither read nor export submissions.
 *
 * @group requires-craft
 */
class McpSubmissionToolsTest extends SimpleFormTestCase
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
    private function issueToken(array $scopes, string $label = 'Sub client'): string
    {
        return Plugin::getInstance()->getMcpTokenManager()->createToken($label, $scopes)['secret'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
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

    /**
     * Seed a submission element directly.
     *
     * @param array<string, mixed> $data
     */
    private function seedSubmission(int $formId, array $data, string $status = 'new', ?string $dateCreated = null): Submission
    {
        $submission = new Submission();
        $submission->formId = $formId;
        $submission->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $submission->data = $data;
        $submission->readStatus = $status;

        $saved = Craft::$app->getElements()->saveElement($submission);
        $this->assertTrue($saved, 'Submission should save');

        if ($dateCreated !== null) {
            // Backdate for the per-day stats / date-range checks.
            Craft::$app->getDb()->createCommand()->update(
                '{{%elements}}',
                ['dateCreated' => $dateCreated],
                ['id' => $submission->id]
            )->execute();
        }

        return $submission;
    }

    public function testQuerySubmissionsFiltersAndPaging(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);

        $formA = $this->createForm('Form A', 'formA');
        $formB = $this->createForm('Form B', 'formB');
        $this->seedSubmission((int)$formA->id, ['name' => 'Alice'], 'new');
        $this->seedSubmission((int)$formA->id, ['name' => 'Bob'], 'read');
        $this->seedSubmission((int)$formB->id, ['name' => 'Carol'], 'new');

        // Filter by form handle.
        $byForm = $this->callTool('query_submissions', ['form' => 'formA'], $token);
        $this->assertFalse($byForm['result']['isError']);
        $this->assertSame(2, $byForm['result']['structuredContent']['total']);

        // Filter by status.
        $byStatus = $this->callTool('query_submissions', ['status' => 'new'], $token);
        $this->assertSame(2, $byStatus['result']['structuredContent']['total']);

        // Field-value match.
        $byField = $this->callTool('query_submissions', ['fieldMatch' => ['name' => 'Alice']], $token);
        $this->assertSame(1, $byField['result']['structuredContent']['total']);

        // Paging.
        $paged = $this->callTool('query_submissions', ['form' => 'formA', 'limit' => 1, 'offset' => 0], $token);
        $this->assertSame(2, $paged['result']['structuredContent']['total']);
        $this->assertCount(1, $paged['result']['structuredContent']['submissions']);
    }

    public function testGetSubmissionReturnsDetail(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);
        $form = $this->createForm('Detail Form', 'detailForm');
        $sub = $this->seedSubmission((int)$form->id, ['email' => 'x@y.com']);

        $res = $this->callTool('get_submission', ['id' => (int)$sub->id], $token);

        $this->assertFalse($res['result']['isError']);
        $detail = $res['result']['structuredContent']['submission'];
        $this->assertSame((int)$sub->id, $detail['id']);
        $this->assertSame('detailForm', $detail['formHandle']);
        $this->assertSame('x@y.com', $detail['data']['email']);
    }

    public function testExportSubmissionsCsvAndJson(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ, Scopes::SUBMISSIONS_EXPORT]);
        $form = $this->createForm('Export Form', 'exportForm');
        $this->seedSubmission((int)$form->id, ['name' => 'Alice', 'age' => '30']);
        $this->seedSubmission((int)$form->id, ['name' => 'Bob', 'age' => '25']);

        // CSV
        $csv = $this->callTool('export_submissions', ['form' => 'exportForm', 'format' => 'csv'], $token);
        $this->assertFalse($csv['result']['isError']);
        $content = $csv['result']['structuredContent']['content'];
        $this->assertSame(2, $csv['result']['structuredContent']['count']);
        $this->assertStringContainsString('name', $content);
        $this->assertStringContainsString('Alice', $content);
        $this->assertStringContainsString('Bob', $content);

        // JSON
        $json = $this->callTool('export_submissions', ['form' => 'exportForm', 'format' => 'json'], $token);
        $this->assertFalse($json['result']['isError']);
        $decoded = json_decode($json['result']['structuredContent']['content'], true);
        $this->assertCount(2, $decoded);
        $names = array_column(array_column($decoded, 'data'), 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testSubmissionStats(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);
        $form = $this->createForm('Stats Form', 'statsForm');
        $this->seedSubmission((int)$form->id, ['x' => '1'], 'new', '2026-01-01 10:00:00');
        $this->seedSubmission((int)$form->id, ['x' => '2'], 'new', '2026-01-01 12:00:00');
        $this->seedSubmission((int)$form->id, ['x' => '3'], 'read', '2026-01-02 09:00:00');

        $res = $this->callTool('submission_stats', ['form' => 'statsForm'], $token);

        $this->assertFalse($res['result']['isError']);
        $stats = $res['result']['structuredContent'];
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['perStatus']['new']);
        $this->assertSame(1, $stats['perStatus']['read']);
        $this->assertSame(3, $stats['perForm']['statsForm']);
        $this->assertSame(2, $stats['perDay']['2026-01-01']);
        $this->assertSame(1, $stats['perDay']['2026-01-02']);
    }

    public function testFormsManageTokenCannotReadSubmissions(): void
    {
        $this->requireCraft();
        // SCOPE ISOLATION (privacy): a forms-only token must be rejected for the
        // submission read tools.
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $form = $this->createForm('Private Form', 'privateForm');
        $this->seedSubmission((int)$form->id, ['secret' => 'value']);

        foreach (['query_submissions', 'get_submission', 'submission_stats'] as $tool) {
            $res = $this->callTool($tool, ['form' => 'privateForm', 'id' => 1], $token);
            $this->assertArrayHasKey('error', $res, "$tool should reject a forms:manage token");
            $this->assertSame(-32001, $res['error']['code'], "$tool should be a scope error");
        }
    }

    public function testFormsManageTokenCannotExportSubmissions(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        $res = $this->callTool('export_submissions', ['format' => 'csv'], $token);

        $this->assertArrayHasKey('error', $res);
        $this->assertSame(-32001, $res['error']['code']);
    }

    public function testExportRequiresExportScopeSpecifically(): void
    {
        $this->requireCraft();
        // A submissions:read token can query/get/stats but NOT export.
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);

        $res = $this->callTool('export_submissions', ['format' => 'csv'], $token);

        $this->assertArrayHasKey('error', $res);
        $this->assertSame(-32001, $res['error']['code']);
    }

    public function testBuildWithFormEagerLoadsTheForm(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Build With Form', 'buildWithForm');
        $this->seedSubmission((int)$form->id, ['name' => 'Dana']);

        $query = SubmissionQueryBuilder::buildWithForm(['formId' => (int)$form->id]);
        $this->assertInstanceOf(SubmissionQuery::class, $query);
        $this->assertContains('form', $query->with ?? []);

        $submissions = $query->all();
        $this->assertCount(1, $submissions);
        // The eager-load means the related form is resolved without another query.
        $this->assertInstanceOf(Form::class, $submissions[0]->getForm());
    }

    public function testBuildWithFormReturnsErrorPayloadForUnknownForm(): void
    {
        $this->requireCraft();

        $result = SubmissionQueryBuilder::buildWithForm(['form' => 'noSuchFormHandle']);

        $this->assertSame(
            ['isError' => true, 'error' => 'Form not found: noSuchFormHandle'],
            $result,
        );
    }
}
