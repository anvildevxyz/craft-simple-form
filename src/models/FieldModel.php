<?php

namespace fabianhaef\simpleform\models;

use Craft;
use fabianhaef\simpleform\helpers\ConditionalEvaluator;
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
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Is this field visible given the full set of submitted values?
     *
     * Fields with no conditional logic are always visible, so this is a no-op
     * for existing forms.
     *
     * @param array<string, mixed> $formData posted values keyed by field handle
     */
    public function isVisible(array $formData = []): bool
    {
        return ConditionalEvaluator::isVisible($this->config, $formData);
    }

    /**
     * Is this field required for the given submitted values?
     *
     * The static `required` flag and any conditional-required rule are ORed
     * together. (Callers must still treat hidden fields as not-required via
     * {@see self::isVisible()} — visibility wins.)
     *
     * @param array<string, mixed> $formData posted values keyed by field handle
     */
    public function isRequired(array $formData = []): bool
    {
        return ($this->config['required'] ?? false)
            || ConditionalEvaluator::isRequiredByCondition($this->config, $formData);
    }

    /**
     * Validate a single field value against the full submitted snapshot.
     *
     * Conditional logic is honored: a field hidden by its conditions is never
     * validated, and its effective required-ness is the OR of the static flag
     * and any conditional-required rule.
     *
     * @param array<string, mixed> $formData posted values keyed by field handle
     * @return string[]
     */
    public function validateValue(mixed $value, array $formData): array
    {
        // Hidden fields are never validated — their value is moot.
        if (!$this->isVisible($formData)) {
            return [];
        }

        try {
            $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();

            // Resolve effective required-ness (static OR conditional) and let the
            // field type enforce it, so there is a single required code path.
            $config = $this->config;
            $config['required'] = $this->isRequired($formData);

            $fieldType = $fieldTypeRegistry->getFieldType($this->type, $config);

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
     * Transform a validated value into the shape persisted in the submission's
     * `data` payload. For most field types this is an identity pass-through; the
     * Consent field replaces the raw `"1"` with its auditable consent record.
     *
     * @param array<string, mixed> $context per-submission context (e.g. `siteId`)
     */
    public function persistValue(mixed $value, array $context = []): mixed
    {
        $fieldType = Plugin::getInstance()->getFieldTypeRegistry()->getFieldType($this->type, $this->config);
        if (!$fieldType) {
            return $value;
        }

        return $fieldType->persistValue($value, $context);
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
