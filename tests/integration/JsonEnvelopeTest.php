<?php

namespace fabianhaef\simpleform\tests\integration;

use craft\web\Controller;
use fabianhaef\simpleform\controllers\SimpleFormControllerTrait;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

/**
 * #101 — every CP controller returns the same AJAX JSON envelope
 * `{ success, error?, errors? }` via the shared trait helpers. Exercises the
 * helpers through a throwaway controller so the contract is pinned regardless
 * of which action uses it.
 *
 * @group requires-craft
 */
class JsonEnvelopeTest extends SimpleFormTestCase
{
    private function controller(): Controller
    {
        return new class('test', Plugin::getInstance()) extends Controller {
            use SimpleFormControllerTrait;

            public function ok(array $data = []): Response
            {
                return $this->asJsonSuccess($data);
            }

            public function err(string $message): Response
            {
                return $this->asJsonError($message);
            }

            /** @param array<string, list<string>> $errors */
            public function errs(array $errors): Response
            {
                return $this->asJsonErrors($errors);
            }
        };
    }

    public function testSuccessEnvelope(): void
    {
        $this->requireCraft();
        $c = $this->controller();

        $this->assertSame(['success' => true], $c->ok()->data);
        // Domain data is merged alongside success: true.
        $this->assertSame(['success' => true, 'enabled' => false], $c->ok(['enabled' => false])->data);
    }

    public function testSingleErrorEnvelope(): void
    {
        $this->requireCraft();
        $data = $this->controller()->err('Nope.')->data;

        $this->assertFalse($data['success']);
        $this->assertSame('Nope.', $data['error']);
        $this->assertArrayNotHasKey('errors', $data, 'single-error responses do not also carry errors');
    }

    public function testValidationErrorsEnvelope(): void
    {
        $this->requireCraft();
        $errors = ['handle' => ['Handle is taken.']];
        $data = $this->controller()->errs($errors)->data;

        $this->assertFalse($data['success']);
        $this->assertSame($errors, $data['errors']);
        $this->assertArrayNotHasKey('error', $data, 'validation responses do not also carry a single error');
    }
}
