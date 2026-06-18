<?php

namespace fabianhaef\simpleform\helpers;

class SimpleFormPermissions
{
    public const MANAGE_FORMS = 'simple-form:manageForms';
    public const VIEW_SUBMISSIONS = 'simple-form:viewSubmissions';
    public const MANAGE_SUBMISSIONS = 'simple-form:manageSubmissions';
    public const MANAGE_INTEGRATIONS = 'simple-form:manageIntegrations';
    public const MANAGE_SETTINGS = 'simple-form:manageSettings';

    /**
     * @return array<string, mixed>
     */
    public static function definitions(): array
    {
        return [
            'heading' => 'Simple Form',
            'permissions' => [
                self::MANAGE_FORMS => [
                    'label' => 'Manage forms and fields',
                    'nested' => [
                        self::MANAGE_INTEGRATIONS => ['label' => 'Manage form integrations'],
                    ],
                ],
                self::VIEW_SUBMISSIONS => [
                    'label' => 'View submissions',
                    'nested' => [
                        self::MANAGE_SUBMISSIONS => ['label' => 'Toggle submission read status'],
                    ],
                ],
                self::MANAGE_SETTINGS => ['label' => 'Manage plugin settings'],
            ],
        ];
    }
}
