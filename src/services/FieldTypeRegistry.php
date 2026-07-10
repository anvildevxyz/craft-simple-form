<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\events\RegisterFieldTypesEvent;
use anvildev\simpleform\fields\AddressFieldType;
use anvildev\simpleform\fields\AssetRelationFieldType;
use anvildev\simpleform\fields\CalculationFieldType;
use anvildev\simpleform\fields\CalloutFieldType;
use anvildev\simpleform\fields\CategoryRelationFieldType;
use anvildev\simpleform\fields\CheckboxFieldType;
use anvildev\simpleform\fields\ConsentFieldType;
use anvildev\simpleform\fields\DateFieldType;
use anvildev\simpleform\fields\DateTimeFieldType;
use anvildev\simpleform\fields\DividerFieldType;
use anvildev\simpleform\fields\EmailFieldType;
use anvildev\simpleform\fields\EntryRelationFieldType;
use anvildev\simpleform\fields\FieldType;
use anvildev\simpleform\fields\FileFieldType;
use anvildev\simpleform\fields\HeadingFieldType;
use anvildev\simpleform\fields\HiddenFieldType;
use anvildev\simpleform\fields\HtmlFieldType;
use anvildev\simpleform\fields\NameFieldType;
use anvildev\simpleform\fields\NumberFieldType;
use anvildev\simpleform\fields\OpinionScaleFieldType;
use anvildev\simpleform\fields\ParagraphFieldType;
use anvildev\simpleform\fields\PaymentFieldType;
use anvildev\simpleform\fields\PhoneFieldType;
use anvildev\simpleform\fields\RadioFieldType;
use anvildev\simpleform\fields\RatingFieldType;
use anvildev\simpleform\fields\RepeaterFieldType;
use anvildev\simpleform\fields\SelectFieldType;
use anvildev\simpleform\fields\SignatureFieldType;
use anvildev\simpleform\fields\TagRelationFieldType;
use anvildev\simpleform\fields\TextareaFieldType;
use anvildev\simpleform\fields\TextFieldType;
use anvildev\simpleform\fields\TimeFieldType;
use anvildev\simpleform\fields\UrlFieldType;
use anvildev\simpleform\fields\UserRelationFieldType;
use anvildev\simpleform\Plugin;
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

    /**
     * Field types whose stored value is a list of asset ids (file uploads,
     * signature PNGs). Consumed by the CSV exporter (rendered as asset URLs) and
     * the retention GC (collected for deletion).
     *
     * @var list<string>
     */
    public const ASSET_TYPES = ['file', 'signature'];

    /** @var array<string, class-string<FieldType>> */
    private array $fieldTypes = [];

    public function init(): void
    {
        parent::init();

        foreach ([
            TextFieldType::class,
            EmailFieldType::class,
            UrlFieldType::class,
            TextareaFieldType::class,
            SelectFieldType::class,
            CheckboxFieldType::class,
            RadioFieldType::class,
            DateFieldType::class,
            TimeFieldType::class,
            DateTimeFieldType::class,
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
            ParagraphFieldType::class,
            CalloutFieldType::class,
        ] as $class) {
            $this->registerFieldType($class);
        }

        // Let third parties contribute their own. Fired on the Plugin class so the
        // registration ergonomics match integration types / captcha providers /
        // stencils. Guarded on the Craft app so unit tests (no bootstrap, no `Yii`
        // alias) skip it cleanly.
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            return;
        }

        if (($plugin = Plugin::getInstance()) !== null) {
            $plugin->trigger(Plugin::EVENT_REGISTER_FIELD_TYPES, $event = new RegisterFieldTypesEvent());
            foreach ($event->types as $class) {
                $this->registerFieldType($class);
            }
        }
    }

    /**
     * The registered non-input (presentational/layout) field-type handles —
     * heading, divider, html, paragraph, callout. Skipped by validation, storage, and export.
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
        if (!is_subclass_of($class, FieldType::class)) {
            throw new \InvalidArgumentException("Field type must extend FieldType: $class");
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
