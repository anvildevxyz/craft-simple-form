<?php

namespace anvildev\simpleform\console\controllers;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\models\ImportResult;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\FormPortabilityService;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use yii\console\ExitCode;

/**
 * `simple-form/forms/*` — portable form definition import/export (#139).
 *
 * Export a form's full, secret-free definition to versioned JSON and import it on
 * any install to recreate the form. See {@see FormPortabilityService}.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
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

    /** apply: remove fields no longer in the file (data-bearing fields are kept). */
    public bool $prune = false;

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
            'apply' => ['dryRun', 'prune'],
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
            // Create the target directory so exporting straight into the (not yet
            // existing) config/simple-form/forms/ folder works on a fresh project.
            $dir = dirname($this->out);
            if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
                FileHelper::createDirectory($dir);
            }
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
     * A handle that does not exist yet is **created** from its file. A handle that
     * already exists is **updated in place** (#225): the form keeps its element id
     * and fields are reconciled by handle, so field ids — and their submissions —
     * survive. Fields absent from the file are kept unless `--prune` is passed,
     * and even then a field that still holds submission data is kept. Idempotent.
     *
     * Usage: `simple-form/forms/apply [--dry-run] [--prune]`
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
        $updated = 0;
        $skipped = 0;
        foreach ($files as $file) {
            $json = (string)file_get_contents($file);
            $decoded = json_decode($json, true);
            $handle = is_array($decoded) && is_array($decoded['form'] ?? null)
                ? trim((string)($decoded['form']['handle'] ?? ''))
                : '';

            if ($handle === '' || !is_array($decoded)) {
                $this->stdout('  - ' . basename($file) . ": no form handle, skipped\n", Console::FG_YELLOW);
                $skipped++;
                continue;
            }

            $existing = Form::find()->handle($handle)->siteId('*')->status(null)->one();
            $portability = Plugin::getInstance()->getPortability();

            if ($this->dryRun) {
                $verb = $existing ? 'updated in place' : 'created';
                $this->stdout("  ~ {$handle}: would be {$verb} (dry run)\n", Console::FG_GREEN);
                $existing ? $updated++ : $created++;
                continue;
            }

            try {
                if ($existing !== null) {
                    $result = new ImportResult();
                    $portability->applyToExistingForm($existing, $decoded, $this->prune, $result);
                    $this->stdout("  ~ {$handle}: updated in place (id {$existing->id})\n", Console::FG_GREEN);
                    $updated++;
                } else {
                    $result = $portability->importJson($json, ['mode' => FormPortabilityService::MODE_ABORT]);
                    $this->stdout("  + {$handle}: created (id {$result->form?->id})\n", Console::FG_GREEN);
                    $created++;
                }
            } catch (\Throwable $e) {
                $this->stderr("  ! {$handle}: " . $e->getMessage() . "\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }

            foreach ($result->warnings as $warning) {
                $this->stdout('      ' . $warning . "\n", Console::FG_YELLOW);
            }
        }

        $prefix = $this->dryRun ? 'Would apply — ' : '';
        $this->stdout("\n{$prefix}created {$created}, updated {$updated}, skipped {$skipped}.\n", Console::FG_GREEN);
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
