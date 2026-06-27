<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\controllers\SimpleFormControllerTrait;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use PHPUnit\Framework\TestCase;

class SimpleFormControllerTraitTest extends TestCase
{
    public function testTraitExists(): void
    {
        $this->assertTrue(trait_exists(SimpleFormControllerTrait::class));
    }

    public function testTraitHasBeforeActionMethod(): void
    {
        $reflection = new \ReflectionClass(SimpleFormControllerTrait::class);
        $this->assertTrue($reflection->hasMethod('beforeAction'));
    }

    public function testPermissionKeysAreValid(): void
    {
        // Verify that the permission keys used by controllers are valid
        $this->assertEquals('simple-form:manageForms', SimpleFormPermissions::MANAGE_FORMS);
        $this->assertEquals('simple-form:viewSubmissions', SimpleFormPermissions::VIEW_SUBMISSIONS);
        $this->assertEquals('simple-form:manageSubmissions', SimpleFormPermissions::MANAGE_SUBMISSIONS);
        $this->assertEquals('simple-form:manageSettings', SimpleFormPermissions::MANAGE_SETTINGS);
    }

    public function testTraitCanBeUsedByMultipleControllers(): void
    {
        // Test that the trait is designed to be used by multiple controllers
        // by checking it doesn't have controller-specific dependencies
        $reflection = new \ReflectionClass(SimpleFormControllerTrait::class);
        $methods = $reflection->getMethods();

        // beforeAction should be the only public method in the trait
        $publicMethods = array_filter($methods, function($method) {
            return $method->isPublic() && $method->class === SimpleFormControllerTrait::class;
        });

        $this->assertCount(1, $publicMethods);
        $firstMethod = reset($publicMethods);
        $this->assertNotFalse($firstMethod);
        $this->assertEquals('beforeAction', $firstMethod->name);
    }
}
