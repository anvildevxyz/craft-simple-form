<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\TwigExtension;

/**
 * @group requires-craft
 */
class FormStructureCacheTest extends SimpleFormTestCase
{
    /**
     * The cache service bypasses caching in devMode (and the test app runs with
     * devMode on), so enable an in-memory cache and force devMode off for the
     * cache-on assertions, restoring both afterwards.
     *
     * @var array{cache: mixed, devMode: bool}|null
     */
    private ?array $restore = null;

    protected function tearDown(): void
    {
        if ($this->restore !== null) {
            Craft::$app->set('cache', $this->restore['cache']);
            Craft::$app->getConfig()->getGeneral()->devMode = $this->restore['devMode'];
            $this->restore = null;
        }
        parent::tearDown();
    }

    private function enableCaching(): void
    {
        $general = Craft::$app->getConfig()->getGeneral();
        $this->restore = [
            'cache' => Craft::$app->getCache(),
            'devMode' => $general->devMode,
        ];
        $general->devMode = false;
        Craft::$app->set('cache', new \yii\caching\ArrayCache());
    }

    public function testWarmCacheOutputMatchesColdCache(): void
    {
        $this->requireCraft();
        $this->enableCaching();

        $form = $this->createForm('Cached', 'cachedForm', 'Cached Form');
        $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $ext = new TwigExtension();

        // Cold render (populates the cache) then warm render (served from cache).
        $cold = $ext->renderForm('cachedForm');
        $warm = $ext->renderForm('cachedForm');

        // CSRF inputs differ per request; strip them before comparing structure.
        $this->assertSame($this->stripDynamic($cold), $this->stripDynamic($warm));
        $this->assertStringContainsString('Full Name', $warm);
    }

    public function testCsrfTokenStaysFreshPerRequest(): void
    {
        $this->requireCraft();
        $this->enableCaching();

        $form = $this->createForm('Csrf', 'csrfForm', 'Csrf Form');
        $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $ext = new TwigExtension();
        $ext->renderForm('csrfForm'); // warm

        $html = $ext->renderForm('csrfForm');
        // A CSRF hidden input must be present on every (cached-structure) render.
        $this->assertMatchesRegularExpression('/name="CRAFT_CSRF_TOKEN"|csrf/i', $html);
    }

    public function testSavingFieldChangeInvalidatesCache(): void
    {
        $this->requireCraft();
        $this->enableCaching();

        $form = $this->createForm('Invalidate', 'invalidateForm', 'Invalidate Form');
        $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $ext = new TwigExtension();
        $first = $ext->renderForm('invalidateForm');
        $this->assertStringContainsString('Full Name', $first);

        // Add another field directly, then invalidate via the documented method.
        $newFieldId = $this->createField($form->id, 'email', 'email', 'Email Address', true);
        Plugin::getInstance()->getFormStructure()->invalidate((int)$form->id);

        $second = $ext->renderForm('invalidateForm');
        $this->assertStringContainsString('Email Address', $second);
        $this->assertStringContainsString('name="field_' . $newFieldId . '"', $second);
    }

    public function testCachingDisabledAlwaysReflectsCurrentDb(): void
    {
        $this->requireCraft();
        // No enableCaching(): the test app's devMode-on path bypasses the cache,
        // so a new field must show up without any explicit invalidation.

        $form = $this->createForm('Fresh', 'freshForm', 'Fresh Form');
        $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $ext = new TwigExtension();
        $ext->renderForm('freshForm');

        $newFieldId = $this->createField($form->id, 'email', 'email', 'Email Address', true);

        $second = $ext->renderForm('freshForm');
        $this->assertStringContainsString('name="field_' . $newFieldId . '"', $second);
    }

    public function testWarmMethodPopulatesCacheForAllSites(): void
    {
        $this->requireCraft();
        $this->enableCaching();

        $form = $this->createForm('Warm', 'warmForm', 'Warm Form');
        $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $warmed = Plugin::getInstance()->getFormStructure()->warm((int)$form->id);
        $this->assertGreaterThanOrEqual(1, $warmed);
        $this->assertSame(count(Craft::$app->getSites()->getAllSites()), $warmed);
    }

    private function stripDynamic(string $html): string
    {
        // Remove the CSRF hidden input (its token rotates per request).
        return (string)preg_replace('/<input[^>]*CRAFT_CSRF_TOKEN[^>]*>/i', '', $html);
    }
}
