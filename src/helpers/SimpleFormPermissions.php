<?php

namespace fabianhaef\simpleform\helpers;

use Craft;

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
            'heading' => Craft::t('simple-form', 'Simple Form'),
            'permissions' => [
                self::MANAGE_FORMS => [
                    'label' => Craft::t('simple-form', 'Manage forms and fields'),
                    'nested' => [
                        self::MANAGE_INTEGRATIONS => ['label' => Craft::t('simple-form', 'Manage form integrations')],
                    ],
                ],
                self::VIEW_SUBMISSIONS => [
                    'label' => Craft::t('simple-form', 'View submissions'),
                    'nested' => [
                        self::MANAGE_SUBMISSIONS => ['label' => Craft::t('simple-form', 'Toggle submission read status')],
                    ],
                ],
                self::MANAGE_SETTINGS => ['label' => Craft::t('simple-form', 'Manage plugin settings')],
            ],
        ];
    }
}
