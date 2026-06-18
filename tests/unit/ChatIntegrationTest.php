<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\integrations\AbstractChatIntegration;
use fabianhaef\simpleform\integrations\SlackIntegration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** Chat connector with a mocked HTTP client + a public seam onto post(). */
class MockSlackIntegration extends SlackIntegration
{
    public function __construct(private MockHandler $mock)
    {
    }

    protected function httpClient(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->mock)]);
    }

    /** @param array<string, mixed> $payload */
    public function postPublic(string $url, array $payload): \fabianhaef\simpleform\integrations\IntegrationResult
    {
        return $this->post($url, $payload);
    }
}

class ChatIntegrationTest extends TestCase
{
    public function testApplyTemplateReplacesHandles(): void
    {
        $out = AbstractChatIntegration::applyTemplate('{name} from {company}', [
            'name' => 'Ada',
            'company' => 'Analytical',
        ]);
        $this->assertSame('Ada from Analytical', $out);
    }

    public function testApplyTemplateJoinsArraysAndBlanksUnknown(): void
    {
        $out = AbstractChatIntegration::applyTemplate('{colors} / {missing}', [
            'colors' => ['red', 'blue'],
        ]);
        $this->assertSame('red, blue / ', $out);
    }

    public function testTransportSuccess(): void
    {
        $slack = new MockSlackIntegration(new MockHandler([new Response(200, [], 'ok')]));
        $result = $slack->postPublic('https://hooks.slack.test/x', ['text' => 'hi']);
        $this->assertTrue($result->success);
        $this->assertSame(200, $result->responseCode);
    }

    public function testTransportServerErrorIsFailure(): void
    {
        $slack = new MockSlackIntegration(new MockHandler([new Response(500, [], 'boom')]));
        $result = $slack->postPublic('https://hooks.slack.test/x', ['text' => 'hi']);
        $this->assertFalse($result->success);
        $this->assertSame(500, $result->responseCode);
    }

    public function testTransportConnectExceptionIsFailure(): void
    {
        $mock = new MockHandler([
            new ConnectException('refused', new Request('POST', 'https://hooks.slack.test/x')),
        ]);
        $slack = new MockSlackIntegration($mock);
        $result = $slack->postPublic('https://hooks.slack.test/x', ['text' => 'hi']);
        $this->assertFalse($result->success);
        $this->assertNull($result->responseCode);
        $this->assertStringContainsString('refused', $result->message);
    }
}
