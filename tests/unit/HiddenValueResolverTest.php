<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\helpers\HiddenValueResolver;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the Hidden field's source resolver (#124). Pure PHP
 * with no Craft dependency: the resolver takes its request/user/cookie inputs
 * explicitly, so these assert the real resolution + sanitisation results,
 * including the load-bearing anti-spoofing guarantee of the `user` source.
 */
class HiddenValueResolverTest extends TestCase
{
    // --- static source ----------------------------------------------------

    public function testStaticReturnsDefaultVerbatim(): void
    {
        $config = ['source' => 'static', 'default' => 'spring-sale'];
        $this->assertSame('spring-sale', HiddenValueResolver::resolveClientSource($config, [], []));
    }

    public function testMissingSourceDefaultsToStatic(): void
    {
        $this->assertSame('hello', HiddenValueResolver::resolveClientSource(['default' => 'hello'], [], []));
    }

    // --- query source -----------------------------------------------------

    public function testQueryReadsParam(): void
    {
        $config = ['source' => 'query', 'queryParam' => 'utm_source', 'default' => 'direct'];
        $this->assertSame('newsletter', HiddenValueResolver::resolveClientSource($config, ['utm_source' => 'newsletter'], []));
    }

    public function testQueryFallsBackToDefaultWhenAbsent(): void
    {
        $config = ['source' => 'query', 'queryParam' => 'utm_source', 'default' => 'direct'];
        $this->assertSame('direct', HiddenValueResolver::resolveClientSource($config, [], []));
    }

    public function testQueryValueIsTrimmed(): void
    {
        $config = ['source' => 'query', 'queryParam' => 'q'];
        $this->assertSame('boxed', HiddenValueResolver::resolveClientSource($config, ['q' => "  boxed \n"], []));
    }

    public function testNonScalarQueryValueYieldsEmpty(): void
    {
        $config = ['source' => 'query', 'queryParam' => 'q'];
        $this->assertSame('', HiddenValueResolver::resolveClientSource($config, ['q' => ['a', 'b']], []));
    }

    // --- cookie source ----------------------------------------------------

    public function testCookieReadsValue(): void
    {
        $config = ['source' => 'cookie', 'cookieName' => 'referrer'];
        $this->assertSame('google', HiddenValueResolver::resolveClientSource($config, [], ['referrer' => 'google']));
    }

    public function testCookieFallsBackToDefault(): void
    {
        $config = ['source' => 'cookie', 'cookieName' => 'referrer', 'default' => 'none'];
        $this->assertSame('none', HiddenValueResolver::resolveClientSource($config, [], []));
    }

    // --- user source ------------------------------------------------------

    public function testUserResolvesEmail(): void
    {
        $config = ['source' => 'user', 'userAttribute' => 'email'];
        $attrs = ['email' => 'real@example.test', 'id' => 5, 'username' => 'real'];
        $this->assertSame('real@example.test', HiddenValueResolver::resolveUser($config, $attrs));
    }

    public function testUserResolvesId(): void
    {
        $config = ['source' => 'user', 'userAttribute' => 'id'];
        $attrs = ['email' => 'real@example.test', 'id' => 42, 'username' => 'real'];
        $this->assertSame('42', HiddenValueResolver::resolveUser($config, $attrs));
    }

    public function testUserResolvesUsername(): void
    {
        $config = ['source' => 'user', 'userAttribute' => 'username'];
        $attrs = ['email' => 'real@example.test', 'id' => 42, 'username' => 'realuser'];
        $this->assertSame('realuser', HiddenValueResolver::resolveUser($config, $attrs));
    }

    public function testUserGuestYieldsDefault(): void
    {
        $config = ['source' => 'user', 'userAttribute' => 'email', 'default' => 'anon'];
        $this->assertSame('anon', HiddenValueResolver::resolveUser($config, null));
    }

    public function testUserGuestWithNoDefaultIsEmpty(): void
    {
        $config = ['source' => 'user', 'userAttribute' => 'email'];
        $this->assertSame('', HiddenValueResolver::resolveUser($config, null));
    }

    public function testUserUnknownAttributeFallsBackToEmail(): void
    {
        $config = ['source' => 'user', 'userAttribute' => 'ssn'];
        $attrs = ['email' => 'real@example.test', 'id' => 1, 'username' => 'u'];
        $this->assertSame('real@example.test', HiddenValueResolver::resolveUser($config, $attrs));
    }

    // --- sanitisation -----------------------------------------------------

    public function testMaxLengthClamps(): void
    {
        $config = ['maxLength' => 5];
        $this->assertSame('abcde', HiddenValueResolver::sanitize('abcdefghij', $config));
    }

    public function testDefaultMaxLengthAppliesWhenUnset(): void
    {
        $this->assertSame(HiddenValueResolver::DEFAULT_MAX_LENGTH, HiddenValueResolver::maxLength([]));
    }

    public function testZeroMaxLengthFallsBackToDefault(): void
    {
        $this->assertSame(HiddenValueResolver::DEFAULT_MAX_LENGTH, HiddenValueResolver::maxLength(['maxLength' => 0]));
    }

    public function testWithinMaxLength(): void
    {
        $this->assertTrue(HiddenValueResolver::withinMaxLength('', ['maxLength' => 5]));
        $this->assertTrue(HiddenValueResolver::withinMaxLength(null, ['maxLength' => 5]));
        $this->assertTrue(HiddenValueResolver::withinMaxLength('abcde', ['maxLength' => 5]));
        $this->assertFalse(HiddenValueResolver::withinMaxLength('abcdef', ['maxLength' => 5]));
    }

    public function testMarkupIsNotInterpretedOnlyBoundedText(): void
    {
        // The resolver returns plain text untouched; HTML escaping happens at
        // output. It must never strip/transform — just trim + bound.
        $config = ['source' => 'static', 'default' => '<script>alert(1)</script>'];
        $this->assertSame('<script>alert(1)</script>', HiddenValueResolver::resolveClientSource($config, [], []));
    }

    // --- trusted-source flag ----------------------------------------------

    public function testOnlyUserSourceIsTrusted(): void
    {
        $this->assertTrue(HiddenValueResolver::isTrustedSource('user'));
        $this->assertFalse(HiddenValueResolver::isTrustedSource('static'));
        $this->assertFalse(HiddenValueResolver::isTrustedSource('query'));
        $this->assertFalse(HiddenValueResolver::isTrustedSource('cookie'));
    }
}
