<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\web\Response;
use craft\web\UploadedFile;
use fabianhaef\simpleform\controllers\SubmitController;
use fabianhaef\simpleform\fields\FileFieldType;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\AssetUploadService;

/**
 * #89 — file-upload field: server-side upload validation, the asset-id storage
 * path through submit(), and (best-effort) real asset persistence.
 *
 * @group requires-craft
 */
class FileUploadTest extends SimpleFormTestCase
{
    private function tmpFile(string $name, string $contents = 'x'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sf');
        file_put_contents($path, $contents);
        return new UploadedFile([
            'name' => $name,
            'tempName' => $path,
            'type' => 'application/octet-stream',
            'size' => strlen($contents),
            'error' => UPLOAD_ERR_OK,
        ]);
    }

    public function testValidateUploadAcceptsAllowedFile(): void
    {
        $this->requireCraft();
        $field = new FileFieldType(['allowedExtensions' => 'pdf', 'maxSize' => 5]);
        $this->assertSame([], $field->validateUpload([$this->tmpFile('doc.pdf')]));
    }

    public function testValidateUploadRejectsWrongExtension(): void
    {
        $this->requireCraft();
        $field = new FileFieldType(['allowedExtensions' => 'pdf']);
        $errors = $field->validateUpload([$this->tmpFile('evil.exe')]);
        $this->assertNotEmpty($errors);
    }

    public function testValidateUploadRejectsOversizeFile(): void
    {
        $this->requireCraft();
        // 1-byte max; a 5-char file exceeds it.
        $field = new FileFieldType(['maxSize' => 1 / (1024 * 1024)]);
        $errors = $field->validateUpload([$this->tmpFile('big.txt', 'hello')]);
        $this->assertNotEmpty($errors);
    }

    public function testValidateUploadRequiredWhenEmpty(): void
    {
        $this->requireCraft();
        $field = new FileFieldType(['required' => true]);
        $this->assertNotEmpty($field->validateUpload([]));

        $optional = new FileFieldType(['required' => false]);
        $this->assertSame([], $optional->validateUpload([]));
    }

    public function testValidateUploadRejectsMultipleWhenSingle(): void
    {
        $this->requireCraft();
        $field = new FileFieldType(['multiple' => false]);
        $errors = $field->validateUpload([$this->tmpFile('a.txt'), $this->tmpFile('b.txt')]);
        $this->assertNotEmpty($errors);
    }

    public function testSubmitStoresAssetIdsForFileField(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Upload', 'upload_form');
        $fileFieldId = $this->createField($form->id, 'file', 'attachment', 'Attachment');

        // submit() is transport-agnostic: the file field's value is the asset-id
        // list the request adapter resolved from the uploads.
        $result = Plugin::getInstance()->getSubmissionService()->submit(
            $form,
            ['field_' . $fileFieldId => [4242, 4243]],
            ['skipCaptcha' => true],
        );

        $this->assertNotNull($result['submission']);
        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $result['submission']->id])->one();
        $data = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);
        $this->assertSame('file', $data['field_' . $fileFieldId]['type']);
        $this->assertSame([4242, 4243], $data['field_' . $fileFieldId]['value']);
    }

    /**
     * Regression for the smoke-test finding: SubmitController must route through
     * the upload-aware createFromRequest(), not call submit() with body params
     * only (which silently drops file uploads, storing null). Drives the real
     * controller with an injected $_FILES entry + a stubbed asset service so it
     * needs no volume.
     */
    public function testSubmitControllerProcessesFileUploads(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Upload Wiring', 'upload_wiring');
        $textId = $this->createField($form->id, 'text', 'name', 'Name');
        $fileId = $this->createField($form->id, 'file', 'attachment', 'Attachment'); // no ext restriction

        // Stub the asset service so the assertion is volume-independent.
        Plugin::getInstance()->set('assetUploadService', new class extends AssetUploadService {
            public function saveUploads(array $files, array $fieldConfig): array
            {
                return [9999];
            }
        });

        $tmp = tempnam(sys_get_temp_dir(), 'sfu');
        file_put_contents($tmp, '%PDF-1.4');
        $_FILES['field_' . $fileId] = [
            'name' => 'doc.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => 8,
        ];
        UploadedFile::reset();

        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => 'upload_wiring', 'field_' . $textId => 'Ada']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        try {
            $controller = new SubmitController('submit', Plugin::getInstance());
            $controller->enableCsrfValidation = false;
            $response = $controller->actionIndex();
            $this->assertTrue(($response->data['success'] ?? false) === true, (string) json_encode($response->data));
        } finally {
            unset($_FILES['field_' . $fileId]);
            UploadedFile::reset();
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
            @unlink($tmp);
        }

        $row = (new Query())->from('{{%simpleform_submissions}}')
            ->where(['formId' => $form->id])->orderBy(['id' => SORT_DESC])->one();
        $data = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);
        $this->assertSame([9999], $data['field_' . $fileId]['value'], 'file field must carry the uploaded asset ids, not null');
    }

    /**
     * Regression (found by the craft-review workflow): when an upload succeeds but
     * a sibling required field fails validation, the just-created assets must be
     * rolled back so no orphan asset is left behind (createFromRequest lines that
     * call deleteAssets() when no submission persists). Volume-independent via a
     * stub that records the deleted ids.
     */
    public function testFailedSubmissionRollsBackUploadedAssets(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Upload Rollback', 'upload_rollback');
        $requiredTextId = $this->createField($form->id, 'text', 'name', 'Name', true); // required
        $fileId = $this->createField($form->id, 'file', 'attachment', 'Attachment');

        $stub = new class extends AssetUploadService {
            /** @var list<int> */
            public array $deleted = [];
            public function saveUploads(array $files, array $fieldConfig): array
            {
                return [9999];
            }
            public function deleteAssets(int ...$ids): void
            {
                $this->deleted = $ids;
            }
        };
        Plugin::getInstance()->set('assetUploadService', $stub);

        $tmp = tempnam(sys_get_temp_dir(), 'sfu');
        file_put_contents($tmp, '%PDF-1.4');
        $_FILES['field_' . $fileId] = [
            'name' => 'doc.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => 8,
        ];
        UploadedFile::reset();

        $request = Craft::$app->getRequest();
        // Upload a file but leave the REQUIRED text field empty → validation fails.
        $request->setBodyParams(['formHandle' => 'upload_rollback', 'field_' . $requiredTextId => '']);

        try {
            $result = Plugin::getInstance()->getSubmissionService()->createFromRequest($form, $request);
        } finally {
            unset($_FILES['field_' . $fileId]);
            UploadedFile::reset();
            Plugin::getInstance()->set('assetUploadService', AssetUploadService::class);
            @unlink($tmp);
        }

        // (a) no submission, with a field error on the required text field
        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('field_' . $requiredTextId, $result['errors'] ?? []);
        // (b) no orphan submission row
        $count = (new Query())->from('{{%simpleform_submissions}}')->where(['formId' => $form->id])->count();
        $this->assertSame(0, (int) $count);
        // (c) the created asset was rolled back
        $this->assertSame([9999], $stub->deleted, 'orphaned upload assets must be deleted when the submission fails');
    }

    public function testSaveUploadsCreatesAssetWhenVolumeAvailable(): void
    {
        $this->requireCraft();
        if (Craft::$app->getVolumes()->getAllVolumes() === []) {
            $this->markTestSkipped('No asset volume configured in the test environment.');
        }

        $ids = Plugin::getInstance()->getAssetUploadService()
            ->saveUploads([$this->tmpFile('hello.txt', 'hello world')], []);

        $this->assertCount(1, $ids);
        $asset = \craft\elements\Asset::find()->id($ids[0])->one();
        $this->assertInstanceOf(\craft\elements\Asset::class, $asset);

        // Clean up.
        Plugin::getInstance()->getAssetUploadService()->deleteAssets(...$ids);
    }
}
