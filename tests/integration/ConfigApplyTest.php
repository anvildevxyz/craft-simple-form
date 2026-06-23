<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\console\controllers\FormsController;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use yii\console\ExitCode;

/**
 * #218 — forms as code. `forms/apply` creates config-defined forms from
 * `config/simple-form/forms/*.json` and is idempotent + non-destructive: an
 * existing form is never recreated or mutated (its element id — and therefore its
 * submissions — survive a re-apply).
 *
 * @group requires-craft
 */
class ConfigApplyTest extends SimpleFormTestCase
{
    private string $dir = '';
    private bool $createdDir = false;
    private string $handle = 'cfgApplyForm';

    protected function _before(): void
    {
        $this->dir = (string) Craft::getAlias('@config') . '/simple-form/forms';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
            $this->createdDir = true;
        }
    }

    protected function _after(): void
    {
        @unlink($this->dir . '/' . $this->handle . '.json');
        if ($this->createdDir && is_dir($this->dir)) {
            @rmdir($this->dir);
            @rmdir(dirname($this->dir)); // config/simple-form
        }
    }

    /**
     * Build a valid config file by exporting a seed form, then deleting the seed
     * so apply has to re-create it from the file.
     */
    private function writeConfigFromSeed(): void
    {
        $seed = $this->createForm('Config Apply', $this->handle);
        $this->createField((int) $seed->id, 'text', 'name', 'Name', true);
        $seed = Form::find()->id($seed->id)->siteId('*')->status(null)->one();

        $json = Plugin::getInstance()->getPortability()->exportJson($seed);
        file_put_contents($this->dir . '/' . $this->handle . '.json', $json);

        Craft::$app->getElements()->deleteElement($seed, true);
        $this->assertFalse(Form::find()->handle($this->handle)->siteId('*')->status(null)->exists());
    }

    private function controller(): FormsController
    {
        return new FormsController('forms', Craft::$app);
    }

    public function testApplyCreatesFormFromConfig(): void
    {
        $this->requireCraft();
        $this->writeConfigFromSeed();

        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());

        $created = Form::find()->handle($this->handle)->siteId('*')->status(null)->one();
        $this->assertNotNull($created, 'apply should create the form');
        $firstId = (int) $created->id;

        // Re-apply is idempotent and non-destructive: the existing form is left
        // untouched (same element id => submissions are never orphaned).
        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());
        $again = Form::find()->handle($this->handle)->siteId('*')->status(null)->one();
        $this->assertNotNull($again);
        $this->assertSame($firstId, (int) $again->id, 're-apply must not recreate the form');
    }

    public function testDryRunCreatesNothing(): void
    {
        $this->requireCraft();
        $this->writeConfigFromSeed();

        $c = $this->controller();
        $c->dryRun = true;
        $this->assertSame(ExitCode::OK, $c->actionApply());

        $this->assertFalse(
            Form::find()->handle($this->handle)->siteId('*')->status(null)->exists(),
            'dry run must not create anything',
        );
    }

    public function testStatusRuns(): void
    {
        $this->requireCraft();
        $this->writeConfigFromSeed();
        // Pending (file present, not yet applied), then applied.
        $this->assertSame(ExitCode::OK, $this->controller()->actionStatus());
        $this->controller()->actionApply();
        $this->assertSame(ExitCode::OK, $this->controller()->actionStatus());
    }
}
