<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use PHPUnit\Framework\TestCase;

class SimpleFormPermissionsTest extends TestCase
{
    public function testPermissionKeysAreDefined(): void
    {
        $this->assertEquals('simple-form:manageForms', SimpleFormPermissions::MANAGE_FORMS);
        $this->assertEquals('simple-form:viewSubmissions', SimpleFormPermissions::VIEW_SUBMISSIONS);
        $this->assertEquals('simple-form:manageSubmissions', SimpleFormPermissions::MANAGE_SUBMISSIONS);
        $this->assertEquals('simple-form:manageSettings', SimpleFormPermissions::MANAGE_SETTINGS);
    }

    public function testDefinitionsReturnsValidStructure(): void
    {
        $defs = SimpleFormPermissions::definitions();

        $this->assertIsArray($defs);
        $this->assertArrayHasKey('heading', $defs);
        $this->assertArrayHasKey('permissions', $defs);
        $this->assertEquals('Simple Form', $defs['heading']);
    }

    public function testDefinitionsIncludesAllPermissions(): void
    {
        $defs = SimpleFormPermissions::definitions();
        $permissions = $defs['permissions'];

        $this->assertArrayHasKey(SimpleFormPermissions::MANAGE_FORMS, $permissions);
        $this->assertArrayHasKey(SimpleFormPermissions::VIEW_SUBMISSIONS, $permissions);
        $this->assertArrayHasKey(SimpleFormPermissions::MANAGE_SETTINGS, $permissions);
    }

    public function testManageSubmissionsIsNestedUnderViewSubmissions(): void
    {
        $defs = SimpleFormPermissions::definitions();
        $viewSubmissions = $defs['permissions'][SimpleFormPermissions::VIEW_SUBMISSIONS];

        $this->assertArrayHasKey('nested', $viewSubmissions);
        $this->assertArrayHasKey(SimpleFormPermissions::MANAGE_SUBMISSIONS, $viewSubmissions['nested']);
    }

    public function testEachPermissionHasLabel(): void
    {
        $defs = SimpleFormPermissions::definitions();
        $permissions = $defs['permissions'];

        foreach ($permissions as $key => $perm) {
            $this->assertArrayHasKey('label', $perm, "Permission $key missing label");
            $this->assertIsString($perm['label']);
            $this->assertNotEmpty($perm['label']);
        }
    }
}
