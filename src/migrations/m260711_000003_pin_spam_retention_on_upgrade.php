<?php

namespace anvildev\simpleform\migrations;

use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Migration;

/**
 * Preserve pre-#338 spam behavior on upgrade. #338 gives `retainSpamDays` a
 * non-zero (30) default so a fresh install bounds its flag-mode spam pile out of
 * the box. An install upgrading INTO this release never chose a spam window and
 * may be intentionally keeping flagged spam for review, so this pins it to 0
 * (keep forever) rather than letting the next garbage-collection run silently
 * hard-delete spam older than 30 days. Fresh installs run Install.php (not this
 * delta migration), so they keep the 30-day default.
 *
 * Only pins when the value isn't already set in project config, and is a no-op
 * (with a note) when project config is read-only (`allowAdminChanges = false`) —
 * such installs manage `retainSpamDays` through their own config and are called
 * out in the changelog.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260711_000003_pin_spam_retention_on_upgrade extends Migration
{
    public function safeUp(): bool
    {
        $handle = Plugin::getInstance()->handle;
        $key = "plugins.$handle.settings.retainSpamDays";
        $projectConfig = Craft::$app->getProjectConfig();

        if ($projectConfig->get($key) !== null) {
            // The upgrading site already set a spam window explicitly — respect it.
            return true;
        }

        try {
            $projectConfig->set($key, 0, 'Preserve keep-spam-forever behavior for a pre-#338 install');
        } catch (\Throwable $e) {
            echo "    > could not pin retainSpamDays ({$e->getMessage()}); the new 30-day default applies — set it explicitly if you keep spam for review.\n";
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Nothing to reverse — the setting is owner-configurable.
        return true;
    }
}
