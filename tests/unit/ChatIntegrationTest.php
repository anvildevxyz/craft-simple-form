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

    /**
     * SSRF guard (F3): a loopback/private URL must be blocked by the consolidated
     * {@see \fabianhaef\simpleform\integrations\support\ApiConnector::request()}
     * path before any HTTP call is made. The mock has no queued response, so a
     * request reaching the client would throw — proving the guard short-circuits.
     *
     * @dataProvider blockedUrlProvider
     */
    public function testSsrfBlockedUrlIsRejectedWithoutCall(string $url): void
    {
        $slack = new MockSlackIntegration(new MockHandler([])); // no responses queued
        $result = $slack->postPublic($url, ['text' => 'hi']);

        $this->assertFalse($result->success);
        $this->assertNull($result->responseCode);
        $this->assertSame('Blocked request to a non-public address', $result->message);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrlProvider(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/hook'];
        yield 'localhost' => ['http://localhost/hook'];
        yield 'private rfc1918' => ['https://10.0.0.5/hook'];
        yield 'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'non-http scheme' => ['file:///etc/passwd'];
    }
}
