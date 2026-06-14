<?php

/**
 * Craft integration-suite bootstrap.
 *
 * This is the bootstrap referenced by the ROOT codeception.yml (integration
 * suite only). It boots a real Craft via craft\test\TestSetup against the
 * plugin's OWN vendor/ (CRAFT_VENDOR_PATH below), isolated from the smoke
 * suite's tests/_bootstrap.php (which loads the PROJECT-root vendor for the
 * browser-based FunctionalTester flow). Keep the two bootstraps separate.
 */

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'templates');
define('CRAFT_CONFIG_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor');
define('CRAFT_ROOT_PATH', dirname(__DIR__));

TestSetup::configureCraft();
