<?php

namespace anvildev\simpleform\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use yii\console\ExitCode;

/**
 * `simple-form/make/*` — scaffold a custom extension from a working stub instead
 * of a blank file (#222):
 *
 *   php craft simple-form/make/field-type [ClassName] [--namespace=] [--path=]
 *   php craft simple-form/make/integration [ClassName] [--namespace=] [--path=]
 *   php craft simple-form/make/theme [--path=]
 *
 * Field-type / integration stubs are written next to the working directory (or
 * `--path`) and printed with the one-liner that registers them. `make/theme`
 * copies the built-in render partials into your `templates/` so you can edit
 * them.
 */
class MakeController extends Controller
{
    /** PHP namespace for the generated class. */
    public ?string $namespace = null;

    /** Output file (field-type/integration) or directory (theme); see each action. */
    public ?string $path = null;

    /** Overwrite existing files without prompting. */
    public bool $force = false;

    /**
     * @param string $actionID
     * @return array<int, string>
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['namespace', 'path', 'force']);
    }

    /**
     * Scaffold a custom field type (extends `fields\FieldType`).
     */
    public function actionFieldType(?string $className = null): int
    {
        $className = $this->resolveClassName($className, 'MyFieldType');
        if ($className === null) {
            return ExitCode::USAGE;
        }
        $namespace = $this->resolveNamespace();
        $handle = (string) $this->prompt('Field handle (machine name):', ['default' => $this->handleFromClass($className)]);
        $label = (string) $this->prompt('Field label:', ['default' => $this->labelFromClass($className)]);

        $written = $this->writeClass($className, $this->fieldTypeStub($namespace, $className, $handle, $label));
        if ($written === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Created field type: $written\n", Console::FG_GREEN);
        $this->printRegistration('EVENT_REGISTER_FIELD_TYPES', $namespace, $className);
        return ExitCode::OK;
    }

    /**
     * Scaffold a custom outbound integration (implements `IntegrationTypeInterface`).
     */
    public function actionIntegration(?string $className = null): int
    {
        $className = $this->resolveClassName($className, 'MyIntegration');
        if ($className === null) {
            return ExitCode::USAGE;
        }
        $namespace = $this->resolveNamespace();
        $handle = (string) $this->prompt('Integration handle (machine name):', ['default' => $this->handleFromClass($className)]);
        $name = (string) $this->prompt('Display name:', ['default' => $this->labelFromClass($className)]);

        $written = $this->writeClass($className, $this->integrationStub($namespace, $className, $handle, $name));
        if ($written === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Created integration: $written\n", Console::FG_GREEN);
        $this->printRegistration('EVENT_REGISTER_INTEGRATION_TYPES', $namespace, $className);
        return ExitCode::OK;
    }

    /**
     * Copy the built-in render partials into a templates folder to theme.
     */
    public function actionTheme(?string $path = null): int
    {
        $target = $path ?? $this->path ?? '@root/templates/_simple-form';
        $targetDir = (string) Craft::getAlias($target);
        $sourceDir = dirname(__DIR__, 2) . '/templates/_form';

        if (is_dir($targetDir) && !$this->force && !$this->confirm("$targetDir exists. Copy partials into it?")) {
            $this->stderr("Aborted.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        FileHelper::createDirectory($targetDir);
        $copied = 0;
        foreach (FileHelper::findFiles($sourceDir, ['only' => ['*.twig']]) as $file) {
            $dest = $targetDir . '/' . basename($file);
            if (file_exists($dest) && !$this->force && !$this->confirm(basename($file) . ' exists. Overwrite?')) {
                continue;
            }
            copy($file, $dest);
            $copied++;
        }

        $this->stdout("Copied $copied partial(s) into $targetDir\n", Console::FG_GREEN);
        $this->stdout("Point a form (or the Default render template path setting) at this folder.\n");
        return ExitCode::OK;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    private function resolveClassName(?string $className, string $default): ?string
    {
        $className ??= (string) $this->prompt('Class name:', ['default' => $default]);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className) !== 1) {
            $this->stderr("Invalid class name: $className\n", Console::FG_RED);
            return null;
        }
        return $className;
    }

    private function resolveNamespace(): string
    {
        return $this->namespace ?? (string) $this->prompt('Namespace:', ['default' => 'modules\\simpleform']);
    }

    private function handleFromClass(string $className): string
    {
        $base = preg_replace('/(FieldType|Field|Integration)$/', '', $className) ?: $className;
        $snake = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $base));
        return trim($snake, '_') ?: 'custom';
    }

    private function labelFromClass(string $className): string
    {
        $base = preg_replace('/(FieldType|Field|Integration)$/', '', $className) ?: $className;
        return trim((string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $base));
    }

    private function writeClass(string $className, string $code): ?string
    {
        $dir = $this->path !== null ? (string) Craft::getAlias($this->path) : (string) getcwd();
        $file = rtrim($dir, '/') . '/' . $className . '.php';

        if (file_exists($file) && !$this->force && !$this->confirm("$file exists. Overwrite?")) {
            $this->stderr("Aborted.\n", Console::FG_YELLOW);
            return null;
        }

        FileHelper::writeToFile($file, $code);
        return $file;
    }

    private function printRegistration(string $event, string $namespace, string $className): void
    {
        $this->stdout("\nRegister it from your plugin/module init():\n\n");
        $this->stdout(
            "    \\yii\\base\\Event::on(\n"
            . "        \\anvildev\\simpleform\\Plugin::class,\n"
            . "        \\anvildev\\simpleform\\Plugin::$event,\n"
            . "        fn(\$e) => \$e->types[] = \\$namespace\\$className::class,\n"
            . "    );\n\n"
        );
    }

    private function fieldTypeStub(string $namespace, string $className, string $handle, string $label): string
    {
        return <<<PHP
            <?php

            namespace $namespace;

            use anvildev\\simpleform\\fields\\FieldType;

            /**
             * Custom Simple Form field type. Register via
             * Plugin::EVENT_REGISTER_FIELD_TYPES (see docs/twig-and-api.md).
             */
            class $className extends FieldType
            {
                public static function getType(): string
                {
                    return '$handle';
                }

                public static function getLabel(): string
                {
                    return '$label';
                }

                public function renderInput(string \$name, mixed \$value = null): string
                {
                    return sprintf(
                        '<input type="text" name="%s" value="%s">',
                        htmlspecialchars(\$name, ENT_QUOTES),
                        htmlspecialchars((string)(\$value ?? ''), ENT_QUOTES),
                    );
                }

                /**
                 * @return string[]
                 */
                public function validate(mixed \$value): array
                {
                    \$errors = [];
                    if (!empty(\$this->config['required']) && trim((string)\$value) === '') {
                        \$errors[] = 'This field is required.';
                    }
                    return \$errors;
                }
            }

            PHP;
    }

    private function integrationStub(string $namespace, string $className, string $handle, string $name): string
    {
        return <<<PHP
            <?php

            namespace $namespace;

            use anvildev\\simpleform\\elements\\Submission;
            use anvildev\\simpleform\\integrations\\IntegrationResult;
            use anvildev\\simpleform\\integrations\\IntegrationTypeInterface;

            /**
             * Custom Simple Form outbound integration. Register via
             * Plugin::EVENT_REGISTER_INTEGRATION_TYPES (see docs/integrations.md).
             */
            class $className implements IntegrationTypeInterface
            {
                public static function handle(): string
                {
                    return '$handle';
                }

                public static function displayName(): string
                {
                    return '$name';
                }

                public function settingsHtml(array \$settings): string
                {
                    // Return the CP settings-form HTML for this connector.
                    return '';
                }

                public function defineSettingsRules(): array
                {
                    return [];
                }

                public function send(Submission \$submission, array \$settings): IntegrationResult
                {
                    // Transform \$submission->data and dispatch it, then report the result.
                    return IntegrationResult::success(200, 'Sent.');
                }
            }

            PHP;
    }
}
