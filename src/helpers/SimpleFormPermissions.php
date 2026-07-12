<?php

namespace anvildev\simpleform\helpers;

use Craft;

/**
 * The plugin's user permissions and their labels — the single source of truth
 * shared by permission registration and the requirePermission() guards.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class SimpleFormPermissions
{
    public const MANAGE_FORMS = 'simple-form:manageForms';
    public const EDIT_HTML_BLOCKS = 'simple-form:editHtmlBlocks';
    public const VIEW_SUBMISSIONS = 'simple-form:viewSubmissions';
    public const MANAGE_SUBMISSIONS = 'simple-form:manageSubmissions';
    public const MANAGE_INTEGRATIONS = 'simple-form:manageIntegrations';
    public const MANAGE_SETTINGS = 'simple-form:manageSettings';

    /**
     * @return array<string, mixed>
     */
    public static function definitions(): array
    {
        $t = static fn(string $m): string => Craft::t('simple-form', $m);

        return [
            'heading' => $t('Simple Form'),
            'permissions' => [
                self::MANAGE_FORMS => [
                    'label' => $t('Manage forms and fields'),
                    'nested' => [
                        self::MANAGE_INTEGRATIONS => ['label' => $t('Manage form integrations')],
                        self::EDIT_HTML_BLOCKS => ['label' => $t('Edit HTML layout blocks')],
                    ],
                ],
                self::VIEW_SUBMISSIONS => [
                    'label' => $t('View submissions'),
                    'nested' => [
                        self::MANAGE_SUBMISSIONS => ['label' => $t('Toggle submission read status')],
                    ],
                ],
                self::MANAGE_SETTINGS => ['label' => $t('Manage plugin settings')],
            ],
        ];
    }
}
