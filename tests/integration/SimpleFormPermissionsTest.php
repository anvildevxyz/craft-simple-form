<?php

namespace fabianhaef\simpleform\tests\integration;

use fabianhaef\simpleform\helpers\SimpleFormPermissions;

/**
 * Permission definitions. Lives in the integration suite because the labels are
 * now wrapped in Craft::t() (#94), which needs a bootstrapped Craft app.
 *
 * @group requires-craft
 */
class SimpleFormPermissionsTest extends SimpleFormTestCase
{
    public function testPermissionKeysAreDefined(): void
    {
        $this->assertSame('simple-form:manageForms', SimpleFormPermissions::MANAGE_FORMS);
        $this->assertSame('simple-form:viewSubmissions', SimpleFormPermissions::VIEW_SUBMISSIONS);
        $this->assertSame('simple-form:manageSubmissions', SimpleFormPermissions::MANAGE_SUBMISSIONS);
        $this->assertSame('simple-form:manageIntegrations', SimpleFormPermissions::MANAGE_INTEGRATIONS);
        $this->assertSame('simple-form:manageSettings', SimpleFormPermissions::MANAGE_SETTINGS);
    }

    public function testDefinitionsReturnsValidStructure(): void
    {
        $this->requireCraft();
        $defs = SimpleFormPermissions::definitions();

        $this->assertIsArray($defs);
        $this->assertArrayHasKey('heading', $defs);
        $this->assertArrayHasKey('permissions', $defs);
        $this->assertSame('Simple Form', $defs['heading']);
    }

    public function testDefinitionsIncludesAllPermissions(): void
    {
        $this->requireCraft();
        $permissions = SimpleFormPermissions::definitions()['permissions'];

        $this->assertArrayHasKey(SimpleFormPermissions::MANAGE_FORMS, $permissions);
        $this->assertArrayHasKey(SimpleFormPermissions::VIEW_SUBMISSIONS, $permissions);
        $this->assertArrayHasKey(SimpleFormPermissions::MANAGE_SETTINGS, $permissions);
    }

    public function testManageIntegrationsIsNestedUnderManageForms(): void
    {
        $this->requireCraft();
        $manageForms = SimpleFormPermissions::definitions()['permissions'][SimpleFormPermissions::MANAGE_FORMS];

        $this->assertArrayHasKey('nested', $manageForms);
        $this->assertArrayHasKey(SimpleFormPermissions::MANAGE_INTEGRATIONS, $manageForms['nested']);
    }

    public function testManageSubmissionsIsNestedUnderViewSubmissions(): void
    {
        $this->requireCraft();
        $viewSubmissions = SimpleFormPermissions::definitions()['permissions'][SimpleFormPermissions::VIEW_SUBMISSIONS];

        $this->assertArrayHasKey('nested', $viewSubmissions);
        $this->assertArrayHasKey(SimpleFormPermissions::MANAGE_SUBMISSIONS, $viewSubmissions['nested']);
    }

    public function testEachPermissionHasLabel(): void
    {
        $this->requireCraft();
        foreach (SimpleFormPermissions::definitions()['permissions'] as $key => $perm) {
            $this->assertArrayHasKey('label', $perm, "Permission $key missing label");
            $this->assertIsString($perm['label']);
            $this->assertNotEmpty($perm['label']);
        }
    }
}
