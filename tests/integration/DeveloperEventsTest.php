<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\test\TestMailer;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\events\BeforeIntegrationDispatchEvent;
use fabianhaef\simpleform\events\BeforeSendNotificationEvent;
use fabianhaef\simpleform\events\BeforeValidateSubmissionEvent;
use fabianhaef\simpleform\events\DefineFieldSetEvent;
use fabianhaef\simpleform\events\ModifyRenderContextEvent;
use fabianhaef\simpleform\events\RegisterFieldTypesEvent;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\FieldTypeRegistry;
use fabianhaef\simpleform\tests\fixtures\StubFieldType;
use yii\base\Event;
use yii\mail\MessageInterface;

/**
 * #219 — the developer extension surface: the EVENT_REGISTER_FIELD_TYPES
 * registration event and the five lifecycle seam events (define-field-set,
 * modify-render-context, before-validate, before-send-notification,
 * before-integration-dispatch). Each test attaches a handler, exercises the
 * code path, and removes the handler in a finally so it never leaks.
 *
 * @group requires-craft
 */
class DeveloperEventsTest extends SimpleFormTestCase
{
    public function testRegisterFieldTypesEvent(): void
    {
        $this->requireCraft();

        $handler = static function (RegisterFieldTypesEvent $e): void {
            $e->types[] = StubFieldType::class;
        };
        Event::on(Plugin::class, Plugin::EVENT_REGISTER_FIELD_TYPES, $handler);

        try {
            // A fresh registry runs init() with the handler attached.
            $registry = new FieldTypeRegistry();
            $this->assertContains('stub_dx', $registry->typeHandles());
            $this->assertInstanceOf(StubFieldType::class, $registry->getFieldType('stub_dx'));
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_REGISTER_FIELD_TYPES, $handler);
        }
    }

    public function testDefineFieldSetEvent(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Define Fields', 'defineFieldsForm');
        $this->createField($form->id, 'text', 'keep', 'Keep');
        $this->createField($form->id, 'text', 'drop', 'Drop');

        $handler = static function (DefineFieldSetEvent $e): void {
            $e->fields = array_filter($e->fields, static fn(array $row): bool => $row['name'] !== 'drop');
        };
        Event::on(Plugin::class, Plugin::EVENT_DEFINE_FIELD_SET, $handler);

        try {
            $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id);
            $names = array_column($fields, 'name');
            $this->assertContains('keep', $names);
            $this->assertNotContains('drop', $names);
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_DEFINE_FIELD_SET, $handler);
        }
    }

    public function testModifyRenderContextEvent(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Modify Context', 'modifyContextForm');
        $this->createField($form->id, 'text', 'name', 'Name');

        $handler = static function (ModifyRenderContextEvent $e): void {
            $e->context['injectedByEvent'] = 'yes';
        };
        Event::on(Plugin::class, Plugin::EVENT_MODIFY_RENDER_CONTEXT, $handler);

        try {
            $context = Plugin::getInstance()->getFormRender()->buildContext($form);
            $this->assertSame('yes', $context['injectedByEvent'] ?? null);
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_MODIFY_RENDER_CONTEXT, $handler);
        }
    }

    public function testBeforeValidateEventRewritesValues(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Before Validate', 'beforeValidateForm');
        $fieldId = $this->createField($form->id, 'text', 'shout', 'Shout', true);

        $handler = static function (BeforeValidateSubmissionEvent $e): void {
            if (isset($e->valuesByHandle['shout'])) {
                $e->valuesByHandle['shout'] = strtolower((string) $e->valuesByHandle['shout']);
            }
        };
        Event::on(Plugin::class, Plugin::EVENT_BEFORE_VALIDATE, $handler);

        try {
            $result = Plugin::getInstance()->getSubmissionService()->submit(
                $form,
                ['field_' . $fieldId => 'HELLO WORLD'],
                ['skipCaptcha' => true],
            );
            $this->assertNotNull($result['submission']);
            $this->assertSame('hello world', $result['submission']->data['field_' . $fieldId]['value']);
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_BEFORE_VALIDATE, $handler);
        }
    }

    public function testBeforeSendNotificationCanSuppress(): void
    {
        $this->requireCraft();

        $form = $this->createForm(
            'Suppress Notify',
            'suppressNotifyForm',
            emailTo: 'owner@example.com',
            emailSubject: 'Hello',
        );
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', true);
        $reloaded = \fabianhaef\simpleform\elements\Form::find()->id($form->id)->one();

        $handler = static function (BeforeSendNotificationEvent $e): void {
            $e->send = false;
        };
        Event::on(Plugin::class, Plugin::EVENT_BEFORE_SEND_NOTIFICATION, $handler);

        try {
            $sent = $this->captureSentMessages(function () use ($reloaded, $fieldId): void {
                $submission = new Submission();
                $submission->formId = (int) $reloaded->id;
                $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
                $data = ['field_' . $fieldId => ['label' => 'Name', 'type' => 'text', 'value' => 'Ada']];
                $submission->data = $data;
                Craft::$app->getElements()->saveElement($submission);
                Plugin::getInstance()->getEmailService()->sendSubmissionEmail($reloaded, $submission, $data);
            });
            $this->assertCount(0, $sent, 'A suppressed notification must not be sent');
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_BEFORE_SEND_NOTIFICATION, $handler);
        }
    }

    public function testBeforeIntegrationDispatchCanSkip(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Skip Dispatch', 'skipDispatchForm');

        $integration = new IntegrationModel();
        $integration->type = 'webhook';
        $integration->name = 'Ops hook';
        $integration->enabled = true;
        // An unreachable URL: if the dispatch were NOT skipped it would fail, so a
        // success result proves the skip path ran instead of a real send.
        $integration->settings = ['url' => 'https://0.0.0.0/never'];
        $this->assertTrue(Plugin::getInstance()->getIntegrations()->saveIntegration($integration));

        $submission = new Submission();
        $submission->formId = (int) $form->id;
        $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission->data = [];
        $this->assertTrue(Craft::$app->getElements()->saveElement($submission));

        $handler = static function (BeforeIntegrationDispatchEvent $e): void {
            $e->send = false;
        };
        Event::on(Plugin::class, Plugin::EVENT_BEFORE_INTEGRATION_DISPATCH, $handler);

        try {
            $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $submission);
            $this->assertTrue($result->success, 'A skipped dispatch is a successful no-op');
            $this->assertStringContainsString('Skipped', $result->message);
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_BEFORE_INTEGRATION_DISPATCH, $handler);
        }
    }

    /**
     * @param callable(): void $work
     * @return list<MessageInterface>
     */
    private function captureSentMessages(callable $work): array
    {
        $mailer = Craft::$app->getMailer();
        $collected = [];

        if ($mailer instanceof TestMailer) {
            $original = $mailer->callback;
            $mailer->callback = function (MessageInterface $message) use (&$collected, $original): void {
                $collected[] = $message;
                if (is_callable($original)) {
                    $original($message);
                }
            };
            try {
                $work();
            } finally {
                $mailer->callback = $original;
            }
        } else {
            $work();
        }

        return $collected;
    }
}
