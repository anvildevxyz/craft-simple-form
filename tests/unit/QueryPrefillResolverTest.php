<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\helpers\QueryPrefillResolver;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the query-string prefill resolver (#316). Pure PHP with
 * no Craft dependency: the resolver takes the field descriptors, query params
 * and form-level default explicitly, so these assert the real opt-in decision,
 * param resolution, value coercion, and the security guarantee that only
 * opted-in fields ever read the query string.
 */
class QueryPrefillResolverTest extends TestCase
{
    // --- opt-in decision --------------------------------------------------

    public function testExplicitFieldFlagWinsOverFormDefault(): void
    {
        $this->assertTrue(QueryPrefillResolver::isEnabled(['prefillFromQuery' => true], false));
        $this->assertFalse(QueryPrefillResolver::isEnabled(['prefillFromQuery' => false], true));
    }

    public function testAbsentFlagInheritsFormDefault(): void
    {
        $this->assertTrue(QueryPrefillResolver::isEnabled([], true));
        $this->assertFalse(QueryPrefillResolver::isEnabled([], false));
    }

    // --- param name -------------------------------------------------------

    public function testParamDefaultsToHandle(): void
    {
        $this->assertSame('email', QueryPrefillResolver::paramName([], 'email'));
    }

    public function testParamOverrideWins(): void
    {
        $this->assertSame('loc', QueryPrefillResolver::paramName(['prefillParam' => 'loc'], 'city'));
    }

    public function testBlankParamOverrideFallsBackToHandle(): void
    {
        $this->assertSame('city', QueryPrefillResolver::paramName(['prefillParam' => '  '], 'city'));
    }

    // --- value coercion ---------------------------------------------------

    public function testScalarValueBecomesString(): void
    {
        $this->assertSame('42', QueryPrefillResolver::sanitizeValue(42));
    }

    public function testArrayValueBecomesListOfStrings(): void
    {
        $this->assertSame(['a', 'b'], QueryPrefillResolver::sanitizeValue(['a', 'b']));
    }

    public function testEmptyArrayYieldsNull(): void
    {
        $this->assertNull(QueryPrefillResolver::sanitizeValue([]));
    }

    public function testNestedArrayEntriesAreDropped(): void
    {
        $this->assertSame(['ok'], QueryPrefillResolver::sanitizeValue(['ok', ['nested']]));
    }

    // --- resolve ----------------------------------------------------------

    public function testOnlyOptedInFieldsAreResolved(): void
    {
        $fields = [
            ['key' => 'field_1', 'handle' => 'name', 'config' => ['prefillFromQuery' => true]],
            ['key' => 'field_2', 'handle' => 'email', 'config' => ['prefillFromQuery' => false]],
        ];
        $query = ['name' => 'Ada', 'email' => 'sneaky@example.com'];

        $this->assertSame(['field_1' => 'Ada'], QueryPrefillResolver::resolve($fields, $query, false));
    }

    public function testFormDefaultOptsInUnflaggedFields(): void
    {
        $fields = [
            ['key' => 'field_1', 'handle' => 'city', 'config' => ['prefillParam' => 'loc']],
            ['key' => 'field_2', 'handle' => 'ref', 'config' => ['prefillFromQuery' => false]],
        ];
        $query = ['loc' => 'Zurich', 'ref' => 'blocked'];

        $this->assertSame(['field_1' => 'Zurich'], QueryPrefillResolver::resolve($fields, $query, true));
    }

    public function testAbsentParamContributesNoEntry(): void
    {
        $fields = [
            ['key' => 'field_1', 'handle' => 'name', 'config' => ['prefillFromQuery' => true]],
        ];

        $this->assertSame([], QueryPrefillResolver::resolve($fields, [], false));
    }
}
