<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\controllers\SubmissionsController;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use Craft;
use craft\elements\User;
use craft\web\Response;
use SmokeTester;

/**
 * CP editable submissions (#294): an admin re-opens a submission and edits its
 * field values through the permission-gated {@see SubmissionsController} edit
 * flow, which routes the change through the shared
 * {@see \anvildev\simpleform\services\SubmissionService::update()} path (the same
 * re-validate + re-snapshot core the front-end tokenized editor uses).
 *
 * @author Anvil Dev
 * @since 2.17.1
 */
class SubmissionEditCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Posting an edited field_<id> through actionSaveEdit under an admin identity
     * rewrites the submission's stored value.
     */
    public function testSaveEditUpdatesStoredValue(SmokeTester $I): void
    {
        $form = $this->createForm('Edit CP', 'editCp' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'color', 'Color');
        $submission = $this->submitRequest($form->handle, ['field_' . $fieldId => 'red'])['submission'];

        $I->assertSame('red', $submission->data['field_' . $fieldId]['value'], 'the submission starts with the seeded value');

        $this->asAdmin(function() use ($submission, $fieldId): void {
            $request = Craft::$app->getRequest();
            $request->setBodyParams([
                'submissionId' => $submission->id,
                'field_' . $fieldId => 'blue',
            ]);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            Craft::$app->set('response', new Response());

            $controller = new SubmissionsController('submissions', Plugin::getInstance());
            $controller->enableCsrfValidation = false;
            $controller->actionSaveEdit();
        });

        $updated = Submission::find()->id($submission->id)->one();
        $I->assertInstanceOf(Submission::class, $updated, 'the submission still exists after the edit');
        $I->assertSame('blue', $updated->data['field_' . $fieldId]['value'], 'the CP edit persisted the new value through update()');
    }

    /**
     * The edit screen renders to a 200 for an admin, primed with the submission's
     * current values.
     */
    public function testEditScreenRendersForAdmin(SmokeTester $I): void
    {
        $form = $this->createForm('Edit CP Render', 'editCpRender' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'color', 'Color');
        $submission = $this->submitRequest($form->handle, ['field_' . $fieldId => 'red'])['submission'];

        $response = $this->asAdmin(function() use ($submission): Response {
            Craft::$app->set('response', new Response());
            $controller = new SubmissionsController('submissions', Plugin::getInstance());

            return $controller->actionEdit((int) $submission->id);
        });

        $I->assertInstanceOf(Response::class, $response, 'the edit action returns a web response');
        $I->assertSame(200, $response->statusCode, 'the edit screen renders successfully for an admin');
    }

    /**
     * A permission-gated CP edit persists even when the form is now closed and
     * login-required — the access gates that block a new visitor submission must
     * not block an admin correcting an existing row (#294).
     */
    public function testCpEditBypassesAccessGatesOnLockedForm(SmokeTester $I): void
    {
        $form = $this->createForm('Edit CP Locked', 'editCpLocked' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'color', 'Color');
        $submission = $this->submitRequest($form->handle, ['field_' . $fieldId => 'red'])['submission'];

        // Lock the form down after the seed submission: closed + login-required.
        $form->requireLogin = true;
        $form->closeDate = new \DateTime('-1 day');
        Craft::$app->getElements()->saveElement($form);

        $this->asAdmin(function() use ($submission, $fieldId): void {
            $request = Craft::$app->getRequest();
            $request->setBodyParams([
                'submissionId' => $submission->id,
                'field_' . $fieldId => 'green',
            ]);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            Craft::$app->set('response', new Response());

            $controller = new SubmissionsController('submissions', Plugin::getInstance());
            $controller->enableCsrfValidation = false;
            $controller->actionSaveEdit();
        });

        $updated = Submission::find()->id($submission->id)->one();
        $I->assertInstanceOf(Submission::class, $updated);
        $I->assertSame('green', $updated->data['field_' . $fieldId]['value'], 'the CP edit persists despite the form being closed + login-required');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Run $work with a freshly-seeded admin as the active identity, restoring the
     * prior identity afterwards.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function asAdmin(callable $work): mixed
    {
        $user = new User();
        $user->admin = true;
        $user->email = 'edit-admin-' . uniqid() . '@example.test';
        $user->username = $user->email;
        Craft::$app->getElements()->saveElement($user);

        $session = Craft::$app->getUser();
        $previous = $session->getIdentity();
        try {
            $session->setIdentity($user);
            return $work();
        } finally {
            $session->setIdentity($previous);
        }
    }
}
