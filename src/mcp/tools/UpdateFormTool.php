<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\FormPresenter;
use Craft;
use craft\enums\PropagationMethod;

/**
 * MCP tool: update a form's metadata.
 *
 * Loads the existing {@see Form} on the target site and re-saves it through the
 * CP's element path, so only the supplied attributes change while validation,
 * events, per-site content and cache invalidation behave exactly as in the CP.
 */
class UpdateFormTool implements ToolInterface
{
    public function name(): string
    {
        return 'update_form';
    }

    public function description(): string
    {
        return 'Update a Simple Form form\'s metadata (by id or handle). Only the supplied '
            . 'attributes change; validation runs the same as the Control Panel and errors '
            . 'are returned in-band.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The form id. Provide id OR handle.'],
                'handle' => ['type' => 'string', 'description' => 'The form handle to identify it. Provide id OR handle.'],
                'name' => ['type' => 'string', 'description' => 'New name (shared).'],
                'newHandle' => ['type' => 'string', 'description' => 'New handle (shared, globally unique).'],
                'title' => ['type' => 'string', 'description' => 'New per-site title.'],
                'description' => ['type' => 'string', 'description' => 'New per-site description.'],
                'emailTo' => ['type' => 'string', 'description' => 'New notification recipient (per-site).'],
                'emailSubject' => ['type' => 'string', 'description' => 'New notification subject (per-site).'],
                'emailBody' => ['type' => 'string', 'description' => 'New notification body template, Twig with form/submission/data (per-site). Blank uses the default.'],
                'emailReplyTo' => ['type' => 'string', 'description' => 'New notification reply-to (per-site).'],
                'siteId' => ['type' => 'integer', 'description' => 'Site whose per-site content to update. Defaults to the form\'s resolved site.'],
                'propagationMethod' => [
                    'type' => 'string',
                    'enum' => Form::SUPPORTED_PROPAGATION_METHODS,
                    'description' => 'New propagation method.',
                ],
            ],
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
        $form = FormPresenter::resolveByIdOrHandle($arguments);
        if (is_array($form)) {
            return $form;
        }

        // Re-load on the requested site so per-site content updates land there.
        if (isset($arguments['siteId'])) {
            $siteId = (int)$arguments['siteId'];
            $onSite = Form::find()->id($form->id)->siteId($siteId)->status(null)->one();
            if ($onSite instanceof Form) {
                $form = $onSite;
            } else {
                $form->siteId = $siteId;
            }
        }

        if (array_key_exists('name', $arguments)) {
            $form->name = (string)$arguments['name'];
        }
        if (array_key_exists('newHandle', $arguments)) {
            $form->handle = (string)$arguments['newHandle'];
        }
        if (array_key_exists('title', $arguments)) {
            $form->title = (string)$arguments['title'];
        }
        if (array_key_exists('description', $arguments)) {
            $form->description = (string)$arguments['description'];
        }
        if (array_key_exists('emailTo', $arguments)) {
            $form->emailTo = (string)$arguments['emailTo'];
        }
        if (array_key_exists('emailSubject', $arguments)) {
            $form->emailSubject = (string)$arguments['emailSubject'];
        }
        if (array_key_exists('emailBody', $arguments)) {
            $form->emailBody = (string)$arguments['emailBody'];
        }
        if (array_key_exists('emailReplyTo', $arguments)) {
            $form->emailReplyTo = (string)$arguments['emailReplyTo'];
        }
        if (isset($arguments['propagationMethod']) && is_string($arguments['propagationMethod'])) {
            $form->propagationMethod = PropagationMethod::tryFrom($arguments['propagationMethod']) ?? $form->propagationMethod;
        }

        if (!Craft::$app->getElements()->saveElement($form)) {
            return ['isError' => true, 'errors' => $form->getErrors()];
        }

        return ['form' => FormPresenter::form($form)];
    }
}
