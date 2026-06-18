<?php

namespace fabianhaef\simpleform\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\Plugin;

/**
 * Bulk-set the read status on selected submissions from the element index (#109).
 * Returned once per target status from Submission::defineActions().
 */
class SetSubmissionStatus extends ElementAction
{
    /** The read status to apply (see SubmissionStatus). */
    public string $status = SubmissionStatus::READ;

    public function getTriggerLabel(): string
    {
        return match ($this->status) {
            SubmissionStatus::READ => Craft::t('simple-form', 'Mark as read'),
            SubmissionStatus::ARCHIVED => Craft::t('simple-form', 'Archive'),
            SubmissionStatus::SPAM => Craft::t('simple-form', 'Mark as spam'),
            default => Craft::t('simple-form', 'Mark as new'),
        };
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $service = Plugin::getInstance()->getSubmissionService();
        $count = 0;
        foreach ($query->ids() as $id) {
            if ($service->updateStatus((int) $id, $this->status)) {
                $count++;
            }
        }

        $this->setMessage(Craft::t('simple-form', '{count} submission(s) updated.', ['count' => $count]));
        return true;
    }
}
