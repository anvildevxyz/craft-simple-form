<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\controllers\SubmitController;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Response;

/**
 * The front-end submit endpoint returns the per-form post-submit envelope:
 * `message` (per-form override, falling back to global) and `redirectUrl`
 * (null for the message action, the templated URL for the url action).
 *
 * @group requires-craft
 */
class SubmitPostSubmitEnvelopeTest extends SimpleFormTestCase
{
    /**
     * @param array<string, mixed> $extra extra body params keyed by `field_<id>`
     * @return array<string, mixed>
     */
    private function submit(string $handle, array $extra = []): array
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => $handle] + $extra);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new SubmitController('submit', Plugin::getInstance());
        $controller->enableCsrfValidation = false;

        $data = $controller->actionIndex()->data;
        return is_array($data) ? $data : [];
    }

    public function testMessageActionReturnsPerFormMessageAndNullRedirect(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Envelope Msg', 'env_message');
        $form->submitMessage = 'Thanks {firstName}!';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField($form->id, 'text', 'firstName', 'First Name', false);

        $data = $this->submit('env_message', ['field_' . $fieldId => 'Ada']);

        $this->assertTrue($data['success']);
        $this->assertSame('Thanks Ada!', $data['message']);
        $this->assertNull($data['redirectUrl']);
    }

    public function testUrlActionReturnsEncodedRedirect(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Envelope Url', 'env_url');
        $form->postSubmitAction = 'url';
        $form->redirectUrl = '/thanks?e={email}';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        $fieldId = $this->createField($form->id, 'email', 'email', 'Email', false);

        $data = $this->submit('env_url', ['field_' . $fieldId => 'ada@example.com']);

        $this->assertTrue($data['success']);
        $this->assertSame('/thanks?e=ada%40example.com', $data['redirectUrl']);
    }

    public function testBlankMessageFallsBackToGlobal(): void
    {
        $this->requireCraft();

        $global = Plugin::getInstance()->getSettings()->submitMessage;
        $form = $this->createForm('Envelope Fallback', 'env_fallback');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);

        $data = $this->submit('env_fallback', ['field_' . $fieldId => 'x']);

        $this->assertTrue($data['success']);
        $this->assertSame($global, $data['message']);
        $this->assertNull($data['redirectUrl']);
    }
}
