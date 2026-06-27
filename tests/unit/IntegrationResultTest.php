<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\integrations\IntegrationResult;
use PHPUnit\Framework\TestCase;

class IntegrationResultTest extends TestCase
{
    public function testSuccessCarriesCodeAndMessage(): void
    {
        $r = IntegrationResult::success(200, 'ok');

        $this->assertTrue($r->success);
        $this->assertSame(200, $r->responseCode);
        $this->assertSame('ok', $r->message);
    }

    public function testFailureCarriesCodeAndMessage(): void
    {
        $r = IntegrationResult::failure(500, 'boom');

        $this->assertFalse($r->success);
        $this->assertSame(500, $r->responseCode);
        $this->assertSame('boom', $r->message);
    }

    public function testDefaultsAreEmpty(): void
    {
        $r = IntegrationResult::success();

        $this->assertNull($r->responseCode);
        $this->assertSame('', $r->message);
    }
}
