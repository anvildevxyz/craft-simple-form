<?php

namespace fabianhaef\simpleform\services;

use fabianhaef\simpleform\fields\AddressFieldType;
use fabianhaef\simpleform\fields\AssetRelationFieldType;
use fabianhaef\simpleform\fields\CalculationFieldType;
use fabianhaef\simpleform\fields\CategoryRelationFieldType;
use fabianhaef\simpleform\fields\CheckboxFieldType;
use fabianhaef\simpleform\fields\ConsentFieldType;
use fabianhaef\simpleform\fields\DateFieldType;
use fabianhaef\simpleform\fields\DividerFieldType;
use fabianhaef\simpleform\fields\EmailFieldType;
use fabianhaef\simpleform\fields\EntryRelationFieldType;
use fabianhaef\simpleform\fields\FieldType;
use fabianhaef\simpleform\fields\FileFieldType;
use fabianhaef\simpleform\fields\HeadingFieldType;
use fabianhaef\simpleform\fields\HiddenFieldType;
use fabianhaef\simpleform\fields\HtmlFieldType;
use fabianhaef\simpleform\fields\NameFieldType;
use fabianhaef\simpleform\fields\NumberFieldType;
use fabianhaef\simpleform\fields\OpinionScaleFieldType;
use fabianhaef\simpleform\fields\PaymentFieldType;
use fabianhaef\simpleform\fields\PhoneFieldType;
use fabianhaef\simpleform\fields\RadioFieldType;
use fabianhaef\simpleform\fields\RatingFieldType;
use fabianhaef\simpleform\fields\RepeaterFieldType;
use fabianhaef\simpleform\fields\SelectFieldType;
use fabianhaef\simpleform\fields\SignatureFieldType;
use fabianhaef\simpleform\fields\TagRelationFieldType;
use fabianhaef\simpleform\fields\TextareaFieldType;
use fabianhaef\simpleform\fields\TextFieldType;
use fabianhaef\simpleform\fields\UserRelationFieldType;
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

    /**
     * Numeric scale field types: they store an integer over a bounded range and
     * are the types analytics aggregates as numbers (average + distribution)
     * rather than grouping as opaque option strings.
     *
     * @var list<string>
     */
    public const SCALE_TYPES = ['rating', 'opinion'];

    /**
     * Field types that store related Craft element ids in the submission data
     * and resolve to live elements at read time (submission detail, export).
     *
     * @var list<string>
     */
    public const RELATION_TYPES = ['entry', 'category', 'tag', 'user', 'asset'];

    /** @var array<string, class-string<FieldType>> */
    private array $fieldTypes = [];

    public function init(): void
    {
        parent::init();

        foreach ([
            TextFieldType::class,
            EmailFieldType::class,
            TextareaFieldType::class,
            SelectFieldType::class,
            CheckboxFieldType::class,
            RadioFieldType::class,
            DateFieldType::class,
            NumberFieldType::class,
            PhoneFieldType::class,
            FileFieldType::class,
            SignatureFieldType::class,
            PaymentFieldType::class,
            HiddenFieldType::class,
            ConsentFieldType::class,
            RatingFieldType::class,
            OpinionScaleFieldType::class,
            EntryRelationFieldType::class,
            CategoryRelationFieldType::class,
            TagRelationFieldType::class,
            UserRelationFieldType::class,
            AssetRelationFieldType::class,
            CalculationFieldType::class,
            RepeaterFieldType::class,
            NameFieldType::class,
            AddressFieldType::class,
            // Presentational/layout blocks (value-less; isInput() === false).
            HeadingFieldType::class,
            DividerFieldType::class,
            HtmlFieldType::class,
        ] as $class) {
            $this->registerFieldType($class);
        }
    }

    /**
     * The registered non-input (presentational/layout) field-type handles —
     * heading, divider, html. Skipped by validation, storage, and export.
     *
     * @return list<string>
     */
    public function layoutTypeHandles(): array
    {
        return array_values(array_filter(
            array_keys($this->fieldTypes),
            fn(string $type): bool => !(new $this->fieldTypes[$type]())->isInput(),
        ));
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
            $types[$type] = ['type' => $type, 'label' => $class::getLabel()];
        }
        return $types;
    }
}
