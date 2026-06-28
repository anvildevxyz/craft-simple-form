<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;

/**
 * UTM/referrer auto-capture (#249): the opted-in form renders the hidden
 * __sf_attr inputs, the posted attribution is sanitized + stored on the
 * submission, and a non-opted form ignores a forged attribution POST.
 *
 * @group requires-craft
 */
class AttributionCaptureTest extends SimpleFormTestCase
{
    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }

    private function attributionForm(string $handle, bool $capture): Form
    {
        $form = $this->createForm('Lead', $handle, 'Lead');
        $form->autoCaptureAttribution = $capture;
        Craft::$app->getElements()->saveElement($form);
        $this->createField((int) $form->id, 'text', 'name', 'Name');
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);
        return $form;
    }

    public function testHiddenInputsRenderOnlyWhenEnabled(): void
    {
        $this->requireCraft();

        $this->attributionForm('attr_render_on', true);
        $on = Plugin::getInstance()->getFormRender()->renderForm('attr_render_on');
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer', 'landing_page'] as $key) {
            $this->assertStringContainsString('name="__sf_attr[' . $key . ']"', $on, "expected hidden input for $key");
        }
        $this->assertStringContainsString('data-sf-attr="utm_source"', $on);

        $this->attributionForm('attr_render_off', false);
        $off = Plugin::getInstance()->getFormRender()->renderForm('attr_render_off');
        $this->assertStringNotContainsString('__sf_attr[', $off);
    }

    public function testAttributionCapturedAndSanitizedOnSubmit(): void
    {
        $this->requireCraft();

        $form = $this->attributionForm('attr_capture', true);
        $fieldId = (int) (new \craft\db\Query())
            ->select(['id'])->from('{{%simpleform_fields}}')->where(['formId' => $form->id])->scalar();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'attr_capture',
            'field_' . $fieldId => 'Ada',
            '__sf_attr' => [
                'utm_source' => '  google  ',                 // trimmed
                'utm_medium' => "cpc\x00",                     // control char stripped
                'utm_campaign' => '',                          // empty dropped
                'utm_content' => str_repeat('x', 400),         // length-bounded to 255
                'referrer' => 'https://example.com/landing',
                'landing_page' => 'https://site.test/form?utm_source=google',
                'bogus' => 'ignored',                          // unknown key dropped
            ],
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertNull($result['errors']);
        $submission = $result['submission'];
        $this->assertInstanceOf(Submission::class, $submission);

        $attr = $submission->attribution;
        $this->assertIsArray($attr);
        $this->assertSame('google', $attr['utm_source']);
        $this->assertSame('cpc', $attr['utm_medium']);
        $this->assertArrayNotHasKey('utm_campaign', $attr, 'empty value dropped');
        $this->assertSame(255, strlen($attr['utm_content']), 'bounded to 255');
        $this->assertSame('https://example.com/landing', $attr['referrer']);
        $this->assertArrayNotHasKey('bogus', $attr, 'unknown key dropped');
    }

    public function testForgedAttributionIgnoredWhenFormNotOptedIn(): void
    {
        $this->requireCraft();

        $form = $this->attributionForm('attr_noopt', false);
        $fieldId = (int) (new \craft\db\Query())
            ->select(['id'])->from('{{%simpleform_fields}}')->where(['formId' => $form->id])->scalar();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'attr_noopt',
            'field_' . $fieldId => 'Ada',
            '__sf_attr' => ['utm_source' => 'injected'],
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertInstanceOf(Submission::class, $result['submission']);
        $this->assertNull($result['submission']->attribution, 'attribution not stored for a non-opted form');
    }
}
