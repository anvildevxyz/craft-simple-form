<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\fields\RadioFieldType;
use fabianhaef\simpleform\fields\SelectFieldType;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\services\FieldSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Per-site translatable option labels (issue #58). These exercise the two pure
 * seams the feature hangs on — {@see FieldQueryHelper::applyOptionLabels()}
 * (render overlay + fallback) and {@see FieldSyncService::splitOptionLabels()}
 * (persist + value-alignment) — plus the rendered HTML they produce, all without
 * a bootstrapped Craft application.
 */
class OptionLabelTest extends TestCase
{
    /** @return array<string,mixed> */
    private function config(): array
    {
        return ['options' => [
            ['value' => 'red', 'label' => 'Red'],
            ['value' => 'blue', 'label' => 'Blue'],
        ]];
    }

    public function testOverrideReplacesLabelButKeepsValue(): void
    {
        $out = FieldQueryHelper::applyOptionLabels($this->config(), ['red' => 'Rouge', 'blue' => 'Bleu']);

        $this->assertSame('Rouge', $out['options'][0]['label']);
        $this->assertSame('Bleu', $out['options'][1]['label']);
        // Values are canonical and must never change across sites.
        $this->assertSame('red', $out['options'][0]['value']);
        $this->assertSame('blue', $out['options'][1]['value']);
    }

    public function testMissingOrEmptyOverrideFallsBackToSourceLabel(): void
    {
        // 'blue' has no translation; 'red' has an empty one — both fall back.
        $out = FieldQueryHelper::applyOptionLabels($this->config(), ['red' => '']);

        $this->assertSame('Red', $out['options'][0]['label']);
        $this->assertSame('Blue', $out['options'][1]['label']);
    }

    public function testOrphanOverrideForUnknownValueIsIgnored(): void
    {
        // A translation left over from a since-removed option must not appear.
        $out = FieldQueryHelper::applyOptionLabels($this->config(), ['green' => 'Vert']);

        $this->assertSame('Red', $out['options'][0]['label']);
        $this->assertSame('Blue', $out['options'][1]['label']);
        $this->assertCount(2, $out['options']);
    }

    public function testApplyIsNoOpWithoutOptionsOrOverrides(): void
    {
        $this->assertSame(['minLength' => 3], FieldQueryHelper::applyOptionLabels(['minLength' => 3], ['x' => 'y']));
        $this->assertSame($this->config(), FieldQueryHelper::applyOptionLabels($this->config(), []));
    }

    public function testSplitExtractsSiteLabelsAndStripsThemFromConfig(): void
    {
        $config = ['options' => [
            ['value' => 'red', 'label' => 'Red', 'siteLabel' => 'Rouge'],
            ['value' => 'blue', 'label' => 'Blue', 'siteLabel' => ''],
        ]];

        [$clean, $labels] = FieldSyncService::splitOptionLabels($config);

        // Only non-empty translations survive, keyed by value.
        $this->assertSame(['red' => 'Rouge'], $labels);
        // The transient siteLabel never reaches the shared config.
        $this->assertArrayNotHasKey('siteLabel', $clean['options'][0]);
        $this->assertArrayNotHasKey('siteLabel', $clean['options'][1]);
        // Source label + value are preserved.
        $this->assertSame('Red', $clean['options'][0]['label']);
        $this->assertSame('blue', $clean['options'][1]['value']);
    }

    public function testSplitKeepsLabelsAlignedToValuesAfterReorder(): void
    {
        // Translations ride with their option, so reordering can't misalign them.
        $config = ['options' => [
            ['value' => 'blue', 'label' => 'Blue', 'siteLabel' => 'Bleu'],
            ['value' => 'red', 'label' => 'Red', 'siteLabel' => 'Rouge'],
        ]];

        [, $labels] = FieldSyncService::splitOptionLabels($config);

        $this->assertSame('Bleu', $labels['blue']);
        $this->assertSame('Rouge', $labels['red']);
    }

    public function testSelectRendersLocalizedLabelsWithFallbackAndStableValues(): void
    {
        $config = FieldQueryHelper::applyOptionLabels($this->config(), ['red' => 'Rouge']);
        $html = (new SelectFieldType($config))->renderInput('field_1');

        // Localized label shows for the translated option...
        $this->assertStringContainsString('>Rouge</option>', $html);
        // ...and the untranslated option falls back to its source label.
        $this->assertStringContainsString('>Blue</option>', $html);
        // Submitted values are identical across sites.
        $this->assertStringContainsString('value="red"', $html);
        $this->assertStringContainsString('value="blue"', $html);
        $this->assertStringNotContainsString('>Red</option>', $html);
    }

    public function testRadioRendersLocalizedLabels(): void
    {
        $config = FieldQueryHelper::applyOptionLabels($this->config(), ['blue' => 'Bleu']);
        $html = (new RadioFieldType($config))->renderInput('field_1');

        $this->assertStringContainsString('Bleu', $html);
        $this->assertStringContainsString('Red', $html);
        $this->assertStringContainsString('value="blue"', $html);
    }
}
