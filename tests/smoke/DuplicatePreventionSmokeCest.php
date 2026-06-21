<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use SmokeTester;

/**
 * Duplicate-prevention smoke tests (functional).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class DuplicatePreventionSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testDuplicateEmailMarkedAsSpam(SmokeTester $I): void
    {
        $form = $this->duplicateForm('dupEmail' . uniqid(), Form::DUPLICATE_KEY_EMAIL);
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email');

        $first = $this->submitRequest($form->handle, ['field_' . $emailId => 'bob@example.com']);
        $second = $this->submitRequest($form->handle, ['field_' . $emailId => 'bob@example.com']);

        $I->assertNull($first['errors']);
        $I->assertSame(SubmissionStatus::NEW, $first['submission']->readStatus);
        $I->assertSame(SubmissionStatus::SPAM, $second['submission']->readStatus);
        $I->assertSame('duplicate', $second['submission']->spamReason);
    }

    public function testDuplicateContentMarkedAsSpam(SmokeTester $I): void
    {
        $form = $this->duplicateForm('dupContent' . uniqid(), Form::DUPLICATE_KEY_CONTENT);
        $fieldId = $this->createField((int) $form->id, 'text', 'message', 'Message');

        $first = $this->submitRequest($form->handle, ['field_' . $fieldId => 'hello there']);
        $second = $this->submitRequest($form->handle, ['field_' . $fieldId => 'hello there']);

        $I->assertSame(SubmissionStatus::NEW, $first['submission']->readStatus);
        $I->assertSame(SubmissionStatus::SPAM, $second['submission']->readStatus);
    }

    public function testDifferentValuesAreAllowed(SmokeTester $I): void
    {
        $form = $this->duplicateForm('dupAllow' . uniqid(), Form::DUPLICATE_KEY_EMAIL);
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email');

        $this->submitRequest($form->handle, ['field_' . $emailId => 'a@example.com']);
        $second = $this->submitRequest($form->handle, ['field_' . $emailId => 'b@example.com']);

        $I->assertSame(SubmissionStatus::NEW, $second['submission']->readStatus);
    }

    // =========================================================================
    // PROTECTED METHODS
    // =========================================================================

    protected function duplicateForm(string $handle, string $key): Form
    {
        $form = $this->createForm('Duplicate', $handle);
        $form->preventDuplicates = true;
        $form->duplicateKey = $key;
        Craft::$app->getElements()->saveElement($form);

        return $form;
    }
}
