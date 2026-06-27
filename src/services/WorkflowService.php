<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\elements\User;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\events\WorkflowTransitionEvent;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Configurable submission approval workflow (#248): resolves the owner-defined
 * stages + transitions from settings, decides which transitions a given user may
 * perform (role-gated), and applies a transition — persisting the new stage,
 * recording it in the audit log, and firing {@see Plugin::EVENT_SUBMISSION_TRANSITIONED}
 * so notifications/integrations can react without being hardcoded.
 *
 * Entirely inert when the workflow is disabled (the default), so the submission
 * lifecycle stays identical to today. The transition-gating logic is pure and
 * unit-tested via {@see self::filterAllowed()}.
 *
 * @phpstan-type WorkflowStatus array{handle: string, label: string, color: string}
 * @phpstan-type WorkflowTransition array{from: string, to: string, label: string, groups: list<string>}
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class WorkflowService extends Component
{
    public function isEnabled(): bool
    {
        return (bool) Plugin::getInstance()->getSettings()->enableWorkflow
            && $this->getStatuses() !== [];
    }

    /**
     * @return list<WorkflowStatus>
     */
    public function getStatuses(): array
    {
        $out = [];
        foreach (Plugin::getInstance()->getSettings()->workflowStatuses as $row) {
            $handle = trim((string) ($row['handle'] ?? ''));
            if ($handle === '') {
                continue;
            }
            $out[] = [
                'handle' => $handle,
                'label' => trim((string) ($row['label'] ?? '')) ?: $handle,
                'color' => trim((string) ($row['color'] ?? '')) ?: 'gray',
            ];
        }

        return $out;
    }

    /**
     * @return WorkflowStatus|null
     */
    public function getStatus(string $handle): ?array
    {
        foreach ($this->getStatuses() as $status) {
            if ($status['handle'] === $handle) {
                return $status;
            }
        }

        return null;
    }

    /**
     * The stage new submissions enter (the first configured one), or null when no
     * workflow is configured.
     */
    public function initialStatusHandle(): ?string
    {
        return $this->getStatuses()[0]['handle'] ?? null;
    }

    /**
     * @return list<WorkflowTransition>
     */
    public function getTransitions(): array
    {
        $out = [];
        foreach (Plugin::getInstance()->getSettings()->workflowTransitions as $row) {
            $from = trim((string) ($row['from'] ?? ''));
            $to = trim((string) ($row['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            $groups = $row['groups'] ?? [];
            $out[] = [
                'from' => $from,
                'to' => $to,
                'label' => trim((string) ($row['label'] ?? '')) ?: $to,
                'groups' => is_array($groups) ? array_values(array_filter(array_map('strval', $groups))) : [],
            ];
        }

        return $out;
    }

    /**
     * The transitions available from a stage to the given user: those whose `from`
     * matches and whose group gate the user satisfies. Each carries the resolved
     * target stage label/color for display.
     *
     * @return list<array{from: string, to: string, label: string, toLabel: string, toColor: string}>
     */
    public function allowedTransitions(?string $fromHandle, ?User $user): array
    {
        $groups = $this->userGroupHandles($user);
        $isAdmin = $user?->admin ?? false;

        $out = [];
        foreach (self::filterAllowed($this->getTransitions(), $fromHandle, $groups, $isAdmin) as $t) {
            $target = $this->getStatus($t['to']);
            if ($target === null) {
                continue;
            }
            $out[] = [
                'from' => $t['from'],
                'to' => $t['to'],
                'label' => $t['label'],
                'toLabel' => $target['label'],
                'toColor' => $target['color'],
            ];
        }

        return $out;
    }

    /**
     * Pure transition gate (no Craft access), unit-tested: keep transitions whose
     * `from` matches and whose `groups` gate is satisfied (empty gate = anyone;
     * an admin always passes).
     *
     * @param list<WorkflowTransition> $transitions
     * @param list<string> $userGroupHandles
     * @return list<WorkflowTransition>
     */
    public static function filterAllowed(array $transitions, ?string $fromHandle, array $userGroupHandles, bool $isAdmin): array
    {
        $out = [];
        foreach ($transitions as $t) {
            if ($t['from'] !== $fromHandle) {
                continue;
            }
            if ($t['groups'] === [] || $isAdmin || array_intersect($t['groups'], $userGroupHandles) !== []) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * Move a submission to a target stage on behalf of a user, if the workflow
     * permits it. Validates the transition is configured + the user may perform
     * it, persists the new stage, audit-logs the change, and fires the transition
     * event. Returns false (changing nothing) when the move isn't allowed.
     */
    public function transition(Submission $submission, string $toHandle, ?User $user): bool
    {
        if (!$this->isEnabled() || $this->getStatus($toHandle) === null) {
            return false;
        }

        $from = $submission->workflowStatus;
        $allowed = false;
        foreach ($this->allowedTransitions($from, $user) as $t) {
            if ($t['to'] === $toHandle) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return false;
        }

        $submission->workflowStatus = $toHandle;
        if (!Craft::$app->getElements()->saveElement($submission)) {
            return false;
        }

        Plugin::getInstance()->getAudit()->log(
            AuditService::ACTION_SUBMISSION_STATUS,
            AuditService::TARGET_SUBMISSION,
            (int) $submission->id,
            sprintf('workflow: %s → %s', $from ?? '—', $toHandle),
        );

        Plugin::getInstance()->trigger(
            Plugin::EVENT_SUBMISSION_TRANSITIONED,
            new WorkflowTransitionEvent($submission, $from, $toHandle, $user),
        );

        return true;
    }

    /**
     * Place a brand-new submission at the initial stage, if a workflow is active
     * and it has none yet. No-op otherwise.
     */
    public function applyInitialStatus(Submission $submission): void
    {
        if ($this->isEnabled() && $submission->workflowStatus === null) {
            $submission->workflowStatus = $this->initialStatusHandle();
        }
    }

    /**
     * @return list<string>
     */
    private function userGroupHandles(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_map(static fn($g): string => (string) $g->handle, $user->getGroups()));
    }
}
