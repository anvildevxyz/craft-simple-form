<?php

namespace fabianhaef\simpleform\models;

use Craft;
use fabianhaef\simpleform\Plugin;
use yii\base\Model;

class FieldModel extends Model
{
    private int $id;
    private string $type;
    private string $name;
    private ?string $label;
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(int $id, string $type, string $name, ?string $label = null, array $config = [])
    {
        parent::__construct();
        $this->id = $id;
        $this->type = $type;
        $this->name = $name;
        $this->label = $label;
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

    /**
     * @return string[]
     */
    public function validateValue(mixed $value): array
    {
        try {
            $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();
            $fieldType = $fieldTypeRegistry->getFieldType($this->type, $this->config);

            if (!$fieldType) {
                Craft::warning(sprintf('Unknown field type: %s', $this->type), 'simple-form');
                return ['Unknown field type: ' . $this->type];
            }

            return $fieldType->validate($value);
        } catch (\Throwable $e) {
            Craft::warning(sprintf('Field validation error: %s', $e->getMessage()), 'simple-form');
            return ['Validation error occurred'];
        }
    }
}
