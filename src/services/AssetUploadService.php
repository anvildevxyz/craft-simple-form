<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\elements\Asset;
use craft\web\UploadedFile;
use yii\base\Component;

/**
 * Turns validated form uploads into Craft Assets. Volume is taken from the
 * field's `volume` config handle, falling back to the first available volume.
 */
class AssetUploadService extends Component
{
    /**
     * Save each uploaded file as an Asset in the field's target volume and
     * return the created asset ids. Returns [] (and logs) if no volume resolves.
     *
     * @param array<int, UploadedFile> $files
     * @param array<string, mixed> $fieldConfig
     * @return list<int>
     */
    public function saveUploads(array $files, array $fieldConfig): array
    {
        $files = array_values(array_filter(
            $files,
            static fn(UploadedFile $f): bool => $f->error === UPLOAD_ERR_OK,
        ));
        if ($files === []) {
            return [];
        }

        $folderId = $this->resolveFolderId($fieldConfig['volume'] ?? null);
        if ($folderId === null) {
            Craft::warning('No asset volume available for a Simple Form file upload.', 'simple-form');
            return [];
        }

        $ids = [];
        foreach ($files as $file) {
            if (($id = $this->createAsset($file->tempName, $file->name, $folderId, 'uploaded')) !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Save pre-staged temp files (already written to disk, not posted via a
     * multipart upload) as Assets in the field's target volume, returning the
     * created asset ids. Used by the Signature field (#129), whose value arrives
     * as a base64 PNG decoded into a temp file rather than an UploadedFile, but
     * still has to land in the same managed volume with the same tooling.
     *
     * @param array<int, array{path: string, filename: string}> $files
     * @param array<string, mixed> $fieldConfig
     * @return list<int>
     */
    public function saveTempFiles(array $files, array $fieldConfig): array
    {
        $files = array_values(array_filter(
            $files,
            static fn(array $f): bool => $f['path'] !== '' && is_file($f['path']),
        ));
        if ($files === []) {
            return [];
        }

        $folderId = $this->resolveFolderId($fieldConfig['volume'] ?? null);
        if ($folderId === null) {
            Craft::warning('No asset volume available for a Simple Form signature.', 'simple-form');
            return [];
        }

        $ids = [];
        foreach ($files as $file) {
            if (($id = $this->createAsset($file['path'], $file['filename'], $folderId, 'signature')) !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Delete assets created during a submission that ultimately failed. */
    public function deleteAssets(int ...$ids): void
    {
        foreach ($ids as $id) {
            Craft::$app->getElements()->deleteElementById($id, Asset::class);
        }
    }

    /** Save one temp file as an Asset in $folderId; returns its id or null (logging) on failure. */
    private function createAsset(string $tempPath, string $filename, int $folderId, string $kind): ?int
    {
        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->newFolderId = $folderId;
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $asset->avoidFilenameConflicts = true;

        if (Craft::$app->getElements()->saveElement($asset)) {
            return (int) $asset->id;
        }

        Craft::warning("Failed to save $kind asset: " . implode(', ', $asset->getFirstErrors()), 'simple-form');
        return null;
    }

    private function resolveFolderId(mixed $volumeHandle): ?int
    {
        $volumes = Craft::$app->getVolumes();
        $volume = (is_string($volumeHandle) && $volumeHandle !== '')
            ? $volumes->getVolumeByHandle($volumeHandle)
            : null;
        $volume ??= $volumes->getAllVolumes()[0] ?? null;

        return $volume === null
            ? null
            : Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)?->id;
    }
}
