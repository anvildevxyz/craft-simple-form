<?php

namespace fabianhaef\simpleform\models;

use craft\base\Model;

/**
 * One configured outbound-integration instance attached to a form
 * (a row in `simpleform_integrations`). Settings are connector-defined and
 * validated by the connector's own `defineSettingsRules()`.
 */
class IntegrationModel extends Model
{
    public ?int $id = null;
    public ?int $formId = null;
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
            [['formId', 'type', 'name'], 'required'],
            [['formId', 'sortOrder'], 'integer'],
            [['type', 'name'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
        ];
    }
}
