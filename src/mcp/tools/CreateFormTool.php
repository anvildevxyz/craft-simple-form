<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\FormPresenter;
use Craft;
use craft\enums\PropagationMethod;

/**
 * MCP tool: create a form.
 *
 * Thin adapter over the {@see Form} element — the SAME save path the CP uses
 * ({@see \Craft::$app}->getElements()->saveElement()), so validation, events,
 * the per-site content rows, propagation and the structure-cache invalidation
 * (Form::afterSave) all apply identically. Invalid input fails exactly as the CP
 * would: the element's validation errors are returned in the result (isError),
 * never a 500.
 */
class CreateFormTool implements ToolInterface
{
    public function name(): string
    {
        return 'create_form';
    }

    public function description(): string
    {
        return 'Create a new Simple Form form. Runs the same validation, events and '
            . 'multi-site rules as the Control Panel. On invalid input the element\'s '
            . 'validation errors are returned (no fields are created).';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Form name (shared across sites). Required.'],
                'handle' => ['type' => 'string', 'description' => 'Globally unique handle (shared across sites). Required.'],
                'title' => ['type' => 'string', 'description' => 'Per-site title. Defaults to the name.'],
                'description' => ['type' => 'string', 'description' => 'Per-site description.'],
                'emailTo' => ['type' => 'string', 'description' => 'Notification recipient (per-site).'],
                'emailSubject' => ['type' => 'string', 'description' => 'Notification subject (per-site).'],
                'emailBody' => ['type' => 'string', 'description' => 'Notification body template, Twig with form/submission/data (per-site). Blank uses the default.'],
                'emailReplyTo' => ['type' => 'string', 'description' => 'Notification reply-to (per-site).'],
                'siteId' => ['type' => 'integer', 'description' => 'Site to save the canonical content on. Defaults to the primary site.'],
                'propagationMethod' => [
                    'type' => 'string',
                    'enum' => Form::SUPPORTED_PROPAGATION_METHODS,
                    'description' => 'How the form propagates across sites. Defaults to "none".',
                ],
            ],
            'required' => ['name', 'handle'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return Scopes::FORMS_MANAGE;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(array $arguments): array
    {
        $form = new Form();
        $form->name = self::strOrNull($arguments, 'name');
        $form->handle = self::strOrNull($arguments, 'handle');
        $form->title = self::strOrNull($arguments, 'title') ?? ($form->name ?? null);
        $form->description = self::strOrNull($arguments, 'description');
        $form->emailTo = self::strOrNull($arguments, 'emailTo');
        $form->emailSubject = self::strOrNull($arguments, 'emailSubject');
        $form->emailBody = self::strOrNull($arguments, 'emailBody');
        $form->emailReplyTo = self::strOrNull($arguments, 'emailReplyTo');

        if (isset($arguments['propagationMethod']) && is_string($arguments['propagationMethod'])) {
            $form->propagationMethod = PropagationMethod::tryFrom($arguments['propagationMethod']) ?? PropagationMethod::None;
        }

        $siteId = isset($arguments['siteId']) ? (int)$arguments['siteId'] : (int)Craft::$app->getSites()->getPrimarySite()->id;
        $form->siteId = $siteId;

        if (!Craft::$app->getElements()->saveElement($form)) {
            // Validation parity: surface the element's own errors, do not 500.
            return ['isError' => true, 'errors' => $form->getErrors()];
        }

        return ['form' => FormPresenter::form($form)];
    }

    /**
     * Cast an argument to a string if present, else null.
     *
     * @param array<string, mixed> $arguments
     */
    private static function strOrNull(array $arguments, string $key): ?string
    {
        return isset($arguments[$key]) ? (string) $arguments[$key] : null;
    }
}
