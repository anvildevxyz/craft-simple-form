<?php

namespace fabianhaef\simpleform\services;

use fabianhaef\simpleform\events\RegisterIntegrationTypesEvent;
use fabianhaef\simpleform\integrations\ActiveCampaignIntegration;
use fabianhaef\simpleform\integrations\DiscordIntegration;
use fabianhaef\simpleform\integrations\IntegrationTypeInterface;
use fabianhaef\simpleform\integrations\MailchimpIntegration;
use fabianhaef\simpleform\integrations\SlackIntegration;
use fabianhaef\simpleform\integrations\WebhookIntegration;
use fabianhaef\simpleform\Plugin;
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
        $this->registerType(WebhookIntegration::class);
        $this->registerType(SlackIntegration::class);
        $this->registerType(DiscordIntegration::class);
        $this->registerType(MailchimpIntegration::class);
        $this->registerType(ActiveCampaignIntegration::class);

        // Let third parties contribute their own. Fired on the Plugin class so the
        // registration ergonomics match field types / MCP tools. Guarded on the
        // Craft app so unit tests (no bootstrap, no `Yii` alias) skip it cleanly.
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            return;
        }

        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            $event = new RegisterIntegrationTypesEvent();
            $plugin->trigger(Plugin::EVENT_REGISTER_INTEGRATION_TYPES, $event);
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
        if (!isset($this->types[$handle])) {
            return null;
        }

        $class = $this->types[$handle];
        return new $class();
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
        $out = [];
        foreach ($this->types as $handle => $class) {
            $out[$handle] = $class::displayName();
        }
        return $out;
    }
}
