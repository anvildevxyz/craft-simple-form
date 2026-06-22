<?php

namespace fabianhaef\simpleform\migrations;

use Craft;
use craft\db\Migration;

/**
 * Custom render templating is now opt-in per form via a lightswitch
 * ({@see \fabianhaef\simpleform\elements\Form::$useCustomTemplate}). Adds the
 * backing column and preserves current rendering on upgrade:
 *
 * Before this change a global default template (if configured) applied to every
 * form, and a per-form path applied to its own form. So the switch is turned on
 * wherever a custom template was already in effect — for all forms when a global
 * default exists, otherwise only for forms that carry their own path — so nothing
 * visually changes until an editor toggles it.
 */
class m260622_000002_form_use_custom_template extends Migration
{
    private const TABLE = '{{%simpleform_forms}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'useCustomTemplate')) {
            $this->addColumn(
                self::TABLE,
                'useCustomTemplate',
                $this->boolean()->notNull()->defaultValue(false)->after('templatePath'),
            );
        }

        $globalDefault = trim((string) (
            Craft::$app->getProjectConfig()->get('plugins.simple-form.settings.templatePath') ?? ''
        ));

        if ($globalDefault !== '') {
            // The global default was rendering for every form — keep them all on.
            $this->update(self::TABLE, ['useCustomTemplate' => true], '', [], false);
        } else {
            // Only forms with their own path were themed.
            $this->update(
                self::TABLE,
                ['useCustomTemplate' => true],
                ['and', ['not', ['templatePath' => null]], ['<>', 'templatePath', '']],
                [],
                false,
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'useCustomTemplate')) {
            $this->dropColumn(self::TABLE, 'useCustomTemplate');
        }

        return true;
    }
}
