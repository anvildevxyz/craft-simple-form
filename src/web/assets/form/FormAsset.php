<?php

namespace anvildev\simpleform\web\assets\form;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * Front-end CSS/JS for rendered forms.
 *
 * Registered by the Twig render path only when a form is actually output, so
 * form-less pages carry no extra asset weight. Craft publishes the `dist/`
 * directory to `cpresources`/`web` with a content hash, giving versioned,
 * cache-bustable URLs the browser can cache across requests.
 */
class FormAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->css = ['css/simple-form.css'];
        $this->js = ['js/simple-form.js'];

        // Front-end forms aren't tied to jQuery; load the script at the end of body.
        $this->jsOptions = ['position' => View::POS_END];

        parent::init();
    }

    /**
     * Absolute path to a file inside the bundle's `dist/` source directory, used
     * by the inline-asset fallback to read the same CSS/JS the bundle serves.
     */
    public static function distPath(string $relative): string
    {
        return __DIR__ . '/dist/' . ltrim($relative, '/');
    }
}
