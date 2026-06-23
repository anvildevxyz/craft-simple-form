<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\console\Controller as ConsoleController;
use craft\console\controllers\UpController;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\base\InlineAction;

/**
 * #226 follow-up — `applyFormsConfigOnUp`. When the setting is on, finishing
 * `craft up` (the UpController index action) applies code-defined forms; when
 * off, it does nothing.
 *
 * @group requires-craft
 */
class ApplyOnUpTest extends SimpleFormTestCase
{
    private string $dir = '';
    private bool $createdDir = false;
    private string $handle = 'onUpForm';

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
            @rmdir(dirname($this->dir));
        }
        Plugin::getInstance()->getSettings()->applyFormsConfigOnUp = false;
    }

    /** Write a valid config file for a form that does not exist yet. */
    private function writePendingConfig(): void
    {
        $seed = $this->createForm('On Up', $this->handle);
        $this->createField((int) $seed->id, 'text', 'name', 'Name', true);
        $seed = Form::find()->id($seed->id)->siteId('*')->status(null)->one();
        file_put_contents(
            $this->dir . '/' . $this->handle . '.json',
            Plugin::getInstance()->getPortability()->exportJson($seed),
        );
        Craft::$app->getElements()->deleteElement($seed, true);
        $this->assertFalse(Form::find()->handle($this->handle)->siteId('*')->status(null)->exists());
    }

    private function fireUp(): void
    {
        $controller = new UpController('up', Craft::$app);
        $event = new ActionEvent(new InlineAction('index', $controller, 'actionIndex'));
        Event::trigger(UpController::class, ConsoleController::EVENT_AFTER_ACTION, $event);
    }

    public function testUpAppliesFormsWhenEnabled(): void
    {
        $this->requireCraft();
        $this->writePendingConfig();
        Plugin::getInstance()->getSettings()->applyFormsConfigOnUp = true;

        $this->fireUp();

        $this->assertTrue(
            Form::find()->handle($this->handle)->siteId('*')->status(null)->exists(),
            '`up` should apply config forms when applyFormsConfigOnUp is on',
        );
    }

    public function testUpDoesNothingWhenDisabled(): void
    {
        $this->requireCraft();
        $this->writePendingConfig();
        Plugin::getInstance()->getSettings()->applyFormsConfigOnUp = false;

        $this->fireUp();

        $this->assertFalse(
            Form::find()->handle($this->handle)->siteId('*')->status(null)->exists(),
            'with the setting off, `up` must not apply anything',
        );
    }
}
