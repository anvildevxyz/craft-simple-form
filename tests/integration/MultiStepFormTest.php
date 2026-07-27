<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\Plugin;
use anvildev\simpleform\TwigExtension;
use Craft;
use craft\db\Query;

/**
 * #90 — multi-step forms: fields render grouped into ordered step containers,
 * single-page forms are unchanged, and a multi-step submit still creates one
 * submission with every field.
 *
 * @group requires-craft
 */
class MultiStepFormTest extends SimpleFormTestCase
{
    public function testSinglePageHasNoStepContainers(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Single', 'single_step');
        $this->createField($form->id, 'text', 'name', 'Name');

        $html = (new TwigExtension())->renderForm('single_step');
        $this->assertStringNotContainsString('simple-form-step', $html);
        $this->assertStringContainsString('simple-form-submit-btn', $html);
    }

    public function testMultiPageRendersStepsAndNav(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Multi', 'multi_step');
        $this->createField($form->id, 'text', 'name', 'Name', false, ['page' => 1]);
        $this->createField($form->id, 'email', 'email', 'Email', false, ['page' => 2]);

        $html = (new TwigExtension())->renderForm('multi_step');

        $this->assertStringContainsString('data-sf-step="0"', $html);
        $this->assertStringContainsString('data-sf-step="1"', $html);
        $this->assertStringContainsString('data-sf-multistep="2"', $html);
        $this->assertStringContainsString('simple-form-step-next', $html);
        $this->assertStringContainsString('simple-form-step-back', $html);
    }

    public function testMultiStepSubmitCreatesOneSubmission(): void
    {
        $this->requireCraft();
        $form = $this->createForm('MultiSubmit', 'multi_submit');
        $nameId = $this->createField($form->id, 'text', 'name', 'Name', false, ['page' => 1]);
        $emailId = $this->createField($form->id, 'email', 'email', 'Email', false, ['page' => 2]);

        $result = Plugin::getInstance()->getSubmissionService()->submit(
            $form,
            ['field_' . $nameId => 'Ada', 'field_' . $emailId => 'ada@example.test'],
            ['skipCaptcha' => true],
        );

        $this->assertNotNull($result['submission']);
        $count = (new Query())->from('{{%simpleform_submissions}}')->where(['formId' => $form->id])->count();
        $this->assertSame(1, (int) $count);

        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $result['submission']->id])->one();
        $data = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);
        $this->assertSame('Ada', $data['field_' . $nameId]['value']);
        $this->assertSame('ada@example.test', $data['field_' . $emailId]['value']);
    }
}
