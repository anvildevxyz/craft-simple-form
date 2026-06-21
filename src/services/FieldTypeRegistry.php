<?php

namespace fabianhaef\simpleform\services;

use fabianhaef\simpleform\fields\CheckboxFieldType;
use fabianhaef\simpleform\fields\ConsentFieldType;
use fabianhaef\simpleform\fields\DateFieldType;
use fabianhaef\simpleform\fields\DividerFieldType;
use fabianhaef\simpleform\fields\EmailFieldType;
use fabianhaef\simpleform\fields\FieldType;
use fabianhaef\simpleform\fields\FileFieldType;
use fabianhaef\simpleform\fields\HeadingFieldType;
use fabianhaef\simpleform\fields\HiddenFieldType;
use fabianhaef\simpleform\fields\HtmlFieldType;
use fabianhaef\simpleform\fields\NumberFieldType;
use fabianhaef\simpleform\fields\PaymentFieldType;
use fabianhaef\simpleform\fields\PhoneFieldType;
use fabianhaef\simpleform\fields\RadioFieldType;
use fabianhaef\simpleform\fields\SelectFieldType;
use fabianhaef\simpleform\fields\TextareaFieldType;
use fabianhaef\simpleform\fields\TextFieldType;
use yii\base\Component;

class FieldTypeRegistry extends Component
{
    /**
     * Field types backed by a closed set of {value,label} options: they require a
     * non-empty `options` config and are the groupable types for submission
     * analytics.
     *
     * @var list<string>
     */
    public const OPTION_TYPES = ['select', 'checkbox', 'radio'];

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
        $this->registerFieldType(PhoneFieldType::class);
        $this->registerFieldType(FileFieldType::class);
        $this->registerFieldType(PaymentFieldType::class);
        $this->registerFieldType(HiddenFieldType::class);
        $this->registerFieldType(ConsentFieldType::class);

        // Presentational/layout blocks (value-less; isInput() === false).
        $this->registerFieldType(HeadingFieldType::class);
        $this->registerFieldType(DividerFieldType::class);
        $this->registerFieldType(HtmlFieldType::class);
    }

    /**
     * The registered non-input (presentational/layout) field-type handles —
     * heading, divider, html. Skipped by validation, storage, and export.
     *
     * @return list<string>
     */
    public function layoutTypeHandles(): array
    {
        $handles = [];
        foreach ($this->fieldTypes as $type => $class) {
            if (!(new $class())->isInput()) {
                $handles[] = $type;
            }
        }
        return $handles;
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
     * The registered field-type handles — the canonical valid-type set.
     *
     * @return list<string>
     */
    public function typeHandles(): array
    {
        return array_keys($this->fieldTypes);
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
