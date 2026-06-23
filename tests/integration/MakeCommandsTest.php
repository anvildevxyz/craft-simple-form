<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\console\controllers\MakeController;
use fabianhaef\simpleform\fields\FieldType;
use fabianhaef\simpleform\integrations\IntegrationTypeInterface;
use yii\console\ExitCode;

/**
 * #222 — the `simple-form/make/*` scaffolding generators. Each runs
 * non-interactively into a temp dir; the generated source must be valid PHP and,
 * when loaded, must satisfy the contract it was scaffolded for.
 *
 * @group requires-craft
 */
class MakeCommandsTest extends SimpleFormTestCase
{
    private string $tmp = '';

    protected function _before(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sf-make-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function _after(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            array_map('unlink', glob($this->tmp . '/*') ?: []);
            @rmdir($this->tmp);
        }
    }

    private function controller(): MakeController
    {
        $c = new MakeController('make', Craft::$app);
        $c->interactive = false; // prompts resolve to their defaults
        $c->path = $this->tmp;
        return $c;
    }

    private function assertValidPhp(string $file): void
    {
        $out = [];
        $status = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $status);
        $this->assertSame(0, $status, "Generated file should be valid PHP: " . implode("\n", $out));
    }

    public function testMakeFieldTypeProducesALoadableFieldType(): void
    {
        $this->requireCraft();
        $c = $this->controller();
        $c->namespace = 'fabianhaef\\simpleform\\tests\\tmp\\ft';

        $this->assertSame(ExitCode::OK, $c->actionFieldType('WidgetField'));

        $file = $this->tmp . '/WidgetField.php';
        $this->assertFileExists($file);
        $this->assertValidPhp($file);

        require $file;
        $class = 'fabianhaef\\simpleform\\tests\\tmp\\ft\\WidgetField';
        $this->assertTrue(is_subclass_of($class, FieldType::class));
        // Handle derives from the class name with the Field suffix stripped.
        $this->assertSame('widget', $class::getType());
        $this->assertSame('Widget', $class::getLabel());
    }

    public function testMakeIntegrationProducesALoadableConnector(): void
    {
        $this->requireCraft();
        $c = $this->controller();
        $c->namespace = 'fabianhaef\\simpleform\\tests\\tmp\\in';

        $this->assertSame(ExitCode::OK, $c->actionIntegration('AcmeIntegration'));

        $file = $this->tmp . '/AcmeIntegration.php';
        $this->assertFileExists($file);
        $this->assertValidPhp($file);

        require $file;
        $class = 'fabianhaef\\simpleform\\tests\\tmp\\in\\AcmeIntegration';
        $this->assertTrue(is_subclass_of($class, IntegrationTypeInterface::class));
        $this->assertSame('acme', $class::handle());
    }

    public function testMakeThemeCopiesPartials(): void
    {
        $this->requireCraft();
        $c = $this->controller();
        $themeDir = $this->tmp . '/theme';

        $this->assertSame(ExitCode::OK, $c->actionTheme($themeDir));

        $this->assertFileExists($themeDir . '/form.twig');
        $this->assertFileExists($themeDir . '/field.twig');
        $this->assertFileExists($themeDir . '/input.twig');

        // cleanup nested dir created by this test
        array_map('unlink', glob($themeDir . '/*') ?: []);
        @rmdir($themeDir);
    }
}
