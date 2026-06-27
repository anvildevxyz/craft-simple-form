<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\controllers\SubmitController;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Response;

/**
 * The front-end submit endpoint throttles per visitor IP per minute when
 * submitRateLimitPerMinute is set (abuse hardening). 0 (the default) disables it.
 *
 * @group requires-craft
 */
class SubmitRateLimitTest extends SimpleFormTestCase
{
    private function submit(string $handle, int $nameFieldId): int
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => $handle, 'field_' . $nameFieldId => 'Ada']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new SubmitController('submit', Plugin::getInstance());
        $controller->enableCsrfValidation = false;

        return $controller->actionIndex()->getStatusCode();
    }

    public function testSubmitIsRateLimitedPerIp(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Rate Limited', 'rate_limited');
        $nameId = $this->createField($form->id, 'text', 'name', 'Name');

        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->submitRateLimitPerMinute;
        $settings->submitRateLimitPerMinute = 2;
        Craft::$app->getCache()->flush();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';

        try {
            // First two within the minute window are accepted...
            $this->assertSame(200, $this->submit('rate_limited', $nameId));
            $this->assertSame(200, $this->submit('rate_limited', $nameId));
            // ...the third trips the limit.
            $this->assertSame(429, $this->submit('rate_limited', $nameId));
        } finally {
            $settings->submitRateLimitPerMinute = $original;
            Craft::$app->getCache()->flush();
        }
    }

    public function testLimitDisabledByDefault(): void
    {
        $this->requireCraft();

        $form = $this->createForm('No Limit', 'no_limit');
        $nameId = $this->createField($form->id, 'text', 'name', 'Name');

        // Default submitRateLimitPerMinute is 0 → no throttle even on a burst.
        $this->assertSame(0, Plugin::getInstance()->getSettings()->submitRateLimitPerMinute);
        Craft::$app->getCache()->flush();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->submit('no_limit', $nameId));
        }
    }
}
