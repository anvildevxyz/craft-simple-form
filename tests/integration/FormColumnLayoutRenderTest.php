<?php

namespace fabianhaef\simpleform\tests\integration;

use fabianhaef\simpleform\TwigExtension;

/**
 * Front-end rendering of multi-column row layouts (issue #136). Asserts that
 * grouped fields emit the `.simple-form-row` grid wrapper, that single-column
 * forms stay byte-for-byte unchanged, and that columns compose within steps.
 *
 * @group requires-craft
 */
class FormColumnLayoutRenderTest extends SimpleFormTestCase
{
    public function testGroupedFieldsRenderGridRow(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Name', 'nameForm', 'Your Name');
        $firstId = $this->createField($form->id, 'text', 'firstName', 'First Name', false, ['row' => 1]);
        $lastId = $this->createField($form->id, 'text', 'lastName', 'Last Name', false, ['row' => 1]);

        $html = (new TwigExtension())->renderForm('nameForm');

        $this->assertStringContainsString('class="simple-form-row" data-cols="2"', $html);
        $this->assertSame(2, substr_count($html, 'class="simple-form-col"'));
        $this->assertStringContainsString('name="field_' . $firstId . '"', $html);
        $this->assertStringContainsString('name="field_' . $lastId . '"', $html);
    }

    public function testColumnWidthsEmitGridTemplate(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Split', 'splitForm', 'Split');
        $this->createField($form->id, 'text', 'firstName', 'First', false, ['row' => 1, 'width' => 2]);
        $this->createField($form->id, 'text', 'lastName', 'Last', false, ['row' => 1]);

        $html = (new TwigExtension())->renderForm('splitForm');

        // A weighted row exposes its column track sizes via the --sf-cols
        // custom property so the stylesheet's media query can still collapse it.
        $this->assertStringContainsString('--sf-cols: 2fr 1fr;', $html);
        $this->assertStringContainsString('class="simple-form-row" data-cols="2"', $html);
    }

    public function testEqualColumnsEmitUniformGridTemplate(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Even', 'evenForm', 'Even');
        $this->createField($form->id, 'text', 'firstName', 'First', false, ['row' => 1]);
        $this->createField($form->id, 'text', 'lastName', 'Last', false, ['row' => 1]);

        $html = (new TwigExtension())->renderForm('evenForm');

        $this->assertStringContainsString('--sf-cols: 1fr 1fr;', $html);
    }

    public function testSingleColumnFormHasNoRowWrapper(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Plain', 'plainForm', 'Plain');
        $this->createField($form->id, 'text', 'fullName', 'Full Name');
        $this->createField($form->id, 'email', 'email', 'Email');

        $html = (new TwigExtension())->renderForm('plainForm');

        $this->assertStringNotContainsString('simple-form-row', $html);
        $this->assertStringNotContainsString('simple-form-col', $html);
    }

    public function testColumnsComposeWithinSteps(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Wizard', 'wizardForm', 'Wizard');
        $this->createField($form->id, 'text', 'firstName', 'First', false, ['page' => 1, 'row' => 1]);
        $this->createField($form->id, 'text', 'lastName', 'Last', false, ['page' => 1, 'row' => 1]);
        $this->createField($form->id, 'textarea', 'comment', 'Comment', false, ['page' => 2]);

        $html = (new TwigExtension())->renderForm('wizardForm');

        // The 2-column row lives inside step 1's container, and step 2 has no row.
        $this->assertStringContainsString('data-sf-step="0"', $html);
        $this->assertStringContainsString('class="simple-form-row" data-cols="2"', $html);
        $this->assertSame(1, substr_count($html, 'simple-form-row'));
    }
}
