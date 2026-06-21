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
        $html = '<html><body>';
        $html .= '<h2>' . Craft::t('simple-form', 'New Form Submission') . '</h2>';

        $html .= '<p>';
        $html .= '<strong>' . Craft::t('simple-form', 'Form') . ':</strong> ' . htmlspecialchars($form->title ?? $form->name) . '<br>';
        $html .= '<strong>' . Craft::t('simple-form', 'Date') . ':</strong> ' . $submission->dateCreated->format('Y-m-d H:i:s') . '<br>';

        if ($submission->userId) {
            $user = Craft::$app->getUsers()->getUserById($submission->userId);
            if ($user) {
                $html .= '<strong>' . Craft::t('simple-form', 'User') . ':</strong> ' . htmlspecialchars($user->fullName ?: $user->username) . '<br>';
            }
        }

        $html .= '</p>';

        $html .= '<hr>';
        $html .= '<h3>' . Craft::t('simple-form', 'Submission Data') . '</h3>';
        $html .= '<table style="border-collapse: collapse; width: 100%;">';

        foreach ($data as $fieldData) {
            $label = htmlspecialchars($fieldData['label']);
            $value = $fieldData['type'] === FileFieldType::getType()
                ? $this->formatFileValue($fieldData['value'])
                : $this->formatFieldValue($fieldData['value']);

            $html .= '<tr style="border-bottom: 1px solid #ddd;">';
            $html .= '<td style="padding: 10px; font-weight: bold; width: 30%;">' . $label . '</td>';
            $html .= '<td style="padding: 10px;">' . $value . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';

        $html .= '<hr>';
        $html .= '<p style="font-size: 0.9em; color: #666;">';
        $html .= Craft::t('simple-form', 'This is an automated message. Please do not reply directly to this email.');
        $html .= '</p>';

        $html .= '</body></html>';

        return $html;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    private function formatFieldValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '<em style="color: #999;">—</em>';
        }

        if (is_array($value)) {
            $stringValues = array_map('strval', $value);
            return htmlspecialchars(implode(', ', $stringValues));
        }

        return htmlspecialchars((string) $value);
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
            return '<em style="color: #999;">—</em>';
        }

        $links = [];
        foreach ($ids as $id) {
            $asset = Asset::find()->id((int) $id)->one();
            if (!$asset instanceof Asset) {
                continue;
            }
            $url = $asset->getUrl();
            $name = htmlspecialchars((string) $asset->getFilename());
            $links[] = $url
                ? '<a href="' . htmlspecialchars((string) $url) . '">' . $name . '</a>'
                : $name;
        }

        return $links === [] ? '<em style="color: #999;">—</em>' : implode('<br>', $links);
    }
}
