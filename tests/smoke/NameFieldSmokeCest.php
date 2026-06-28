<?php

namespace anvildev\simpleform\tests\smoke;

use SmokeTester;

/**
 * Name composite field smoke tests (#126): the default Name field renders its
 * first/last sub-inputs, and a submission stores the parts as one structured
 * value (extra crafted keys clamped to the rendered sub-fields).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class NameFieldSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testNameFieldRendersFirstAndLastSubInputs(SmokeTester $I): void
    {
        $form = $this->createForm('Name', 'nameRender' . uniqid());
        $this->createField((int) $form->id, 'name', 'fullName', 'Full name');

        $html = $this->renderForm($form->handle);
        $I->assertStringContainsString('[first]', $html);
        $I->assertStringContainsString('[last]', $html);
    }

    public function testNameSubmissionStoresStructuredParts(SmokeTester $I): void
    {
        $form = $this->createForm('Name', 'nameSubmit' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'name', 'fullName', 'Full name');

        $result = $this->submitDirect($form, [
            'field_' . $fieldId => ['first' => 'Ada', 'last' => 'Lovelace', 'evil' => 'crafted'],
        ]);

        $I->assertNull($result['errors']);
        $value = $result['submission']->data['field_' . $fieldId]['value'];
        // Only the rendered sub-fields persist — the crafted key is clamped out.
        $I->assertSame(['first' => 'Ada', 'last' => 'Lovelace'], $value);
        $I->assertSame('name', $result['submission']->data['field_' . $fieldId]['type']);
    }
}
