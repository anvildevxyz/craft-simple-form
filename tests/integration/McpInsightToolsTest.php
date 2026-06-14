<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\web\Response;
use fabianhaef\simpleform\controllers\McpController;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\Plugin;

/**
 * Integration coverage for the #67 AI-insight tools: summarize_submissions,
 * categorize_submissions, detect_spam_patterns — their shaped output over a
 * seeded dataset and the submissions:read scope gate.
 *
 * @group requires-craft
 */
class McpInsightToolsTest extends SimpleFormTestCase
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
    private function issueToken(array $scopes, string $label = 'Insight client'): string
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

    public function testSummarizeReturnsFreeTextCorpus(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);
        $form = $this->createForm('Feedback', 'feedbackForm');
        $this->createField((int)$form->id, 'textarea', 'comment', 'Comment', true);
        $this->createField((int)$form->id, 'select', 'rating', 'Rating', false);

        $this->seedSubmission((int)$form->id, ['comment' => 'Loved the product', 'rating' => '5']);
        $this->seedSubmission((int)$form->id, ['comment' => 'Shipping was slow', 'rating' => '2']);

        $res = $this->callTool('summarize_submissions', ['form' => 'feedbackForm'], $token);

        $this->assertFalse($res['result']['isError']);
        $out = $res['result']['structuredContent'];
        $this->assertSame(2, $out['count']);
        // Only the free-text field is in the corpus, not the select.
        $this->assertContains('comment', $out['fields']);
        $this->assertNotContains('rating', $out['fields']);
        $texts = array_column($out['corpus'], 'text');
        $this->assertContains('Loved the product', $texts);
        $this->assertContains('Shipping was slow', $texts);
    }

    public function testCategorizeGroupsByOptionField(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);
        $form = $this->createForm('Topics', 'topicsForm');
        $this->createField((int)$form->id, 'select', 'topic', 'Topic', true);
        $this->createField((int)$form->id, 'textarea', 'detail', 'Detail', false);

        $this->seedSubmission((int)$form->id, ['topic' => 'billing', 'detail' => 'Charged twice']);
        $this->seedSubmission((int)$form->id, ['topic' => 'billing', 'detail' => 'Refund please']);
        $this->seedSubmission((int)$form->id, ['topic' => 'support', 'detail' => 'How do I reset?']);

        $res = $this->callTool('categorize_submissions', ['form' => 'topicsForm'], $token);

        $this->assertFalse($res['result']['isError']);
        $out = $res['result']['structuredContent'];
        $this->assertSame('topic', $out['groupBy']);
        $this->assertSame(3, $out['count']);

        $groups = array_column($out['groups'], 'count', 'value');
        $this->assertSame(2, $groups['billing']);
        $this->assertSame(1, $groups['support']);
        // The free-text corpus is returned alongside the grouping signal.
        $this->assertContains('detail', $out['textFields']);
    }

    public function testDetectSpamFlagsDuplicateAndLinks(): void
    {
        $this->requireCraft();
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);
        $form = $this->createForm('Contact', 'contactSpamForm');
        $this->createField((int)$form->id, 'textarea', 'message', 'Message', true);

        // Two identical bodies → duplicateContent on both.
        $this->seedSubmission((int)$form->id, ['message' => 'Buy now cheap deals']);
        $this->seedSubmission((int)$form->id, ['message' => 'Buy now cheap deals']);
        // Many links → excessiveLinks.
        $this->seedSubmission((int)$form->id, [
            'message' => 'Visit https://a.com and https://b.com and https://c.com and www.d.com',
        ]);
        // Clean, unique submission → not flagged.
        $this->seedSubmission((int)$form->id, ['message' => 'Hello, I have a question about your hours.']);

        $res = $this->callTool('detect_spam_patterns', ['form' => 'contactSpamForm'], $token);

        $this->assertFalse($res['result']['isError']);
        $out = $res['result']['structuredContent'];
        $this->assertSame(4, $out['scanned']);

        $bySignal = [];
        foreach ($out['flagged'] as $row) {
            foreach ($row['signals'] as $sig) {
                $bySignal[$sig] = ($bySignal[$sig] ?? 0) + 1;
            }
        }
        $this->assertSame(2, $bySignal['duplicateContent'], 'both duplicate rows flagged');
        $this->assertArrayHasKey('excessiveLinks', $bySignal);
        // The clean submission is not flagged.
        $this->assertSame(3, $out['flaggedCount']);
    }

    public function testAllInsightToolsRequireSubmissionsRead(): void
    {
        $this->requireCraft();
        // forms:manage only — must be rejected for every insight tool.
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);
        $form = $this->createForm('Guarded', 'guardedForm');
        $this->seedSubmission((int)$form->id, ['x' => 'y']);

        foreach (['summarize_submissions', 'categorize_submissions', 'detect_spam_patterns'] as $tool) {
            $res = $this->callTool($tool, ['form' => 'guardedForm'], $token);
            $this->assertArrayHasKey('error', $res, "$tool must reject a forms:manage token");
            $this->assertSame(-32001, $res['error']['code'], "$tool must be a scope error");
        }
    }
}
