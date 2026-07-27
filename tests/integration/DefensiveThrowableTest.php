<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\fields\FieldType;
use anvildev\simpleform\models\FieldModel;
use anvildev\simpleform\Plugin;
use Craft;

/**
 * Locks in the failure-mode changes from issues #152 and #153: genuine errors
 * now propagate instead of being swallowed by a broad `\Throwable` catch, while
 * the legitimate recoverable/absent cases keep their existing behavior.
 *
 * @group requires-craft
 */
class DefensiveThrowableTest extends SimpleFormTestCase
{
    // =========================================================================
    // #152 — FieldModel::validateValue
    // =========================================================================

    /**
     * A valid value against a real field type validates cleanly (no errors),
     * proving the happy path is unaffected by removing the catch.
     */
    public function testValidateValueReturnsNoErrorsForValidValue(): void
    {
        $this->requireCraft();

        $field = new FieldModel(1, 'text', 'fullName', 'Full Name', ['required' => true]);

        $this->assertSame([], $field->validateValue('Ada Lovelace', []));
    }

    /**
     * An unregistered field type is a recoverable data state, so it still
     * degrades to a validation error rather than throwing.
     */
    public function testValidateValueReturnsErrorForUnknownFieldType(): void
    {
        $this->requireCraft();

        $field = new FieldModel(1, 'no-such-type', 'mystery', 'Mystery', []);

        $this->assertSame(['Unknown field type: no-such-type'], $field->validateValue('x', []));
    }

    /**
     * A genuine defect raised by the field type (e.g. a malformed stored config
     * tripping an exception) now propagates to the caller instead of being
     * masked as a vague 'Validation error occurred' string. The broken catch
     * would have swallowed this.
     */
    public function testValidateValuePropagatesGenuineFieldTypeError(): void
    {
        $this->requireCraft();

        $registry = Plugin::getInstance()->getFieldTypeRegistry();
        $registry->registerFieldType(ThrowingFieldType::class);

        $field = new FieldModel(1, ThrowingFieldType::getType(), 'broken', 'Broken', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $field->validateValue('x', []);
    }

    // =========================================================================
    // #153 — Submission::getForm
    // =========================================================================

    /**
     * A submission whose form does not exist still returns null (the absent
     * case is served by `->one()` returning null, not by a swallowed error),
     * so the legitimate null-when-absent behavior is preserved.
     */
    public function testGetFormReturnsNullWhenFormIsAbsent(): void
    {
        $this->requireCraft();

        $submission = new Submission();
        $submission->formId = 999999;

        $this->assertNull($submission->getForm());
    }

    /**
     * A submission with no form id returns null without touching the database.
     */
    public function testGetFormReturnsNullWhenFormIdIsUnset(): void
    {
        $this->requireCraft();

        $submission = new Submission();
        $submission->formId = null;

        $this->assertNull($submission->getForm());
    }
}

/**
 * A field type whose validate() always throws, used to prove that a genuine
 * field-type defect propagates out of {@see FieldModel::validateValue()}.
 */
class ThrowingFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'throwing-test-type';
    }

    public static function getLabel(): string
    {
        return 'Throwing Test Type';
    }

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        throw new \RuntimeException('boom');
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        return '';
    }
}
