<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\IntegrationResult;
use fabianhaef\simpleform\integrations\IntegrationTypeInterface;
use fabianhaef\simpleform\services\IntegrationTypeRegistry;
use PHPUnit\Framework\TestCase;

/** A minimal in-test connector used to exercise the registry. */
class StubIntegrationType implements IntegrationTypeInterface
{
    public static function handle(): string
    {
        return 'stub';
    }

    public static function displayName(): string
    {
        return 'Stub Connector';
    }

    public function settingsHtml(array $settings): string
    {
        return '';
    }

    public function defineSettingsRules(): array
    {
        return [];
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        return IntegrationResult::success();
    }
}

class NotAnIntegration
{
}

class IntegrationTypeRegistryTest extends TestCase
{
    public function testRegisterAndRetrieveType(): void
    {
        $registry = new IntegrationTypeRegistry();
        $registry->registerType(StubIntegrationType::class);

        // The core Webhook connector is always registered; the stub adds to it.
        $this->assertContains('stub', $registry->typeHandles());
        $this->assertContains('webhook', $registry->typeHandles());
        $this->assertSame('Stub Connector', $registry->getAllTypes()['stub']);
        $this->assertInstanceOf(StubIntegrationType::class, $registry->getType('stub'));
    }

    public function testWebhookIsRegisteredByDefault(): void
    {
        $registry = new IntegrationTypeRegistry();
        $this->assertInstanceOf(
            \fabianhaef\simpleform\integrations\WebhookIntegration::class,
            $registry->getType('webhook'),
        );
    }

    public function testCraftElementIsRegisteredByDefault(): void
    {
        $registry = new IntegrationTypeRegistry();
        $this->assertInstanceOf(
            \fabianhaef\simpleform\integrations\CraftElementIntegration::class,
            $registry->getType('craft-element'),
        );
    }

    public function testGetUnknownTypeReturnsNull(): void
    {
        $registry = new IntegrationTypeRegistry();
        $this->assertNull($registry->getType('nope'));
    }

    public function testRegisterNonExistentClassThrows(): void
    {
        $registry = new IntegrationTypeRegistry();
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentionally invalid class for the guard test */
        $registry->registerType('fabianhaef\simpleform\Nope');
    }

    public function testRegisterClassNotImplementingInterfaceThrows(): void
    {
        $registry = new IntegrationTypeRegistry();
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentionally wrong type for the guard test */
        $registry->registerType(NotAnIntegration::class);
    }
}
