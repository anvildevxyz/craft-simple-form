<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\TwigExtension;
use Craft;

/**
 * @group requires-craft
 */
class FormCreateAndRenderTest extends SimpleFormTestCase
{
    public function testCreateFormPersistsSharedAndPerSiteColumns(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Contact', 'contactForm', 'Contact Us');

        $this->assertNotNull($form->id);

        // Round-trip from the DB through the element query (joins shared + per-site rows).
        $reloaded = Form::find()->id($form->id)->one();
        $this->assertNotNull($reloaded);
        $this->assertSame('Contact', $reloaded->name);
        $this->assertSame('contactForm', $reloaded->handle);
        $this->assertSame('Contact Us', $reloaded->title);
    }

    public function testRenderFormOutputsFieldInputs(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Signup', 'signupForm', 'Sign Up');
        $nameFieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);
        $emailFieldId = $this->createField($form->id, 'email', 'email', 'Email Address', true);

        $html = (new TwigExtension())->renderForm('signupForm');

        // The form wrapper and submit posts to the plugin's submit action.
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('name="formHandle"', $html);
        $this->assertStringContainsString('value="signupForm"', $html);

        // Both field inputs render under their field_<id> names with their labels.
        $this->assertStringContainsString('name="field_' . $nameFieldId . '"', $html);
        $this->assertStringContainsString('name="field_' . $emailFieldId . '"', $html);
        $this->assertStringContainsString('Full Name', $html);
        $this->assertStringContainsString('Email Address', $html);
        $this->assertStringContainsString('type="submit"', $html);
    }

    public function testRenderUnknownFormReturnsComment(): void
    {
        $this->requireCraft();

        $html = (new TwigExtension())->renderForm('doesNotExist');
        $this->assertStringContainsString('not found', $html);
    }
}
