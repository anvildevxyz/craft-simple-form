<?php

namespace fabianhaef\simpleform\models;

use fabianhaef\simpleform\Plugin;
use yii\base\Model;

class FieldModel extends Model
{
    private int $id;
    private string $type;
    private string $name;
    private ?string $label;
    private string $helpText;
    private array $config;

    public function __construct(int $id, string $type, string $name, ?string $label = null, string $helpText = '', array $config = [])
    {
        parent::__construct();
        $this->id = $id;
        $this->type = $type;
        $this->name = $name;
        $this->label = $label;
        $this->helpText = $helpText;
        $this->config = $config;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getHelpText(): string
    {
        return $this->helpText;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function validate($value): array
    {
        $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();
        $fieldType = $fieldTypeRegistry->getFieldType($this->type, $this->config);

        if (!$fieldType) {
            return ['Unknown field type: ' . $this->type];
        }

        return $fieldType->validate($value);
    }

    public function renderInput(string $name, $value = null): string
    {
        $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();
        $fieldType = $fieldTypeRegistry->getFieldType($this->type, $this->config);

        if (!$fieldType) {
            return '<!-- Unknown field type: ' . htmlspecialchars($this->type) . ' -->';
        }

        return $fieldType->renderInput($name, $value);
    }
}
