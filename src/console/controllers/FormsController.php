<?php

namespace fabianhaef\simpleform\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\FormPortabilityService;
use yii\console\ExitCode;

/**
 * `simple-form/forms/*` — portable form definition import/export (#139).
 *
 * Export a form's full, secret-free definition to versioned JSON and import it on
 * any install to recreate the form. See {@see FormPortabilityService}.
 *
 * @author Fabian Haefliger
 * @since 2.11.0
 */
class FormsController extends Controller
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /** The form handle to export. */
    public ?string $form = null;

    /** Write the export JSON to this path instead of stdout. */
    public ?string $out = null;

    /** Conflict mode on import: rename (default), replace, or abort. */
    public string $mode = FormPortabilityService::MODE_RENAME;

    /** apply: report what would happen without writing anything. */
    public bool $dryRun = false;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @param string $actionID
     * @return list<string>
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'export' => ['form', 'out'],
            'import' => ['mode'],
            'apply' => ['dryRun'],
            default => [],
        };
    }

    /**
     * The directory holding code-defined form definitions
     * (`config/simple-form/forms/<handle>.json`).
     */
    private function configDir(): string
    {
        return (string)Craft::getAlias('@config') . '/simple-form/forms';
    }

    /**
     * Export a form's definition as JSON to stdout or --out=<path>.
     *
     * Usage: `simple-form/forms/export --form=<handle> [--out=path.json]`
     */
    public function actionExport(): int
    {
        if ($this->form === null || trim($this->form) === '') {
            $this->stderr("--form=<handle> is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $form = Form::find()->handle($this->form)->siteId('*')->status(null)->one();
        if (!$form) {
            $this->stderr("No form found with handle \"{$this->form}\".\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $json = Plugin::getInstance()->getPortability()->exportJson($form);

        if ($this->out !== null) {
            file_put_contents($this->out, $json);
            $this->stdout("Wrote {$this->out}\n", Console::FG_GREEN);
        } else {
            $this->stdout($json . "\n");
        }

        return ExitCode::OK;
    }

    /**
     * Import a form definition from a JSON file.
     *
     * Usage: `simple-form/forms/import <path.json> [--mode=rename|replace|abort]`
     */
    public function actionImport(string $path): int
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->stderr("File not found or not readable: {$path}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if (!in_array($this->mode, FormPortabilityService::MODES, true)) {
            $this->stderr("--mode must be one of: " . implode(', ', FormPortabilityService::MODES) . "\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $json = (string)file_get_contents($path);

        try {
            $result = Plugin::getInstance()->getPortability()->importJson($json, ['mode' => $this->mode]);
        } catch (\Throwable $e) {
            $this->stderr('Import failed: ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $form = $result->form;
        $this->stdout("Imported form “{$form?->handle}” (id {$form?->id}).\n", Console::FG_GREEN);

        foreach ($result->warnings as $warning) {
            $this->stdout('  - ' . $warning . "\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Apply code-defined forms from `config/simple-form/forms/*.json` to this
     * install — the "forms as code" deploy path (e.g. on `craft up`).
     *
     * A form whose handle does not yet exist here is **created** from its file.
     * A form that already exists is **left untouched** (this command never
     * mutates or deletes a form, so live submissions are always safe); update an
     * existing form's structure from code by re-importing explicitly. Idempotent:
     * re-running only creates what is missing.
     *
     * Usage: `simple-form/forms/apply [--dry-run]`
     */
    public function actionApply(): int
    {
        $dir = $this->configDir();
        $files = is_dir($dir) ? (glob($dir . '/*.json') ?: []) : [];

        if ($files === []) {
            $this->stdout("No form definitions found in {$dir}\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $created = 0;
        $skipped = 0;
        foreach ($files as $file) {
            $json = (string)file_get_contents($file);
            $decoded = json_decode($json, true);
            $handle = is_array($decoded) && is_array($decoded['form'] ?? null)
                ? trim((string)($decoded['form']['handle'] ?? ''))
                : '';

            if ($handle === '') {
                $this->stdout('  - ' . basename($file) . ": no form handle, skipped\n", Console::FG_YELLOW);
                $skipped++;
                continue;
            }

            if (Form::find()->handle($handle)->siteId('*')->status(null)->exists()) {
                $this->stdout("  = {$handle}: already exists, left untouched\n");
                $skipped++;
                continue;
            }

            if ($this->dryRun) {
                $this->stdout("  + {$handle}: would be created (dry run)\n", Console::FG_GREEN);
                $created++;
                continue;
            }

            try {
                $result = Plugin::getInstance()->getPortability()->importJson($json, ['mode' => FormPortabilityService::MODE_ABORT]);
            } catch (\Throwable $e) {
                $this->stderr("  ! {$handle}: " . $e->getMessage() . "\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }

            $this->stdout("  + {$handle}: created (id {$result->form?->id})\n", Console::FG_GREEN);
            foreach ($result->warnings as $warning) {
                $this->stdout('      ' . $warning . "\n", Console::FG_YELLOW);
            }
            $created++;
        }

        $verb = $this->dryRun ? 'Would create' : 'Created';
        $this->stdout("\n{$verb} {$created} form(s); {$skipped} skipped.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Report which forms are defined in code vs. only in the database, and which
     * config files have not been applied yet.
     *
     * Usage: `simple-form/forms/status`
     */
    public function actionStatus(): int
    {
        $dir = $this->configDir();
        $files = is_dir($dir) ? (glob($dir . '/*.json') ?: []) : [];

        // Map config handles -> file.
        $configHandles = [];
        foreach ($files as $file) {
            $decoded = json_decode((string)file_get_contents($file), true);
            $handle = is_array($decoded) && is_array($decoded['form'] ?? null)
                ? trim((string)($decoded['form']['handle'] ?? ''))
                : '';
            if ($handle !== '') {
                $configHandles[$handle] = basename($file);
            }
        }

        $this->stdout("Forms in this install:\n");
        $dbHandles = [];
        foreach (Form::find()->siteId('*')->status(null)->all() as $form) {
            $handle = (string)$form->handle;
            $dbHandles[$handle] = true;
            $managed = isset($configHandles[$handle]);
            $this->stdout(sprintf(
                "  %s %s\n",
                $managed ? '[config]' : '[db]    ',
                $handle,
            ), $managed ? Console::FG_GREEN : Console::FG_GREY);
        }

        $pending = array_diff_key($configHandles, $dbHandles);
        if ($pending !== []) {
            $this->stdout("\nConfig files not yet applied (run forms/apply):\n", Console::FG_YELLOW);
            foreach ($pending as $handle => $file) {
                $this->stdout("  + {$handle} ({$file})\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }
}
