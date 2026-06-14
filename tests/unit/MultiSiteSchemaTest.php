<?php

namespace fabianhaef\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-level assertions on the install migration: the multi-site schema must
 * key shared data by element id and split translatable content into per-site tables.
 */
class MultiSiteSchemaTest extends TestCase
{
    private function migration(): string
    {
        return file_get_contents(__DIR__ . '/../../src/migrations/m240614_000001_init.php');
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
        $formsBlock = substr(
            $code,
            strpos($code, "createTable('{{%simpleform_forms}}'"),
            strpos($code, "createTable('{{%simpleform_forms_sites}}'") - strpos($code, "createTable('{{%simpleform_forms}}'")
        );
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
        $sitesBlock = substr($code, strpos($code, "createTable('{{%simpleform_forms_sites}}'"));
        foreach (['description', 'emailTo', 'emailSubject', 'emailReplyTo', 'formId', 'siteId'] as $col) {
            $this->assertStringContainsString("'$col'", $sitesBlock, "forms_sites should hold $col");
        }
    }

    public function testFieldsTableHasRequiredColumnAndNoLabel(): void
    {
        $code = $this->migration();
        $fieldsBlock = substr(
            $code,
            strpos($code, "createTable('{{%simpleform_fields}}'"),
            strpos($code, "createTable('{{%simpleform_fields_sites}}'") - strpos($code, "createTable('{{%simpleform_fields}}'")
        );
        $this->assertStringContainsString("'required'", $fieldsBlock);
        $this->assertStringNotContainsString("'label'", $fieldsBlock);
        $this->assertStringNotContainsString("'helpText'", $fieldsBlock);
    }

    public function testPerSiteFieldTableHoldsLabelAndHelpText(): void
    {
        $code = $this->migration();
        $sitesBlock = substr($code, strpos($code, "createTable('{{%simpleform_fields_sites}}'"));
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
