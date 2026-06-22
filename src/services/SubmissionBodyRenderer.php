<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\elements\Asset;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\fields\FileFieldType;
use yii\base\Component;

/**
 * Builds the plugin's default submission HTML — a titled field-value table —
 * shared by the notification email body fallback ({@see EmailService}) and the
 * default PDF layout ({@see PdfService}). Kept as its own low-level renderer so
 * neither of those services has to depend on the other.
 *
 * @phpstan-import-type SubmissionData from Submission
 */
class SubmissionBodyRenderer extends Component
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Render the default submission body (a titled field-value table) used when
     * no author-supplied template applies.
     *
     * @param SubmissionData $data
     */
    public function render(Form $form, Submission $submission, array $data): string
    {
        $html = '<html><body>'
            . '<h2>' . Craft::t('simple-form', 'New Form Submission') . '</h2>'
            . '<p>'
            . '<strong>' . Craft::t('simple-form', 'Form') . ':</strong> ' . htmlspecialchars($form->title ?? $form->name) . '<br>'
            . '<strong>' . Craft::t('simple-form', 'Date') . ':</strong> ' . $submission->dateCreated->format('Y-m-d H:i:s') . '<br>';

        if ($submission->userId && ($user = Craft::$app->getUsers()->getUserById($submission->userId))) {
            $html .= '<strong>' . Craft::t('simple-form', 'User') . ':</strong> ' . htmlspecialchars($user->fullName ?: $user->username) . '<br>';
        }

        $html .= '</p>'
            . '<hr>'
            . '<h3>' . Craft::t('simple-form', 'Submission Data') . '</h3>'
            . '<table style="border-collapse: collapse; width: 100%;">';

        foreach ($data as $fieldData) {
            $value = $fieldData['type'] === FileFieldType::getType()
                ? $this->formatFileValue($fieldData['value'])
                : $this->formatFieldValue($fieldData['value']);

            $html .= '<tr style="border-bottom: 1px solid #ddd;">'
                . '<td style="padding: 10px; font-weight: bold; width: 30%;">' . htmlspecialchars($fieldData['label']) . '</td>'
                . '<td style="padding: 10px;">' . $value . '</td>'
                . '</tr>';
        }

        return $html . '</table>'
            . '<hr>'
            . '<p style="font-size: 0.9em; color: #666;">'
            . Craft::t('simple-form', 'This is an automated message. Please do not reply directly to this email.')
            . '</p>'
            . '</body></html>';
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    private const EMPTY = '<em style="color: #999;">—</em>';

    private function formatFieldValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY;
        }

        return htmlspecialchars(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value);
    }

    /**
     * Render a file field's stored asset ids as download links (filename + URL).
     *
     * @param mixed $value list of asset ids
     */
    private function formatFileValue(mixed $value): string
    {
        $ids = is_array($value) ? $value : [];
        if ($ids === []) {
            return self::EMPTY;
        }

        $links = [];
        foreach ($ids as $id) {
            $asset = Asset::find()->id((int) $id)->one();
            if (!$asset instanceof Asset) {
                continue;
            }
            $name = htmlspecialchars((string) $asset->getFilename());
            $links[] = ($url = $asset->getUrl())
                ? '<a href="' . htmlspecialchars((string) $url) . '">' . $name . '</a>'
                : $name;
        }

        return $links === [] ? self::EMPTY : implode('<br>', $links);
    }
}
