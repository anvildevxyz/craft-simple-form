<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\fields\SignatureFieldType;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\AssetUploadService;
use Craft;
use craft\db\Query;

/**
 * #129 — Signature field: the Craft-bound validation messages, the data-URL →
 * asset submit path (id stored in `data`, orphan cleanup on failure), and the
 * retention sweeps that must delete the signature asset on hard-delete and
 * scrub-and-delete on anonymize.
 *
 * @group requires-craft
 */
class SignatureFieldTest extends SimpleFormTestCase
{
    /** A minimal valid 1×1 PNG as a base64 data URL. */
    private function validPngDataUrl(): string
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
        return 'data:image/png;base64,' . $base64;
    }

    public function testValidateRequiredEmpty(): void
    {
        $this->requireCraft();
        $errors = (new SignatureFieldType(['required' => true]))->validate('');
        $this->assertCount(1, $errors);
        $this->assertSame([], (new SignatureFieldType(['required' => false]))->validate(''));
    }

    public function testValidateMalformedRejected(): void
    {
        $this->requireCraft();
        $errors = (new SignatureFieldType([]))->validate('data:image/svg+xml;base64,' . base64_encode('<svg></svg>'));
        $this->assertCount(1, $errors);
    }

    public function testValidateValidPng(): void
    {
        $this->requireCraft();
        $this->assertSame([], (new SignatureFieldType(['required' => true]))->validate($this->validPngDataUrl()));
    }

    public function testRenderInputMarkup(): void
    {
        $this->requireCraft();
        $html = (new SignatureFieldType(['required' => true]))->renderInput('field_9');
        $this->assertStringContainsString('data-sf-signature', $html);
        $this->assertStringContainsString('<canvas', $html);
        $this->assertStringContainsString('name="field_9"', $html);
        $this->assertStringContainsString('data-sf-signature-clear', $html);
    }

    public function testSubmitDecodesDataUrlToAsset(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Waiver', 'waiver_sig');
        $sigId = $this->createField($form->id, 'signature', 'sig', 'Signature', true);

        // Capture the staged file's bytes (the service deletes the temp file
        // after the call) so we can assert it is a PNG.
        $stub = new class() extends AssetUploadService {
            public string $bytes = '';
            public string $filename = '';
            public function saveTempFiles(array $files, array $fieldConfig): array
            {
                $this->bytes = (string) @file_get_contents($files[0]['path']);
                $this->filename = $files[0]['filename'];
                return [7777];
            }
        };
        Plugin::getInstance()->set('assetUploadService', $stub);

        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => 'waiver_sig', 'field_' . $sigId => $this->validPngDataUrl()]);

        try {
            $result = Plugin::getInstance()->getSubmissionService()->createFromRequest($form, $request);
        } finally {
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
        }

        $this->assertNotNull($result['submission']);
        $this->assertStringStartsWith("\x89PNG", $stub->bytes);
        $this->assertStringEndsWith('.png', $stub->filename);

        $row = (new Query())->from('{{%simpleform_submissions}}')
            ->where(['id' => $result['submission']->id])->one();
        $data = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);
        $this->assertSame('signature', $data['field_' . $sigId]['type']);
        $this->assertSame([7777], $data['field_' . $sigId]['value']);
    }

    public function testRequiredEmptySignatureCreatesNoAsset(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Waiver Empty', 'waiver_empty');
        $sigId = $this->createField($form->id, 'signature', 'sig', 'Signature', true);

        $stub = new class() extends AssetUploadService {
            public bool $saveCalled = false;
            public function saveTempFiles(array $files, array $fieldConfig): array
            {
                $this->saveCalled = true;
                return [1];
            }
        };
        Plugin::getInstance()->set('assetUploadService', $stub);

        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => 'waiver_empty', 'field_' . $sigId => '']);

        try {
            $result = Plugin::getInstance()->getSubmissionService()->createFromRequest($form, $request);
        } finally {
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
        }

        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('field_' . $sigId, $result['errors'] ?? []);
        $this->assertFalse($stub->saveCalled, 'no asset should be created for an empty required signature');
    }

    public function testMalformedDataUrlRejectedBeforeAsset(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Waiver Junk', 'waiver_junk');
        $sigId = $this->createField($form->id, 'signature', 'sig', 'Signature', true);

        $stub = new class() extends AssetUploadService {
            public bool $saveCalled = false;
            public function saveTempFiles(array $files, array $fieldConfig): array
            {
                $this->saveCalled = true;
                return [1];
            }
        };
        Plugin::getInstance()->set('assetUploadService', $stub);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'waiver_junk',
            'field_' . $sigId => 'data:text/html;base64,' . base64_encode('<script>alert(1)</script>'),
        ]);

        try {
            $result = Plugin::getInstance()->getSubmissionService()->createFromRequest($form, $request);
        } finally {
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
        }

        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('field_' . $sigId, $result['errors'] ?? []);
        $this->assertFalse($stub->saveCalled);
    }

    public function testFailedSubmissionRollsBackSignatureAsset(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Waiver Rollback', 'waiver_rollback');
        $requiredTextId = $this->createField($form->id, 'text', 'name', 'Name', true);
        $sigId = $this->createField($form->id, 'signature', 'sig', 'Signature');

        $stub = new class() extends AssetUploadService {
            /** @var list<int> */
            public array $deleted = [];
            public function saveTempFiles(array $files, array $fieldConfig): array
            {
                return [8888];
            }
            public function deleteAssets(int ...$ids): void
            {
                $this->deleted = $ids;
            }
        };
        Plugin::getInstance()->set('assetUploadService', $stub);

        $request = Craft::$app->getRequest();
        // Valid signature, but the required text field is empty → submission fails.
        $request->setBodyParams([
            'formHandle' => 'waiver_rollback',
            'field_' . $requiredTextId => '',
            'field_' . $sigId => $this->validPngDataUrl(),
        ]);

        try {
            $result = Plugin::getInstance()->getSubmissionService()->createFromRequest($form, $request);
        } finally {
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
        }

        $this->assertNull($result['submission']);
        $this->assertSame([8888], $stub->deleted, 'the signature asset must be rolled back when the submission fails');
    }
}
