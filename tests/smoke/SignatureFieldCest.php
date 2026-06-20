<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\AssetUploadService;

/**
 * Signature field smoke tests (#129).
 *
 * Covers the public render (canvas + Clear control), the required-empty
 * rejection (no asset created), and the data-URL → asset submit path with the
 * asset id stored in the submission's data — using a stubbed asset service so
 * the scenario is volume-independent.
 */
class SignatureFieldCest
{
    /** A minimal valid 1×1 PNG as a base64 data URL. */
    private const PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

    private $formId;
    private $formHandle;

    public function _before(FunctionalTester $I)
    {
        $form = new Form();
        $form->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form->name = 'signature-test-' . uniqid();
        $form->handle = $this->formHandle = 'sigTest' . uniqid();
        $form->title = 'Signature Test Form';
        $form->emailTo = 'admin@test.com';

        Craft::$app->getElements()->saveElement($form);
        $this->formId = $form->id;
    }

    private function addSignatureField(bool $required = true): int
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'signature',
            'name' => 'signature',
            'label' => 'Signature',
            'required' => $required,
            'config' => json_encode([]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        return (int) $db->getLastInsertID();
    }

    public function testCanvasAndClearControlRender(FunctionalTester $I)
    {
        $this->addSignatureField();

        $html = Craft::$app->getView()->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('data-sf-signature', $html, 'signature wrapper renders');
        $I->assertStringContainsString('<canvas', $html, 'canvas pad renders');
        $I->assertStringContainsString('data-sf-signature-canvas', $html);
        $I->assertStringContainsString('data-sf-signature-clear', $html, 'Clear control renders');
        $I->assertStringContainsString('type="hidden"', $html, 'hidden input carries the data URL');
    }

    public function testRequiredEmptySignatureRejectedWithNoAsset(FunctionalTester $I)
    {
        $fieldId = $this->addSignatureField();

        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => '',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success'] ?? true, 'empty required signature is rejected');
        $I->assertArrayHasKey('field_' . $fieldId, $response['errors'] ?? []);
        $I->assertNull(Submission::find()->formId($this->formId)->one(), 'no submission persists');
    }

    public function testDrawnSignatureIsStoredAsAssetId(FunctionalTester $I)
    {
        $fieldId = $this->addSignatureField();

        // Volume-independent: stub the asset service to return a fixed id.
        Plugin::getInstance()->set('assetUploadService', new class extends AssetUploadService {
            public function saveTempFiles(array $files, array $fieldConfig): array
            {
                return [424242];
            }
        });

        try {
            $I->sendPost('/simple-form/submit', [
                'formHandle' => $this->formHandle,
                'field_' . $fieldId => self::PNG_DATA_URL,
            ]);

            $response = json_decode($I->grabPageSource(), true);
            $I->assertTrue($response['success'] ?? false, 'a drawn signature submits successfully');

            $submission = Submission::find()->formId($this->formId)->one();
            $I->assertNotNull($submission, 'submission persists');
            $I->assertSame('signature', $submission->data['field_' . $fieldId]['type']);
            $I->assertSame([424242], $submission->data['field_' . $fieldId]['value']);
        } finally {
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
        }
    }
}
