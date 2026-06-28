<?php

namespace anvildev\simpleform\migrations;

use Craft;
use craft\db\Migration;

/**
 * Rewrite persisted `fabianhaef\simpleform\…` class names to `anvildev\simpleform\…`.
 *
 * The namespace/package rename (fabianhaef -> anvildev) changed the FQCN of every
 * element, field, and widget class, but those FQCNs are stored verbatim in the
 * database (and project config) of any install that ran an earlier release. Without
 * this rewrite Craft can no longer resolve the saved `type`, so existing forms,
 * submissions, Form fields, and dashboard widgets all read as a missing component
 * type and vanish from the CP — apparent total data loss.
 *
 * Element content and dashboard widgets live only in the DB, so those columns are
 * patched directly. The Form field type is also project-config-managed, so its
 * `type` is rewritten there too (Craft re-syncs the `{{%fields}}` table from it).
 *
 * @author Fabian Haefliger
 */
class m260628_000001_rename_fqcns extends Migration
{
    private const OLD_PREFIX = 'fabianhaef\\simpleform\\';
    private const NEW_PREFIX = 'anvildev\\simpleform\\';

    /**
     * Persisted component classes, by table. Listed explicitly (rather than a
     * blanket prefix REPLACE) so the rewrite is scoped to this plugin's rows.
     *
     * @var array<string, list<string>>
     */
    private const RENAMES = [
        '{{%elements}}' => [
            'elements\\Form',
            'elements\\Submission',
        ],
        '{{%widgets}}' => [
            'widgets\\RecentSubmissionsWidget',
            'widgets\\SubmissionCountWidget',
        ],
        '{{%fields}}' => [
            'fields\\FormField',
        ],
    ];

    public function safeUp(): bool
    {
        foreach (self::RENAMES as $table => $classes) {
            foreach ($classes as $class) {
                $this->update(
                    $table,
                    ['type' => self::NEW_PREFIX . $class],
                    ['type' => self::OLD_PREFIX . $class],
                    [],
                    false,
                );
            }
        }

        $this->_rewriteProjectConfigFields();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260628_000001_rename_fqcns cannot be reverted.\n";
        return false;
    }

    /**
     * Rewrite the Form field type in project config so a later project-config
     * apply doesn't revert the `{{%fields}}` table back to the old FQCN.
     *
     * Skipped when project config is read-only (allowAdminChanges=false): set()
     * would throw NotSupportedException and roll the whole migration back. On those
     * installs the deployed YAML is authoritative; the legacy-class-alias
     * registered in {@see \anvildev\simpleform\Plugin} keeps the old FQCN resolving
     * if the YAML still carries it.
     */
    private function _rewriteProjectConfigFields(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        if ($projectConfig->readOnly) {
            return;
        }

        $fields = $projectConfig->get('fields') ?? [];
        if (!is_array($fields)) {
            return;
        }

        $old = self::OLD_PREFIX . 'fields\\FormField';
        $new = self::NEW_PREFIX . 'fields\\FormField';
        foreach ($fields as $uid => $config) {
            if (is_array($config) && ($config['type'] ?? null) === $old) {
                $projectConfig->set("fields.$uid.type", $new);
            }
        }
    }
}
