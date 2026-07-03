<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\fields\HtmlFieldType;
use anvildev\simpleform\helpers\SubmissionCsv;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\TwigExtension;
use Craft;

/**
 * #127 — value-less layout blocks (heading, divider, html) thread through the
 * whole pipeline without contributing a value, a column, or a validation error,
 * and the HTML block renders through the forced sandbox + purifier.
 *
 * @group requires-craft
 */
class LayoutBlocksTest extends SimpleFormTestCase
{
    // =========================================================================
    // Submission / export skip
    // =========================================================================

    public function testSubmissionSkipsLayoutBlocks(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutSubmit');
        // A heading + divider + html block interleaved with two inputs.
        $this->createField($form->id, 'heading', 'sectionA', 'Personal details', false, ['level' => 'h2']);
        $nameId = $this->createField($form->id, 'text', 'name', 'Full Name');
        $this->createField($form->id, 'divider', 'div1', 'More');
        $emailId = $this->createField($form->id, 'email', 'email', 'Email');
        $this->createField($form->id, 'html', 'note', '', false, [], null, '<p>Hello</p>');
        $paraId = $this->createField($form->id, 'paragraph', 'intro', '', false, [], null, "Please read this.\nThanks.");

        $service = Plugin::getInstance()->getSubmissionService();
        // Forge a posted value against every layout block too — it must be ignored.
        $result = $service->submit($form, [
            'field_' . $nameId => 'Ada',
            'field_' . $emailId => 'ada@example.test',
            'field_' . $paraId => 'forged',
        ], ['skipCaptcha' => true]);

        $this->assertNull($result['errors']);
        $submission = $result['submission'];
        $this->assertNotNull($submission);

        $keys = array_keys($submission->data);
        $this->assertEqualsCanonicalizing(['field_' . $nameId, 'field_' . $emailId], $keys);
        // No heading/divider/html/paragraph entry of any kind.
        foreach ($submission->data as $entry) {
            $this->assertNotContains($entry['type'], ['heading', 'divider', 'html', 'paragraph']);
        }
    }

    public function testLayoutBlocksNeverBlockSubmissionEvenWhenForgedRequired(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutRequired');
        // A heading config with a forged `required` flag must not block submit.
        $this->createField($form->id, 'heading', 'h', 'Heading', true, ['level' => 'h3', 'required' => true]);
        $nameId = $this->createField($form->id, 'text', 'name', 'Name');

        $result = Plugin::getInstance()->getSubmissionService()->submit(
            $form,
            ['field_' . $nameId => 'Ada'],
            ['skipCaptcha' => true],
        );

        $this->assertNull($result['errors']);
        $this->assertNotNull($result['submission']);
    }

    public function testCsvExportHasNoLayoutColumns(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutCsv');
        $this->createField($form->id, 'heading', 'sectionA', 'Personal details', false, ['level' => 'h2']);
        $nameId = $this->createField($form->id, 'text', 'name', 'Full Name');
        $this->createField($form->id, 'html', 'note', '', false, [], null, '<p>x</p>');

        Plugin::getInstance()->getSubmissionService()->submit(
            $form,
            ['field_' . $nameId => 'Ada'],
            ['skipCaptcha' => true],
        );

        $submissions = Submission::find()->formId($form->id)->all();
        $csv = SubmissionCsv::fromSubmissions($submissions);

        $header = explode("\n", trim($csv))[0];
        $this->assertStringContainsString('Full Name', $header);
        $this->assertStringNotContainsString('Personal details', $header);
        $this->assertStringNotContainsString('note', $header);
        $this->assertStringNotContainsString('html', $header);
    }

    public function testIsInputTypeOnFieldModel(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutModel');
        $this->createField($form->id, 'heading', 'h', 'Heading', false, ['level' => 'h3']);
        $this->createField($form->id, 'text', 'name', 'Name');

        $fields = (new \anvildev\simpleform\models\FormModel($form))->getFields();
        $byType = [];
        foreach ($fields as $field) {
            $byType[$field->getType()] = $field->isInputType();
        }

        $this->assertFalse($byType['heading']);
        $this->assertTrue($byType['text']);
    }

    // =========================================================================
    // editHtmlBlocks permission gate
    // =========================================================================

    public function testHtmlBlockBodyChangedDetectsNewAndChangedBodies(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutGate', 'Layout');
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $htmlId = $this->createField($form->id, 'html', 'note', '', false, [], [$siteId], '<p>original</p>');

        $sync = new \anvildev\simpleform\services\FieldSyncService();

        // A new HTML block with a body is gated.
        $this->assertTrue($sync->htmlBlockBodyChanged(
            [['type' => 'html', 'id' => null, 'helpText' => '<p>new</p>', 'handle' => 'n']],
            $siteId,
        ));

        // A new HTML block with NO body is not gated.
        $this->assertFalse($sync->htmlBlockBodyChanged(
            [['type' => 'html', 'id' => null, 'helpText' => '', 'handle' => 'n']],
            $siteId,
        ));

        // An existing block whose body is unchanged is not gated (reorder/keep).
        $this->assertFalse($sync->htmlBlockBodyChanged(
            [['type' => 'html', 'id' => $htmlId, 'helpText' => '<p>original</p>', 'handle' => 'note']],
            $siteId,
        ));

        // A changed body on the existing block is gated.
        $this->assertTrue($sync->htmlBlockBodyChanged(
            [['type' => 'html', 'id' => $htmlId, 'helpText' => '<p>edited</p>', 'handle' => 'note']],
            $siteId,
        ));

        // A set with no HTML block is never gated.
        $this->assertFalse($sync->htmlBlockBodyChanged(
            [['type' => 'text', 'id' => null, 'helpText' => '', 'handle' => 't']],
            $siteId,
        ));
    }

    // =========================================================================
    // Public rendering
    // =========================================================================

    public function testPublicFormRendersLayoutBlocksInOrder(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutRender', 'Layout');
        $this->createField($form->id, 'heading', 'sectionA', 'Personal details', false, ['level' => 'h2']);
        $nameId = $this->createField($form->id, 'text', 'name', 'Full Name');
        $this->createField($form->id, 'divider', 'div1', 'And more');
        $this->createField($form->id, 'html', 'note', '', false, [], null, '<p>Read the <a href="https://example.com">policy</a>.</p>');

        $html = (new TwigExtension())->renderForm('layoutRender');

        // Heading at the configured level with escaped text; divider rule with
        // its label; purified HTML block — all present.
        $this->assertStringContainsString('<h2 class="simple-form-heading">Personal details</h2>', $html);
        $this->assertStringContainsString('simple-form-divider__label', $html);
        $this->assertStringContainsString('And more', $html);
        $this->assertStringContainsString('simple-form-html', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);

        // Order: heading before the name input, name input before the divider.
        $this->assertLessThan(strpos($html, 'name="field_' . $nameId . '"'), strpos($html, '<h2'));
        $this->assertLessThan(strpos($html, 'simple-form-divider'), strpos($html, 'name="field_' . $nameId . '"'));
    }

    public function testParagraphBlockRendersEscapedWithLineBreaksAndNoLabel(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutParagraph', 'Layout');
        // Multi-line copy containing markup: it must render as literal escaped
        // text with the line break preserved, and never as a labelled field.
        $body = "First line & <script>alert(1)</script>\nSecond line";
        $this->createField($form->id, 'paragraph', 'intro', '', false, [], null, $body);
        $nameId = $this->createField($form->id, 'text', 'name', 'Full Name');

        $html = (new TwigExtension())->renderForm('layoutParagraph');

        // Rendered as a layout block (displayMode = 'layout' → bare, no group).
        $this->assertStringContainsString('simple-form-layout--paragraph', $html);
        $this->assertStringContainsString('<div class="simple-form-text">', $html);

        // Escaped, not executed; line break preserved as <br>.
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('First line &amp;', $html);
        $this->assertMatchesRegularExpression('/First line.*<br\s*\/?>\s*\n?Second line/s', $html);

        // No label / required marker for the block; it precedes the name input.
        $this->assertLessThan(strpos($html, 'name="field_' . $nameId . '"'), strpos($html, 'simple-form-layout--paragraph'));
    }

    // =========================================================================
    // HTML block safety (forced sandbox + purifier)
    // =========================================================================

    public function testHtmlBlockRendersBenignTwigButStripsScriptVectors(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutSafe', 'Layout');
        // Benign Twig (the default filter is sandbox-allowed) mixed with script
        // vectors: the Twig renders, the vectors are purified away.
        $body = '<p onclick="x()">Hi {{ "there"|upper }}</p>'
            . '<script>alert(1)</script>'
            . '<a href="javascript:alert(2)">x</a>';
        $this->createField($form->id, 'html', 'note', '', false, [], null, $body);

        $html = (new TwigExtension())->renderForm('layoutSafe');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Hi THERE', $html);
    }

    public function testHtmlBlockWithCraftAppAccessRendersNothing(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Layout', 'layoutCraft', 'Layout');
        // The sandbox denies craft.app property access; a body that reaches for
        // it fails closed — the whole block renders to nothing and the
        // securityKey never leaks.
        $body = 'KEY={{ craft.app.config.general.securityKey ?? "" }}';
        $this->createField($form->id, 'html', 'note', '', false, [], null, $body);

        $html = (new TwigExtension())->renderForm('layoutCraft');

        $this->assertStringNotContainsString('KEY=', $html);
        $key = Craft::$app->getConfig()->getGeneral()->securityKey;
        if ($key !== '' && $key !== null) {
            $this->assertStringNotContainsString($key, $html);
        }
    }

    public function testPurifyRuntimeStripsVectorsAndKeepsSafeTags(): void
    {
        $this->requireCraft();

        $clean = HtmlFieldType::purify(
            '<p onclick="steal()">Hi</p><script>alert(1)</script>'
            . '<style>body{display:none}</style>'
            . '<img src="x" onerror="alert(2)">'
            . '<a href="javascript:alert(3)">x</a>'
            . '<a href="https://example.com" rel="noopener">ok</a><ul><li>a</li></ul>'
        );

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('<style', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('href="https://example.com"', $clean);
        $this->assertStringContainsString('<li>a</li>', $clean);
    }
}
