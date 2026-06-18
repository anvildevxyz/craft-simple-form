<?php

namespace fabianhaef\simpleform\models;

use craft\base\Model;

/**
 * One global outbound-integration definition (a row in `simpleform_integrations`).
 * Integrations are attached to forms through `simpleform_form_integrations`;
 * `enabled` is the global master switch. Settings are connector-defined and
 * validated by the connector's own `defineSettingsRules()`.
 */
class IntegrationModel extends Model
{
    public ?int $id = null;
    public string $type = '';
    public string $name = '';
    public bool $enabled = true;
    /** @var array<string, mixed> */
    public array $settings = [];
    public ?int $sortOrder = null;
    public ?string $uid = null;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['type', 'name'], 'required'],
            [['sortOrder'], 'integer'],
            [['type', 'name'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
        ];
    }
}
