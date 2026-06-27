<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-level assertions on the install migration: the multi-site schema must
 * key shared data by element id and split translatable content into per-site tables.
 */
class MultiSiteSchemaTest extends TestCase
{
    private function migration(): string
    {
        $code = file_get_contents(__DIR__ . '/../../src/migrations/Install.php');
        $this->assertNotFalse($code);
        return $code;
    }

    private function offsetOf(string $haystack, string $needle): int
    {
        $pos = strpos($haystack, $needle);
        $this->assertNotFalse($pos, "Expected to find: $needle");
        return $pos;
    }

    public function testCreatesPerSiteContentTables(): void
    {
        $code = $this->migration();
        $this->assertStringContainsString('{{%simpleform_forms_sites}}', $code);
        $this->assertStringContainsString('{{%simpleform_fields_sites}}', $code);
    }

    public function testSharedFormsTableHasNoSiteIdColumn(): void
    {
        $code = $this->migration();
        // The shared forms table block must not declare a siteId column.
        $formsStart = $this->offsetOf($code, "createTable('{{%simpleform_forms}}'");
        $sitesStart = $this->offsetOf($code, "createTable('{{%simpleform_forms_sites}}'");
        $formsBlock = substr($code, $formsStart, $sitesStart - $formsStart);
        $this->assertStringNotContainsString("'siteId'", $formsBlock);
        $this->assertStringNotContainsString("'title'", $formsBlock);
    }

    public function testFormsTableHasPropagationMethodDefaultNone(): void
    {
        $code = $this->migration();
        $this->assertMatchesRegularExpression(
            "/'propagationMethod'\s*=>.*defaultValue\('none'\)/",
            $code
        );
    }

    public function testPerSiteFormTableHoldsTranslatableColumns(): void
    {
        $code = $this->migration();
        $sitesBlock = substr($code, $this->offsetOf($code, "createTable('{{%simpleform_forms_sites}}'"));
        foreach (['description', 'emailTo', 'emailSubject', 'emailReplyTo', 'formId', 'siteId'] as $col) {
            $this->assertStringContainsString("'$col'", $sitesBlock, "forms_sites should hold $col");
        }
    }

    public function testFieldsTableHasRequiredColumnAndNoLabel(): void
    {
        $code = $this->migration();
        $fieldsStart = $this->offsetOf($code, "createTable('{{%simpleform_fields}}'");
        $fieldsSitesStart = $this->offsetOf($code, "createTable('{{%simpleform_fields_sites}}'");
        $fieldsBlock = substr($code, $fieldsStart, $fieldsSitesStart - $fieldsStart);
        $this->assertStringContainsString("'required'", $fieldsBlock);
        $this->assertStringNotContainsString("'label'", $fieldsBlock);
        $this->assertStringNotContainsString("'helpText'", $fieldsBlock);
    }

    public function testPerSiteFieldTableHoldsLabelAndHelpText(): void
    {
        $code = $this->migration();
        $sitesBlock = substr($code, $this->offsetOf($code, "createTable('{{%simpleform_fields_sites}}'"));
        $this->assertStringContainsString("'label'", $sitesBlock);
        $this->assertStringContainsString("'helpText'", $sitesBlock);
        $this->assertStringContainsString("'fieldId'", $sitesBlock);
    }

    public function testFormHandleIsGloballyUnique(): void
    {
        $code = $this->migration();
        // unique index on handle alone (third arg true), not [handle, siteId]
        $this->assertMatchesRegularExpression(
            "/createIndex\(null, '\{\{%simpleform_forms\}\}', \['handle'\], true\)/",
            $code
        );
    }
}
