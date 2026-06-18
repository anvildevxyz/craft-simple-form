<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\web\UploadedFile;
use fabianhaef\simpleform\fields\FileFieldType;
use fabianhaef\simpleform\Plugin;

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
