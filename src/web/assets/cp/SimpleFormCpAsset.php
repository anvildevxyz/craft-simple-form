<?php

namespace anvildev\simpleform\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control-panel CSS/JS for Simple Form's CP screens (forms index + builder,
 * submissions index + detail, per-form integrations, spam settings).
 *
 * Consolidates what used to be per-template inline <style>/<script> blocks
 * (#100) into one versioned, cache-bustable bundle. Both files are written to
 * be inert when their target elements are absent, so the bundle is safe to
 * register on any CP screen. Craft publishes the dist/ directory to
 * cpresources with a content hash.
 */
class SimpleFormCpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [CpAsset::class];

        $this->css = ['css/cp.css'];
        $this->js = ['js/cp.js', 'js/form-builder.js'];

        parent::init();
    }
}
