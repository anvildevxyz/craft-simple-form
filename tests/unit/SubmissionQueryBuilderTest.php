<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\mcp\tools\support\SubmissionQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the DB-free accessors of {@see SubmissionQueryBuilder} —
 * the shared `fieldMatch` argument coercion (#168). Query construction
 * ({@see SubmissionQueryBuilder::build()} / ::buildWithForm()) is covered by
 * {@see \anvildev\simpleform\tests\integration\McpSubmissionToolsTest} where a
 * Craft app and DB are available.
 */
class SubmissionQueryBuilderTest extends TestCase
{
    public function testFieldMatchReturnsTheArrayWhenPresent(): void
    {
        $match = ['email' => 'a@example.com', 'topic' => 'billing'];

        $this->assertSame($match, SubmissionQueryBuilder::fieldMatch(['fieldMatch' => $match]));
    }

    public function testFieldMatchDefaultsToEmptyWhenMissing(): void
    {
        $this->assertSame([], SubmissionQueryBuilder::fieldMatch([]));
    }

    public function testFieldMatchDefaultsToEmptyWhenNotAnArray(): void
    {
        // Any non-array value (scalar, null) coerces to the empty filter, exactly
        // as the previous inline `is_array(... ?? null) ? ... : []` did.
        $this->assertSame([], SubmissionQueryBuilder::fieldMatch(['fieldMatch' => 'nope']));
        $this->assertSame([], SubmissionQueryBuilder::fieldMatch(['fieldMatch' => null]));
        $this->assertSame([], SubmissionQueryBuilder::fieldMatch(['fieldMatch' => 5]));
    }
}
