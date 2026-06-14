<?php

namespace fabianhaef\simpleform\services;

use fabianhaef\simpleform\fields\CheckboxFieldType;
use fabianhaef\simpleform\fields\DateFieldType;
use fabianhaef\simpleform\fields\EmailFieldType;
use fabianhaef\simpleform\fields\FieldType;
use fabianhaef\simpleform\fields\NumberFieldType;
use fabianhaef\simpleform\fields\RadioFieldType;
use fabianhaef\simpleform\fields\SelectFieldType;
use fabianhaef\simpleform\fields\TextareaFieldType;
use fabianhaef\simpleform\fields\TextFieldType;
use yii\base\Component;

class FieldTypeRegistry extends Component
{
    /** @var array<string, class-string<FieldType>> */
    private array $fieldTypes = [];

    public function init(): void
    {
        parent::init();

        $this->registerFieldType(TextFieldType::class);
        $this->registerFieldType(EmailFieldType::class);
        $this->registerFieldType(TextareaFieldType::class);
        $this->registerFieldType(SelectFieldType::class);
        $this->registerFieldType(CheckboxFieldType::class);
        $this->registerFieldType(RadioFieldType::class);
        $this->registerFieldType(DateFieldType::class);
        $this->registerFieldType(NumberFieldType::class);
    }

    /**
     * @param class-string<FieldType> $class
     */
    public function registerFieldType(string $class): void
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Field type class does not exist: $class");
        }

        $type = $class::getType();
        $this->fieldTypes[$type] = $class;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function getFieldType(string $type, array $config = []): ?FieldType
    {
        if (!isset($this->fieldTypes[$type])) {
            return null;
        }

        $class = $this->fieldTypes[$type];
        return new $class($config);
    }

    /**
     * @return array<string, array{type: string, label: string}>
     */
    public function getAllFieldTypes(): array
    {
        $types = [];
        foreach ($this->fieldTypes as $type => $class) {
            $types[$type] = [
                'type' => $type,
                'label' => $class::getLabel(),
            ];
        }
        return $types;
    }
}
