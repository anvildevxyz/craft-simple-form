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
            $asset = new Asset();
            $asset->tempFilePath = $file->tempName;
            $asset->setFilename($file->name);
            $asset->newFolderId = $folderId;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            $asset->avoidFilenameConflicts = true;

            if (Craft::$app->getElements()->saveElement($asset)) {
                $ids[] = (int) $asset->id;
            } else {
                Craft::warning('Failed to save uploaded asset: ' . implode(', ', $asset->getFirstErrors()), 'simple-form');
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

    private function resolveFolderId(mixed $volumeHandle): ?int
    {
        $volumes = Craft::$app->getVolumes();

        $volume = null;
        if (is_string($volumeHandle) && $volumeHandle !== '') {
            $volume = $volumes->getVolumeByHandle($volumeHandle);
        }
        $volume ??= $volumes->getAllVolumes()[0] ?? null;
        if ($volume === null) {
            return null;
        }

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        return $folder?->id;
    }
}
