<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\integrations\support\SubmissionValues;
use PHPUnit\Framework\TestCase;

/**
 * Single-sourced submission-data value extraction (issue #166). Covers
 * {@see SubmissionValues::value()} — the one place that now encodes the stored
 * `field_<id> => {label, type, value}` shape — including the `''`-vs-`null`
 * default that each call site relies on.
 */
class SubmissionValuesTest extends TestCase
{
    public function testReturnsValueFromShapedEntry(): void
    {
        $entry = ['label' => 'Email', 'type' => 'email', 'value' => 'a@b.test'];
        $this->assertSame('a@b.test', SubmissionValues::value($entry));
    }

    public function testReturnsRawScalarEntryAsIs(): void
    {
        $this->assertSame('plain', SubmissionValues::value('plain'));
        $this->assertSame(0, SubmissionValues::value(0));
    }

    public function testPreservesArrayValue(): void
    {
        $entry = ['label' => 'Topics', 'type' => 'checkboxes', 'value' => ['a', 'b']];
        $this->assertSame(['a', 'b'], SubmissionValues::value($entry));
    }

    public function testMissingValueDefaultsToNullForServicesAndIntegrations(): void
    {
        // NotificationsService / PaymentsService / SubmissionValues::byHandle all
        // omit the $default argument and must keep their null default.
        $entry = ['label' => 'Email', 'type' => 'email'];
        $this->assertNull(SubmissionValues::value($entry));
    }

    public function testMissingValueHonoursEmptyStringDefaultForCsv(): void
    {
        // SubmissionCsv passes '' so a value-less cell stays an empty string, not
        // null — the distinction the issue requires preserving.
        $entry = ['label' => 'Email', 'type' => 'email'];
        $this->assertSame('', SubmissionValues::value($entry, ''));
    }

    public function testNullStoredValueIsTreatedAsMissing(): void
    {
        // `?? $default` collapses a stored null onto the default, matching the
        // original inline behaviour at every call site.
        $entry = ['label' => 'Email', 'type' => 'email', 'value' => null];
        $this->assertSame('', SubmissionValues::value($entry, ''));
        $this->assertNull(SubmissionValues::value($entry));
    }
}
