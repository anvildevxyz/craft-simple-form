<?php

namespace anvildev\simpleform\elements\actions;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;

/**
 * Bulk-duplicate the selected forms (#138): each is deep-copied into a new,
 * independent form with a fresh handle, copied fields/notifications/integration
 * attachments, and zero submissions. Returned from {@see Form::defineActions()}.
 *
 * @since 1.0.0
 * @author Fabian Haefliger
 */
class DuplicateForm extends ElementAction
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public function getTriggerLabel(): string
    {
        return Craft::t('simple-form', 'Duplicate');
    }

    /**
     * @throws \Throwable if a copy cannot be saved
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        $service = Plugin::getInstance()->getFormClone();
        $count = 0;

        /** @var Form $form */
        foreach ($query->all() as $form) {
            $service->duplicate($form);
            $count++;
        }

        $this->setMessage(Craft::t('simple-form', '{count} form(s) duplicated.', ['count' => $count]));
        return true;
    }
}
