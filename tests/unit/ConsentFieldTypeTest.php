<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\fields\ConsentFieldType;
use PHPUnit\Framework\TestCase;

/**
 * #125 — the Consent field's server-side gate, single-checkbox render, and
 * auditable consent record. The localized default error message (via Craft::t)
 * is covered in the integration suite; here we assert the behaviour that needs
 * no Craft boot, driving the required message through the config override.
 */
class ConsentFieldTypeTest extends TestCase
{
    private const MSG = ['required' => true, 'requiredMessage' => 'You must agree.'];

    public function testRequiredAndUncheckedYieldsError(): void
    {
        $field = new ConsentFieldType(self::MSG);

        $this->assertSame(['You must agree.'], $field->validate(null));
        $this->assertSame(['You must agree.'], $field->validate(''));
        $this->assertSame(['You must agree.'], $field->validate('0'));
        $this->assertSame(['You must agree.'], $field->validate(false));
    }

    public function testCheckedPassesValidation(): void
    {
        $field = new ConsentFieldType(self::MSG);

        $this->assertSame([], $field->validate('1'));
        $this->assertSame([], $field->validate(1));
        $this->assertSame([], $field->validate(true));
    }

    public function testNotRequiredNeverErrors(): void
    {
        $field = new ConsentFieldType(['required' => false]);

        $this->assertSame([], $field->validate(null));
        $this->assertSame([], $field->validate('1'));
    }

    public function testRenderIsSingleCheckboxWithRichLabelForMatchingId(): void
    {
        $config = ['required' => true, 'consentText' => 'I agree to the [privacy policy](https://example.com/privacy)'];
        $html = (new ConsentFieldType($config))->renderInput('field_88');

        $this->assertStringContainsString('<input type="checkbox" id="field_88" name="field_88" value="1" required>', $html);
        $this->assertStringContainsString('<label for="field_88">', $html);
        $this->assertStringContainsString(
            '<a href="https://example.com/privacy" target="_blank" rel="noopener noreferrer">privacy policy</a>',
            $html,
        );
        $this->assertFalse((new ConsentFieldType([]))->isChoiceGroup());
        $this->assertTrue((new ConsentFieldType([]))->rendersOwnLabel());
    }

    public function testRenderReflectsCheckedState(): void
    {
        $config = ['consentText' => 'Agree'];

        $this->assertStringContainsString(' checked', (new ConsentFieldType($config))->renderInput('field_1', '1'));
        $this->assertStringNotContainsString(' checked', (new ConsentFieldType($config))->renderInput('field_1', null));
    }

    public function testPersistValueBuildsAuditRecordWithServerTimestamp(): void
    {
        $config = ['consentText' => 'I agree to the [privacy policy](https://example.com/privacy)'];

        $record = (new ConsentFieldType($config))->persistValue('1');

        $this->assertTrue($record['consented']);
        $this->assertNotEmpty($record['consentedAt']);
        // Server-stamped: a parseable ISO-8601 instant, not the client's value.
        $this->assertInstanceOf(\DateTimeImmutable::class, new \DateTimeImmutable($record['consentedAt']));
        $this->assertSame('I agree to the privacy policy (https://example.com/privacy)', $record['textVersion']);
        $this->assertStringStartsWith('sha256:', $record['textHash']);
    }

    public function testPersistValueRecordsUncheckedAsNotConsented(): void
    {
        $record = (new ConsentFieldType(['consentText' => 'Agree']))->persistValue(null);

        $this->assertFalse($record['consented']);
    }
}
