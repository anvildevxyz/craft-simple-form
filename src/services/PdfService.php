<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\FileHelper;
use craft\web\View;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\FieldModel;
use fabianhaef\simpleform\pdf\DompdfEngine;
use fabianhaef\simpleform\pdf\PdfEngineInterface;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Renders a submission to a PDF (#143) from the overridable, sandboxed
 * `forms/notifications/pdf` Twig template, and optionally persists it as an
 * Asset. The PDF engine (dompdf) is an *optional* dependency resolved lazily
 * through {@see PdfEngineInterface}: when it is absent {@see isAvailable()}
 * returns false and the PDF features degrade gracefully (CP toggles disabled,
 * the notification email still sends without the attachment).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class PdfService extends Component
{
    /** Overridable template rendered into the PDF body. */
    public const TEMPLATE = 'simple-form/forms/notifications/pdf';

    // =========================================================================
    // PRIVATE PROPERTIES
    // =========================================================================

    private ?PdfEngineInterface $_engine = null;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Whether a usable PDF engine is installed. When false, every other method
     * here is a no-op and PDF toggles must stay disabled in the CP.
     */
    public function isAvailable(): bool
    {
        return $this->getEngine()?->isAvailable() ?? false;
    }

    /**
     * Render the submission to PDF bytes via the sandboxed template + engine, or
     * null when no engine is available or rendering fails. Never throws so a
     * notification send can fall back to no attachment.
     *
     * @param array<string, mixed> $data submission data keyed by field_<id>
     */
    public function render(Form $form, Submission $submission, array $data, ?int $siteId = null): ?string
    {
        $engine = $this->getEngine();
        if ($engine === null || !$engine->isAvailable()) {
            return null;
        }

        try {
            $html = $this->renderHtml($form, $submission, $data, $siteId);
            return $engine->renderHtml($html);
        } catch (\Throwable $e) {
            Craft::warning('Failed to render submission PDF: ' . $e->getMessage(), 'simple-form');
            return null;
        }
    }

    /**
     * Render the PDF and persist it as an Asset in the configured storage volume,
     * returning the Asset (or null when no engine, no volume, or on failure). When
     * an Asset for this submission already exists it is reused rather than
     * duplicated.
     *
     * @param array<string, mixed> $data
     */
    public function store(Form $form, Submission $submission, array $data): ?Asset
    {
        $volumeHandle = Plugin::getInstance()->getSettings()->pdfStorageVolume;
        if ($volumeHandle === null || $volumeHandle === '') {
            return null;
        }

        $folderId = $this->resolveFolderId($volumeHandle);
        if ($folderId === null) {
            Craft::warning(sprintf('PDF storage volume "%s" not found.', $volumeHandle), 'simple-form');
            return null;
        }

        $filename = $this->filename($form, $submission);

        $existing = Asset::find()->folderId($folderId)->filename($filename)->one();
        if ($existing instanceof Asset) {
            return $existing;
        }

        $bytes = $this->render($form, $submission, $data, (int) $submission->siteId);
        if ($bytes === null) {
            return null;
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $filename;
        FileHelper::writeToFile($tempPath, $bytes);

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->newFolderId = $folderId;
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $asset->avoidFilenameConflicts = true;

        if (!Craft::$app->getElements()->saveElement($asset)) {
            Craft::warning('Failed to store submission PDF as asset: ' . implode(', ', $asset->getFirstErrors()), 'simple-form');
            return null;
        }

        return $asset;
    }

    /**
     * The download filename for a submission's PDF, e.g. `contact-42.pdf`.
     */
    public function filename(Form $form, Submission $submission): string
    {
        $slug = Assets::prepareAssetName(sprintf('%s-%d.pdf', $form->handle ?: 'form', (int) $submission->id), true, true);
        return $slug;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Resolve the PDF engine once. Only dompdf in v1; selected only when its
     * library is installed so a missing dependency never fatals.
     */
    private function getEngine(): ?PdfEngineInterface
    {
        if ($this->_engine === null && class_exists(\Dompdf\Dompdf::class)) {
            $this->_engine = new DompdfEngine();
        }

        return $this->_engine;
    }

    /**
     * Render the overridable `pdf.twig` template (per the given/submission site so
     * it localises) into sandboxed HTML, falling back to a default layout when the
     * template is missing or errors.
     *
     * @param array<string, mixed> $data
     */
    private function renderHtml(Form $form, Submission $submission, array $data, ?int $siteId): string
    {
        $siteId ??= (int) $submission->siteId;
        $sites = Craft::$app->getSites();
        $restore = $sites->getCurrentSite();
        $site = $sites->getSiteById($siteId);
        if ($site !== null) {
            $sites->setCurrentSite($site);
        }

        try {
            $view = Craft::$app->getView();
            $variables = [
                'form' => $form,
                'submission' => $submission,
                'data' => $data,
            ];

            if ($view->doesTemplateExist(self::TEMPLATE, View::TEMPLATE_MODE_CP)) {
                return Plugin::getInstance()->getSafeRender()->renderTemplate(
                    self::TEMPLATE,
                    $variables,
                    [Form::class, Submission::class, FieldModel::class],
                );
            }

            // No author-supplied pdf.twig: fall back to the shared default body.
            return Plugin::getInstance()->getSubmissionBodyRenderer()->render($form, $submission, $data);
        } finally {
            $sites->setCurrentSite($restore);
        }
    }

    /**
     * Root folder id for the configured storage volume, or null when missing.
     */
    private function resolveFolderId(string $volumeHandle): ?int
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        if ($volume === null) {
            return null;
        }

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        return $folder?->id;
    }
}
