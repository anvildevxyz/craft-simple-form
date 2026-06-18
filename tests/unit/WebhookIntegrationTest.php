<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\integrations\WebhookIntegration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** A Webhook connector whose HTTP client is a Guzzle mock. */
class MockWebhookIntegration extends WebhookIntegration
{
    public function __construct(private MockHandler $mock)
    {
    }

    protected function httpClient(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->mock)]);
    }
}

class WebhookIntegrationTest extends TestCase
{
    public function testSignBodyIsDeterministicHmacSha256(): void
    {
        $expected = 'sha256=' . hash_hmac('sha256', '{"a":1}', 'topsecret');
        $this->assertSame($expected, WebhookIntegration::signBody('{"a":1}', 'topsecret'));
    }

    public function testTwoXxIsSuccess(): void
    {
        $webhook = new MockWebhookIntegration(new MockHandler([new Response(200, [], 'thanks')]));
        $result = $webhook->requestWebhook('POST', 'https://example.test/hook', '{}', 'application/json', null);

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->responseCode);
    }

    public function testServerErrorIsFailureWithCode(): void
    {
        $webhook = new MockWebhookIntegration(new MockHandler([new Response(500, [], 'boom')]));
        $result = $webhook->requestWebhook('POST', 'https://example.test/hook', '{}', 'application/json', null);

        $this->assertFalse($result->success);
        $this->assertSame(500, $result->responseCode);
        $this->assertStringContainsString('boom', $result->message);
    }

    public function testClientErrorIsFailure(): void
    {
        $webhook = new MockWebhookIntegration(new MockHandler([new Response(404, [], 'nope')]));
        $result = $webhook->requestWebhook('PUT', 'https://example.test/hook', '{}', 'application/json', null);

        $this->assertFalse($result->success);
        $this->assertSame(404, $result->responseCode);
    }

    public function testTransportExceptionIsFailureWithNullCode(): void
    {
        $mock = new MockHandler([
            new ConnectException('timed out', new Request('POST', 'https://example.test/hook')),
        ]);
        $webhook = new MockWebhookIntegration($mock);
        $result = $webhook->requestWebhook('POST', 'https://example.test/hook', '{}', 'application/json', null);

        $this->assertFalse($result->success);
        $this->assertNull($result->responseCode);
        $this->assertStringContainsString('timed out', $result->message);
    }

    public function testSignatureHeaderSentWhenSecretSet(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));

        $webhook = new class ($stack) extends WebhookIntegration {
            public function __construct(private HandlerStack $stack)
            {
            }

            protected function httpClient(): Client
            {
                return new Client(['handler' => $this->stack]);
            }
        };

        $webhook->requestWebhook('POST', 'https://example.test/hook', '{"x":1}', 'application/json', 'sekret');

        $this->assertCount(1, $history);
        /** @var Request $sent */
        $sent = $history[0]['request'];
        $this->assertSame(
            WebhookIntegration::signBody('{"x":1}', 'sekret'),
            $sent->getHeaderLine(WebhookIntegration::SIGNATURE_HEADER),
        );
    }
}
