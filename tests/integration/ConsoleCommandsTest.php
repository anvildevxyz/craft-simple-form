<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use fabianhaef\simpleform\console\controllers\CacheController;
use fabianhaef\simpleform\console\controllers\DoctorController;
use fabianhaef\simpleform\console\controllers\FormsController;
use fabianhaef\simpleform\console\controllers\IntegrationsController;
use fabianhaef\simpleform\console\controllers\SubmissionsController;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\services\FormPortabilityService;
use yii\console\ExitCode;

/**
 * Smoke the CLI commands (#106): each runs against the Craft harness and the
 * real service layer (purge/export/cache/doctor/redispatch).
 */
class ConsoleCommandsTest extends SimpleFormTestCase
{
    private function makeSubmission(int $formId, int $ageDays = 0): int
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = ['name' => 'Ada'];
        $sub->readStatus = 'new';
        Craft::$app->getElements()->saveElement($sub);
        $id = (int) $sub->id;

        if ($ageDays > 0) {
            $old = Db::prepareDateForDb((new \DateTime('now', new \DateTimeZone('UTC')))->modify("-{$ageDays} days"));
            Craft::$app->getDb()->createCommand()
                ->update('{{%simpleform_submissions}}', ['dateCreated' => $old], ['id' => $id])
                ->execute();
        }

        return $id;
    }

    public function testPurgeCommandDeletesAgedSubmissions(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'console_purge');
        $aged = $this->makeSubmission((int) $form->id, 100);

        $controller = new SubmissionsController('submissions', Craft::$app);
        $controller->days = 30;
        $code = $controller->actionPurge();

        $this->assertSame(ExitCode::OK, $code);
        $this->assertFalse((new Query())->from('{{%simpleform_submissions}}')->where(['id' => $aged])->exists());
    }

    public function testPurgeRequiresDays(): void
    {
        $this->requireCraft();
        $controller = new SubmissionsController('submissions', Craft::$app);
        $this->assertSame(ExitCode::USAGE, $controller->actionPurge());
    }

    public function testExportCommandWritesCsv(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'console_export');
        $this->makeSubmission((int) $form->id);

        $path = Craft::$app->getPath()->getTempPath() . '/sf-console-export-' . uniqid() . '.csv';
        $controller = new SubmissionsController('submissions', Craft::$app);
        $controller->out = $path;
        $code = $controller->actionExport();

        $this->assertSame(ExitCode::OK, $code);
        $this->assertFileExists($path);
        $this->assertNotSame('', trim((string) file_get_contents($path)));
        @unlink($path);
    }

    public function testCacheWarmAndClear(): void
    {
        $this->requireCraft();
        $this->createForm('Contact', 'console_cache');
        $controller = new CacheController('cache', Craft::$app);

        $this->assertSame(ExitCode::OK, $controller->actionWarm());
        $this->assertSame(ExitCode::OK, $controller->actionClear());
    }

    public function testDoctorRuns(): void
    {
        $this->requireCraft();
        $controller = new DoctorController('doctor', Craft::$app);
        $this->assertSame(ExitCode::OK, $controller->actionIndex());
    }

    public function testRedispatchRejectsUnknownSubmission(): void
    {
        $this->requireCraft();
        $controller = new IntegrationsController('integrations', Craft::$app);
        $controller->submission = 99999999;
        $this->assertSame(ExitCode::DATAERR, $controller->actionRedispatch());
    }

    public function testFormsExportImportRoundTrip(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'console_form_export');
        $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $path = Craft::$app->getPath()->getTempPath() . '/sf-form-export-' . uniqid() . '.json';

        // Export.
        $export = new FormsController('forms', Craft::$app);
        $export->form = 'console_form_export';
        $export->out = $path;
        $this->assertSame(ExitCode::OK, $export->actionExport());
        $this->assertFileExists($path);

        // Import with rename → a -2 form appears.
        $import = new FormsController('forms', Craft::$app);
        $import->mode = FormPortabilityService::MODE_RENAME;
        $this->assertSame(ExitCode::OK, $import->actionImport($path));

        $imported = \fabianhaef\simpleform\elements\Form::find()
            ->handle('console_form_export-2')->siteId('*')->status(null)->one();
        $this->assertNotNull($imported);

        @unlink($path);
    }

    public function testFormsExportRequiresFormHandle(): void
    {
        $this->requireCraft();
        $controller = new FormsController('forms', Craft::$app);
        $this->assertSame(ExitCode::USAGE, $controller->actionExport());
    }

    public function testFormsImportRejectsMissingFile(): void
    {
        $this->requireCraft();
        $controller = new FormsController('forms', Craft::$app);
        $this->assertSame(ExitCode::DATAERR, $controller->actionImport('/no/such/file.json'));
    }
}
