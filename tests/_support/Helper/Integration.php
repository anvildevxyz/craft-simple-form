<?php

namespace Helper;

use Codeception\Module;
use Codeception\TestInterface;

class Integration extends Module
{
    /**
     * Runs after the Craft module's _before (which boots the test app and opens
     * the per-test transaction). Under the CLI SAPI the app flags its request as
     * a console request, so simple-form's front-end-only code (Twig form render,
     * submit handling) would treat every test as an admin/console context. Pin a
     * front-end web request so those paths run as they would on the site — the
     * Craft module's transaction still rolls back anything they write.
     */
    public function _before(TestInterface $test): void
    {
        $request = \Craft::$app->getRequest();
        $request->setIsConsoleRequest(false);
        if (method_exists($request, 'setIsCpRequest')) {
            $request->setIsCpRequest(false);
        }
        // Twig form rendering emits csrfInput(), which reads the CSRF cookie token;
        // the CLI test request has no cookie validation key configured, so set one.
        if (empty($request->cookieValidationKey)) {
            $request->cookieValidationKey = 'simple-form-test-cookie-validation-key';
        }

        // Pin the full Pro edition by default. With editions() = [solo, pro], a
        // freshly-resolved plugin defaults to the lowest edition (Solo); both
        // suites exercise Pro features, and the dedicated edition tests flip to
        // Solo per-test. Covers integration + smoke uniformly.
        $plugin = \anvildev\simpleform\Plugin::getInstance();
        if ($plugin !== null) {
            $plugin->edition = \anvildev\simpleform\Plugin::EDITION_PRO;
        }
    }
}
