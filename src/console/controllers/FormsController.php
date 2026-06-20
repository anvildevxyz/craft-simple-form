<?php

namespace fabianhaef\simpleform\console\controllers;

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
            default => [],
        };
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
}
