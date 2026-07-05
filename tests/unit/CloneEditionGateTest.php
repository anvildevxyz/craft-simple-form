<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\Editions;
use anvildev\simpleform\services\FormCloneService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use yii\base\InvalidArgumentException;

/**
 * #282 — cloning (duplicate / stencil) must run the same edition gate as
 * importing: the copy is a brand-new form, so every Pro feature it carries is
 * new escalation on Solo. Previously FormCloneService had no Editions checks
 * at all, so Solo could mint unlimited Pro forms by duplicating one.
 */
class CloneEditionGateTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $items
     */
    private function assertEditionAllows(array $items, bool $saveResume, string $edition): void
    {
        $method = new ReflectionMethod(FormCloneService::class, 'assertEditionAllows');
        $method->invoke(new FormCloneService(), $items, $saveResume, $edition);
    }

    public function testSoloBlocksCloningProFieldTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Pro edition/');

        $this->assertEditionAllows(
            [['type' => 'text', 'config' => []], ['type' => 'signature', 'config' => []]],
            false,
            Editions::SOLO,
        );
    }

    public function testSoloBlocksCloningProCapabilities(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // No Pro field types, but the field set uses conditional logic.
        $this->assertEditionAllows(
            [['type' => 'text', 'config' => ['conditional' => ['enabled' => true, 'rules' => [['field' => 'a']]]]]],
            false,
            Editions::SOLO,
        );
    }

    public function testSoloBlocksCloningSaveResumeForms(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->assertEditionAllows([['type' => 'text', 'config' => []]], true, Editions::SOLO);
    }

    public function testSoloAllowsCloningPlainForms(): void
    {
        $this->assertEditionAllows(
            [['type' => 'text', 'config' => []], ['type' => 'email', 'config' => []]],
            false,
            Editions::SOLO,
        );
        $this->addToAssertionCount(1);
    }

    public function testProAllowsCloningEverything(): void
    {
        $this->assertEditionAllows(
            [['type' => 'signature', 'config' => []], ['type' => 'payment', 'config' => []]],
            true,
            Editions::PRO,
        );
        $this->addToAssertionCount(1);
    }

    public function testBothCloneEntryPointsRunTheGate(): void
    {
        $code = (string) file_get_contents(__DIR__ . '/../../src/services/FormCloneService.php');

        $duplicatePos = strpos($code, 'public function duplicate(');
        $stencilPos = strpos($code, 'public function createFromStencil(');
        $this->assertNotFalse($duplicatePos);
        $this->assertNotFalse($stencilPos);

        // Each entry point calls the gate before build().
        $this->assertStringContainsString('assertEditionAllows', substr($code, $duplicatePos, $stencilPos - $duplicatePos));
        $this->assertStringContainsString('assertEditionAllows', substr($code, $stencilPos, 2500));
    }

    public function testClonedNotificationsKeepAttachmentFlags(): void
    {
        $code = (string) file_get_contents(__DIR__ . '/../../src/services/FormCloneService.php');
        $pos = strpos($code, 'private function copyNotifications(');
        $this->assertNotFalse($pos);
        $body = substr($code, $pos, 1500);
        $this->assertStringContainsString('attachPdf', $body);
        $this->assertStringContainsString('attachUploads', $body);
    }
}
