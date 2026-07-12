<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\AssetUploadService;
use Craft;
use craft\fs\Local;
use craft\models\Volume;
use ReflectionMethod;
use SmokeTester;
use Throwable;

/**
 * Default upload-volume setting (#296): a File/Signature field without its own
 * `volume` resolves through {@see \anvildev\simpleform\models\Settings::$uploadVolume}
 * before falling back to the first available volume — the resolution order is
 * per-field volume → setting → first-available.
 *
 * Drives the private {@see AssetUploadService::resolveFolderId()} directly via
 * reflection with a null (no per-field) volume, so the setting fallback is the
 * branch under test. Two temporary local volumes are created so the setting's
 * precedence over first-available is provable regardless of what the test app
 * already has configured (it ships with none); creation is skipped-past only if
 * the environment forbids it.
 *
 * @author Fabian Haefliger
 * @since 2.17.0
 */
class UploadVolumeSettingCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * With no per-field volume, an existing `uploadVolume` handle wins: its root
     * folder is returned and it overrides the (different) first-available volume.
     */
    public function testUploadVolumeSettingIsHonored(SmokeTester $I): void
    {
        [$first, $target] = $this->twoVolumes($I);

        $firstFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($first->id)?->id;
        $targetFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($target->id)?->id;
        $I->assertNotNull($targetFolderId, 'the target volume has a root folder');
        $I->assertNotSame($firstFolderId, $targetFolderId, 'the two volumes have distinct folders');

        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->uploadVolume;
        try {
            $settings->uploadVolume = $target->handle;
            $resolved = $this->resolveFolderId(null);

            $I->assertSame($targetFolderId, $resolved, 'the uploadVolume setting resolves to its own root folder');
            $I->assertNotSame($firstFolderId, $resolved, 'the setting overrides the first-available fallback');
        } finally {
            $settings->uploadVolume = $original;
        }
    }

    /**
     * A bogus `uploadVolume` handle resolves to nothing, so resolution falls
     * through to the first available volume rather than returning null.
     */
    public function testBogusUploadVolumeFallsThroughToFirstAvailable(SmokeTester $I): void
    {
        [$first] = $this->twoVolumes($I);

        $firstFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($first->id)?->id;

        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->uploadVolume;
        try {
            $settings->uploadVolume = 'does_not_exist_' . uniqid();
            $resolved = $this->resolveFolderId(null);

            $I->assertNotNull($resolved, 'a bogus setting still resolves a folder (first-available)');
            $I->assertSame($firstFolderId, $resolved, 'a bogus setting falls through to the first available volume');
        } finally {
            $settings->uploadVolume = $original;
        }
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Ensure at least two volumes exist so the setting target differs from the
     * first-available fallback, creating temporary local volumes as needed.
     * Returns [firstAvailableVolume, lastVolume]. Skips the test if the
     * environment can't provide/allow the volumes.
     *
     * @return array{0: Volume, 1: Volume}
     */
    private function twoVolumes(SmokeTester $I): array
    {
        $volumesService = Craft::$app->getVolumes();
        while (count($volumesService->getAllVolumes()) < 2) {
            if (!$this->createTempVolume()) {
                $I->markTestSkipped('Could not create an asset volume in this environment. ' . $this->lastError);
            }
        }

        $all = array_values($volumesService->getAllVolumes());

        return [$all[0], $all[count($all) - 1]];
    }

    private string $lastError = '';

    /** Create a temporary local filesystem + volume; returns false if unsupported. */
    private function createTempVolume(): bool
    {
        try {
            $suffix = uniqid();
            // Outside Craft's system directories — a Local fs is rejected if its
            // path sits within or above the project/storage tree.
            $path = sys_get_temp_dir() . '/sf-vol-' . $suffix;
            if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
                $this->lastError = 'mkdir failed';
                return false;
            }

            $fs = new Local(['handle' => 'sfTestFs' . $suffix, 'name' => 'SF Test FS ' . $suffix, 'path' => $path]);
            if (!Craft::$app->getFs()->saveFilesystem($fs)) {
                $this->lastError = 'fs: ' . implode('; ', $fs->getFirstErrors());
                return false;
            }

            $volume = new Volume(['name' => 'SF Test Vol ' . $suffix, 'handle' => 'sfTestVol' . $suffix]);
            $volume->setFsHandle($fs->handle);

            if (!Craft::$app->getVolumes()->saveVolume($volume)) {
                $this->lastError = 'volume: ' . implode('; ', $volume->getFirstErrors());
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->lastError = get_class($e) . ': ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Invoke the private AssetUploadService::resolveFolderId() with the given
     * per-field volume handle (null = "no per-field volume").
     */
    private function resolveFolderId(mixed $volumeHandle): ?int
    {
        $service = Plugin::getInstance()->getAssetUploadService();
        $method = new ReflectionMethod(AssetUploadService::class, 'resolveFolderId');
        $method->setAccessible(true);

        /** @var int|null $result */
        $result = $method->invoke($service, $volumeHandle);

        return $result;
    }
}
