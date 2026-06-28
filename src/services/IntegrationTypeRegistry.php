<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\events\RegisterIntegrationTypesEvent;
use anvildev\simpleform\integrations\ActiveCampaignIntegration;
use anvildev\simpleform\integrations\CraftElementIntegration;
use anvildev\simpleform\integrations\DiscordIntegration;
use anvildev\simpleform\integrations\GoogleSheetsIntegration;
use anvildev\simpleform\integrations\HubSpotIntegration;
use anvildev\simpleform\integrations\IntegrationTypeInterface;
use anvildev\simpleform\integrations\MailchimpIntegration;
use anvildev\simpleform\integrations\PipedriveIntegration;
use anvildev\simpleform\integrations\SlackIntegration;
use anvildev\simpleform\integrations\WebhookIntegration;
use anvildev\simpleform\Plugin;
use yii\base\Component;

/**
 * Registry of available outbound-integration types. Core types are registered in
 * {@see init()}; third parties add their own via
 * {@see Plugin::EVENT_REGISTER_INTEGRATION_TYPES}. Mirrors
 * {@see FieldTypeRegistry}.
 */
class IntegrationTypeRegistry extends Component
{
    /** @var array<string, class-string<IntegrationTypeInterface>> */
    private array $types = [];

    public function init(): void
    {
        parent::init();

        // Core connectors.
        foreach ([
            WebhookIntegration::class,
            SlackIntegration::class,
            DiscordIntegration::class,
            MailchimpIntegration::class,
            ActiveCampaignIntegration::class,
            HubSpotIntegration::class,
            PipedriveIntegration::class,
            GoogleSheetsIntegration::class,
            CraftElementIntegration::class,
        ] as $class) {
            $this->registerType($class);
        }

        // Let third parties contribute their own. Fired on the Plugin class so the
        // registration ergonomics match field types / MCP tools. Guarded on the
        // Craft app so unit tests (no bootstrap, no `Yii` alias) skip it cleanly.
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            return;
        }

        if (($plugin = Plugin::getInstance()) !== null) {
            $plugin->trigger(Plugin::EVENT_REGISTER_INTEGRATION_TYPES, $event = new RegisterIntegrationTypesEvent());
            foreach ($event->types as $class) {
                $this->registerType($class);
            }
        }
    }

    /**
     * @param class-string<IntegrationTypeInterface> $class
     */
    public function registerType(string $class): void
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Integration type class does not exist: $class");
        }
        if (!is_subclass_of($class, IntegrationTypeInterface::class)) {
            throw new \InvalidArgumentException("Integration type must implement IntegrationTypeInterface: $class");
        }

        $this->types[$class::handle()] = $class;
    }

    public function getType(string $handle): ?IntegrationTypeInterface
    {
        return isset($this->types[$handle]) ? new $this->types[$handle]() : null;
    }

    /**
     * The registered integration-type handles — the canonical valid-type set.
     *
     * @return list<string>
     */
    public function typeHandles(): array
    {
        return array_keys($this->types);
    }

    /**
     * Handle => display-name map for the CP integration picker.
     *
     * @return array<string, string>
     */
    public function getAllTypes(): array
    {
        return array_map(static fn(string $class): string => $class::displayName(), $this->types);
    }
}
