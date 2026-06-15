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
    private ?string $errorMessage;

    /**
     * @param array<string, mixed> $config
     * @param string|null $errorMessage optional per-site validation message override
     */
    public function __construct(int $id, string $type, string $name, ?string $label = null, array $config = [], ?string $errorMessage = null)
    {
        parent::__construct();
        $this->id = $id;
        $this->type = $type;
        $this->name = $name;
        $this->label = $label;
        $this->config = $config;
        $this->errorMessage = $errorMessage;
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

            return self::applyOverride($fieldType->validate($value), $this->errorMessage);
        } catch (\Throwable $e) {
            Craft::warning(sprintf('Field validation error: %s', $e->getMessage()), 'simple-form');
            return ['Validation error occurred'];
        }
    }

    /**
     * Replace a field's default validation errors with the editor's per-site
     * override message when one is set, so a failed submission speaks in the
     * site's own wording. With no override (the common case) the localized
     * defaults pass through untouched, so messages are never blank.
     *
     * Pure and side-effect free for straightforward unit testing.
     *
     * @param string[] $errors
     * @return string[]
     */
    public static function applyOverride(array $errors, ?string $override): array
    {
        $override = $override !== null ? trim($override) : '';
        if ($errors === [] || $override === '') {
            return $errors;
        }

        return [$override];
    }
}
