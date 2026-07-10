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

    public function testArrayValueBecomesListOfStringsWhenFieldAcceptsAList(): void
    {
        $this->assertSame(['a', 'b'], QueryPrefillResolver::sanitizeValue(['a', 'b'], true));
    }

    public function testArrayValueYieldsNullWhenFieldDoesNotAcceptAList(): void
    {
        // Code-review fix: a scalar field (e.g. text) casts its value straight
        // to a string, so an array param must never reach it — the default
        // (no $acceptsList arg) is the safe scalar-field behaviour.
        $this->assertNull(QueryPrefillResolver::sanitizeValue(['a', 'b']));
        $this->assertNull(QueryPrefillResolver::sanitizeValue(['a', 'b'], false));
    }

    public function testEmptyArrayYieldsNull(): void
    {
        $this->assertNull(QueryPrefillResolver::sanitizeValue([], true));
    }

    public function testNestedArrayEntriesAreDropped(): void
    {
        $this->assertSame(['ok'], QueryPrefillResolver::sanitizeValue(['ok', ['nested']], true));
    }

    // --- resolve ----------------------------------------------------------

    public function testOnlyOptedInFieldsAreResolved(): void
    {
        $fields = [
            ['key' => 'field_1', 'handle' => 'name', 'config' => ['prefillFromQuery' => true], 'acceptsList' => false],
            ['key' => 'field_2', 'handle' => 'email', 'config' => ['prefillFromQuery' => false], 'acceptsList' => false],
        ];
        $query = ['name' => 'Ada', 'email' => 'sneaky@example.com'];

        $this->assertSame(['field_1' => 'Ada'], QueryPrefillResolver::resolve($fields, $query, false));
    }

    public function testFormDefaultOptsInUnflaggedFields(): void
    {
        $fields = [
            ['key' => 'field_1', 'handle' => 'city', 'config' => ['prefillParam' => 'loc'], 'acceptsList' => false],
            ['key' => 'field_2', 'handle' => 'ref', 'config' => ['prefillFromQuery' => false], 'acceptsList' => false],
        ];
        $query = ['loc' => 'Zurich', 'ref' => 'blocked'];

        $this->assertSame(['field_1' => 'Zurich'], QueryPrefillResolver::resolve($fields, $query, true));
    }

    public function testAbsentParamContributesNoEntry(): void
    {
        $fields = [
            ['key' => 'field_1', 'handle' => 'name', 'config' => ['prefillFromQuery' => true], 'acceptsList' => false],
        ];

        $this->assertSame([], QueryPrefillResolver::resolve($fields, [], false));
    }

    public function testArrayQueryParamIsRejectedForAScalarField(): void
    {
        // The DoS scenario (code review): a visitor loads `?name[]=x&name[]=y`
        // against a scalar text field. Without `acceptsList`, the array must be
        // dropped rather than handed to the field's renderer.
        $fields = [
            ['key' => 'field_1', 'handle' => 'name', 'config' => ['prefillFromQuery' => true], 'acceptsList' => false],
        ];
        $query = ['name' => ['x', 'y']];

        $this->assertSame([], QueryPrefillResolver::resolve($fields, $query, false));
    }

    public function testArrayQueryParamIsAcceptedForAListField(): void
    {
        $fields = [
            ['key' => 'field_1', 'handle' => 'interests', 'config' => ['prefillFromQuery' => true], 'acceptsList' => true],
        ];
        $query = ['interests' => ['sports', 'music']];

        $this->assertSame(['field_1' => ['sports', 'music']], QueryPrefillResolver::resolve($fields, $query, false));
    }
}
