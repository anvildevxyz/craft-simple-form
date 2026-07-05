<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\controllers\SettingsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * #280 — settings validate as a whole model while the UI is per-tab, so a save
 * can fail on a field the author can't see (fresh install: the blank required
 * Email-tab sender fails saves on every other tab). The flash summary must
 * point at the offending tab and include every error, and a fresh install must
 * seed the sender so the model starts valid.
 */
class SettingsSaveErrorsTest extends TestCase
{
    /**
     * @param array<string, string> $firstErrors
     */
    private function summary(array $firstErrors, string $currentTab): string
    {
        $controller = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SettingsController::class, 'saveErrorSummary');

        return $method->invoke($controller, $firstErrors, $currentTab);
    }

    public function testCrossTabErrorNamesTheOffendingTab(): void
    {
        $summary = $this->summary(['defaultEmailSender' => 'Default Email Sender cannot be blank.'], 'general');

        $this->assertStringContainsString('Default Email Sender cannot be blank', $summary);
        $this->assertStringContainsString('Email', $summary);
        $this->assertStringContainsString('tab', $summary);
    }

    public function testSameTabErrorIsShownVerbatim(): void
    {
        $summary = $this->summary(['defaultEmailSender' => 'Default Email Sender cannot be blank.'], 'email');

        $this->assertSame('Default Email Sender cannot be blank.', $summary);
    }

    public function testEveryFirstErrorIsIncludedNotJustTheFirst(): void
    {
        $summary = $this->summary([
            'defaultEmailSender' => 'Default Email Sender cannot be blank.',
            'recaptchaV3SiteKey' => 'Site Key cannot be blank.',
        ], 'privacy');

        $this->assertStringContainsString('Default Email Sender cannot be blank', $summary);
        $this->assertStringContainsString('Site Key cannot be blank', $summary);
        $this->assertStringContainsString('Spam Protection', $summary);
    }

    public function testEmptyErrorsFallBackToGenericMessage(): void
    {
        $this->assertSame('Couldn’t save settings.', $this->summary([], 'general'));
    }

    public function testFreshInstallSeedsTheRequiredSender(): void
    {
        // Source guard: afterInstall() must seed defaultEmailSender from the
        // system mail settings, otherwise every settings save fails whole-model
        // validation on a fresh install.
        $code = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        $pos = strpos($code, 'protected function afterInstall(');
        $this->assertNotFalse($pos, 'Plugin::afterInstall() is missing');
        $body = substr($code, $pos, 1200);
        $this->assertStringContainsString('defaultEmailSender', $body);
        $this->assertStringContainsString('App::mailSettings()', $body);
        $this->assertStringContainsString('savePluginSettings', $body);
    }
}
