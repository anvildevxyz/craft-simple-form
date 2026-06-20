<?php

namespace fabianhaef\simpleform\integrations;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Json;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\support\SubmissionValues;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Append one row per submission to a configured Google Sheets worksheet via the
 * Sheets v4 API (`spreadsheets.values.append`). Auth is either a service-account
 * JSON key or OAuth2 refresh token (see {@see AbstractGoogleIntegration}); the
 * row is built from an ordered field→column mapping, optionally preceded by a
 * header row on the first write to an empty sheet.
 *
 * Non-goals (v1): no read-back, no row update/delete, no per-form worksheet
 * creation. File fields export as their stored URL; multi-value fields are
 * comma-joined. `valueInputOption=RAW` is used so a submission can never inject a
 * formula.
 */
class GoogleSheetsIntegration extends AbstractGoogleIntegration
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /** The Sheets v4 API base; constant, so there is no SSRF surface. */
    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /** Synthetic, non-field columns the owner can also map. */
    private const SYNTHETIC_FIELDS = [
        'sf:submissionDate' => 'Submission date',
        'sf:formName' => 'Form name',
        'sf:submissionId' => 'Submission ID',
    ];

    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'google-sheets';
    }

    public static function displayName(): string
    {
        return 'Google Sheets';
    }

    public function defineSettingsRules(): array
    {
        // Inline validators are invoked with `$this` rebound to the model being
        // validated, so connector logic is reached through a captured $self.
        $self = $this;

        return [
            [['authMode'], 'in', 'range' => [self::AUTH_SERVICE_ACCOUNT, self::AUTH_OAUTH]],
            [['spreadsheetId'], 'required'],
            [['spreadsheetId', 'worksheet'], 'string'],
            [['writeHeader'], 'boolean'],
            // Credential required for the chosen mode.
            [['serviceAccountKey'], 'required', 'when' => fn($model) => ($model->authMode ?? self::AUTH_SERVICE_ACCOUNT) === self::AUTH_SERVICE_ACCOUNT],
            [['refreshToken'], 'required', 'when' => fn($model) => ($model->authMode ?? null) === self::AUTH_OAUTH],
            // Reject a malformed JSON key before save (env references are resolved
            // at dispatch time, so they can't be validated here).
            [['serviceAccountKey'], function($attribute, $params, $validator, $value) use ($self): void {
                if (!is_string($value) || $value === '' || str_starts_with(trim($value), '$')) {
                    return;
                }
                if ($self->parseServiceAccountKey($value) === null) {
                    $this->addError($attribute, Craft::t('simple-form', 'The service-account key must be valid JSON with a client_email and private_key.'));
                }
            }],
            // Require at least one mapped column (skipOnEmpty: false so an empty
            // or absent mapping is still rejected).
            [['columnMapping'], function($attribute, $params, $validator, $value) use ($self): void {
                if ($self->orderedMapping($value) === []) {
                    $this->addError($attribute, Craft::t('simple-form', 'Map at least one field to a column.'));
                }
            }, 'skipOnEmpty' => false],
        ];
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $spreadsheetId = $this->extractSpreadsheetId((string) ($settings['spreadsheetId'] ?? ''));
        if ($spreadsheetId === '') {
            return IntegrationResult::failure(null, 'No spreadsheet configured');
        }

        $mapping = $this->orderedMapping($settings['columnMapping'] ?? null);
        if ($mapping === []) {
            return IntegrationResult::failure(null, 'No field-to-column mapping configured');
        }

        $worksheet = trim((string) ($settings['worksheet'] ?? ''));
        $row = $this->buildRow($submission, $mapping);

        try {
            $token = $this->accessToken($settings);
        } catch (GoogleAuthException $e) {
            return IntegrationResult::failure(null, $e->getMessage());
        }

        // Optionally write a header row first, but only when the sheet is empty.
        if (!empty($settings['writeHeader'])) {
            $headerResult = $this->maybeWriteHeader($spreadsheetId, $worksheet, $mapping, $token, $settings);
            if ($headerResult !== null && !$headerResult->success) {
                return $headerResult;
            }
        }

        $result = $this->appendRow($spreadsheetId, $worksheet, $row, $token);

        // A 401 means the cached token expired/was revoked: refresh once, retry.
        if (!$result->success && $result->responseCode === 401) {
            try {
                $token = $this->accessToken($settings, forceRefresh: true);
            } catch (GoogleAuthException $e) {
                return IntegrationResult::failure(401, $e->getMessage());
            }
            $result = $this->appendRow($spreadsheetId, $worksheet, $row, $token);
        }

        return $result;
    }

    /**
     * Extract the spreadsheet ID from a raw setting that may be a bare ID or a
     * full `https://docs.google.com/spreadsheets/d/<ID>/edit` URL.
     */
    public function extractSpreadsheetId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $raw, $m) === 1) {
            return $m[1];
        }
        return $raw;
    }

    /**
     * Append one row to the worksheet via `values.append`. Isolated so it can be
     * exercised with a mocked client.
     *
     * @param list<string> $row
     * @internal
     */
    public function appendRow(string $spreadsheetId, string $worksheet, array $row, string $token): IntegrationResult
    {
        $range = $this->appendRange($worksheet);
        $url = sprintf('%s/%s/values/%s:append', self::API_BASE, rawurlencode($spreadsheetId), rawurlencode($range));

        try {
            $response = $this->httpClient()->request('POST', $url, [
                'query' => ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS'],
                'headers' => ['Authorization' => "Bearer $token"],
                'json' => ['values' => [$row]],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            return IntegrationResult::failure(null, $e->getMessage());
        }

        return $this->resultFromGoogle($response);
    }

    /**
     * Fetch the worksheet tab names for a spreadsheet (the "Test connection" /
     * worksheet-dropdown source). Returns null when the live fetch fails so the
     * caller can fall back to a free-text worksheet name.
     *
     * @param array<string, mixed> $settings env-resolved settings
     * @return list<string>|null
     */
    public function fetchWorksheets(string $spreadsheetId, array $settings): ?array
    {
        $spreadsheetId = $this->extractSpreadsheetId($spreadsheetId);
        if ($spreadsheetId === '') {
            return null;
        }

        try {
            $token = $this->accessToken($settings);
            $response = $this->httpClient()->request('GET', sprintf('%s/%s', self::API_BASE, rawurlencode($spreadsheetId)), [
                'query' => ['fields' => 'sheets.properties.title'],
                'headers' => ['Authorization' => "Bearer $token"],
                'http_errors' => false,
            ]);
        } catch (GoogleAuthException | GuzzleException $e) {
            Craft::warning('Google Sheets worksheet fetch failed: ' . $e->getMessage(), 'simple-form');
            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        $decoded = Json::decodeIfJson((string) $response->getBody());
        if (!is_array($decoded) || !isset($decoded['sheets']) || !is_array($decoded['sheets'])) {
            return null;
        }

        $titles = [];
        foreach ($decoded['sheets'] as $sheet) {
            $title = $sheet['properties']['title'] ?? null;
            if (is_string($title) && $title !== '') {
                $titles[] = $title;
            }
        }
        return $titles;
    }

    public function settingsHtml(array $settings): string
    {
        $authMode = (string) ($settings['authMode'] ?? self::AUTH_SERVICE_ACCOUNT);

        $html = Cp::selectFieldHtml([
            'label' => Craft::t('simple-form', 'Authentication'),
            'instructions' => Craft::t('simple-form', 'How Simple Form authenticates to Google. A service-account key is simplest for an org-owned sheet; OAuth grants access to a user’s own Drive.'),
            'id' => 'authMode',
            'name' => 'authMode',
            'options' => [
                ['label' => Craft::t('simple-form', 'Service account (JSON key)'), 'value' => self::AUTH_SERVICE_ACCOUNT],
                ['label' => Craft::t('simple-form', 'OAuth2 (refresh token)'), 'value' => self::AUTH_OAUTH],
            ],
            'value' => $authMode,
        ]);

        $html .= Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'Service-account JSON key'),
            'instructions' => Craft::t('simple-form', 'Paste the JSON key (or reference an env var). Share the spreadsheet with the key’s client_email. Stored encrypted; never echoed back.'),
            'id' => 'serviceAccountKey',
            'name' => 'serviceAccountKey',
            'value' => $settings['serviceAccountKey'] ?? '',
            'suggestEnvVars' => true,
        ]);

        $html .= Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'OAuth client ID'),
            'id' => 'clientId',
            'name' => 'clientId',
            'value' => $settings['clientId'] ?? '',
            'suggestEnvVars' => true,
        ]);
        $html .= Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'OAuth client secret'),
            'id' => 'clientSecret',
            'name' => 'clientSecret',
            'value' => $settings['clientSecret'] ?? '',
            'suggestEnvVars' => true,
        ]);
        $html .= Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'OAuth refresh token'),
            'instructions' => Craft::t('simple-form', 'A long-lived refresh token for the connected Google account. Stored encrypted; never echoed back.'),
            'id' => 'refreshToken',
            'name' => 'refreshToken',
            'value' => $settings['refreshToken'] ?? '',
            'suggestEnvVars' => true,
        ]);

        $html .= Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Spreadsheet'),
            'instructions' => Craft::t('simple-form', 'The spreadsheet ID or full edit URL.'),
            'id' => 'spreadsheetId',
            'name' => 'spreadsheetId',
            'value' => $settings['spreadsheetId'] ?? '',
            'required' => true,
        ]);
        $html .= Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Worksheet'),
            'instructions' => Craft::t('simple-form', 'The tab name to append rows to (e.g. “Sheet1”). Leave blank to use the first tab.'),
            'id' => 'worksheet',
            'name' => 'worksheet',
            'value' => $settings['worksheet'] ?? '',
        ]);

        $html .= Cp::editableTableFieldHtml([
            'label' => Craft::t('simple-form', 'Field → column mapping'),
            'instructions' => Craft::t('simple-form', 'Each row maps a submission field handle to a sheet column. Row order is the column order written to the sheet. Synthetic fields: {fields}.', [
                'fields' => implode(', ', array_keys(self::SYNTHETIC_FIELDS)),
            ]),
            'id' => 'columnMapping',
            'name' => 'columnMapping',
            'cols' => [
                'handle' => ['heading' => Craft::t('simple-form', 'Field handle'), 'type' => 'singleline'],
                'column' => ['heading' => Craft::t('simple-form', 'Column header'), 'type' => 'singleline'],
            ],
            'rows' => $this->orderedMapping($settings['columnMapping'] ?? null),
            'addRowLabel' => Craft::t('simple-form', 'Add a column'),
            'allowAdd' => true,
            'allowDelete' => true,
            'allowReorder' => true,
        ]);

        $html .= Cp::lightswitchFieldHtml([
            'label' => Craft::t('simple-form', 'Write a header row'),
            'instructions' => Craft::t('simple-form', 'On the first append to an empty sheet, write the column headers as the first row.'),
            'id' => 'writeHeader',
            'name' => 'writeHeader',
            'on' => !empty($settings['writeHeader']),
        ]);

        return $html;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Map a Google Sheets API response to an {@see IntegrationResult}. A non-2xx
     * carries the (scrubbed-downstream) status reason; the body is truncated and
     * never exposes a token (the request, not the response, holds the bearer).
     */
    protected function resultFromGoogle(ResponseInterface $response): IntegrationResult
    {
        $code = $response->getStatusCode();
        if ($code >= 200 && $code < 300) {
            return IntegrationResult::success($code, 'OK');
        }

        $reason = $this->errorReason($response);
        return IntegrationResult::failure($code, $reason);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Build the ordered row of stringified values for one submission.
     *
     * @param list<array{handle: string, column: string}> $mapping
     * @return list<string>
     */
    private function buildRow(Submission $submission, array $mapping): array
    {
        $byHandle = SubmissionValues::byHandle($submission);
        $synthetic = $this->syntheticValues($submission);

        $row = [];
        foreach ($mapping as $col) {
            $handle = $col['handle'];
            $value = str_starts_with($handle, 'sf:')
                ? ($synthetic[$handle] ?? '')
                : ($byHandle[$handle] ?? '');
            $row[] = $this->stringify($value);
        }
        return $row;
    }

    /**
     * Synthetic (non-field) values mappable alongside form fields.
     *
     * @return array<string, string>
     */
    private function syntheticValues(Submission $submission): array
    {
        return [
            'sf:submissionDate' => $submission->dateCreated?->format(\DateTimeInterface::ATOM) ?? '',
            'sf:formName' => (string) ($submission->getForm()?->title ?? ''),
            'sf:submissionId' => $submission->id !== null ? (string) $submission->id : '',
        ];
    }

    /** Coerce any submission value to a single sheet-cell string. */
    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn($v) => is_scalar($v) ? (string) $v : '', $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Write the header row, but only when the target worksheet is currently
     * empty (so resubmissions don't keep prepending headers). Returns null when
     * the header is skipped (sheet not empty), or the append result otherwise.
     *
     * @param list<array{handle: string, column: string}> $mapping
     * @param array<string, mixed> $settings
     */
    private function maybeWriteHeader(string $spreadsheetId, string $worksheet, array $mapping, string $token, array $settings): ?IntegrationResult
    {
        if (!$this->sheetIsEmpty($spreadsheetId, $worksheet, $token)) {
            return null;
        }

        $header = array_map(static fn($col) => $col['column'], $mapping);
        return $this->appendRow($spreadsheetId, $worksheet, $header, $token);
    }

    /** Whether the worksheet has no values yet (drives the one-time header). */
    private function sheetIsEmpty(string $spreadsheetId, string $worksheet, string $token): bool
    {
        $range = $worksheet !== '' ? "$worksheet!A1:Z1" : 'A1:Z1';
        $url = sprintf('%s/%s/values/%s', self::API_BASE, rawurlencode($spreadsheetId), rawurlencode($range));

        try {
            $response = $this->httpClient()->request('GET', $url, [
                'headers' => ['Authorization' => "Bearer $token"],
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            return false;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        $decoded = Json::decodeIfJson((string) $response->getBody());
        $values = is_array($decoded) ? ($decoded['values'] ?? null) : null;
        return !is_array($values) || $values === [];
    }

    /** The `values.append` range: the worksheet (whole tab) or A:A default. */
    private function appendRange(string $worksheet): string
    {
        $worksheet = trim($worksheet);
        return $worksheet !== '' ? $worksheet : 'A:A';
    }

    /**
     * Normalise the posted/saved column mapping into an ordered list of
     * `{handle, column}` rows, dropping incomplete rows. Accepts the
     * editable-table shape (list of assoc rows).
     *
     * @return list<array{handle: string, column: string}>
     */
    private function orderedMapping(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $handle = trim((string) ($row['handle'] ?? ''));
            $column = trim((string) ($row['column'] ?? ''));
            if ($handle === '' || $column === '') {
                continue;
            }
            $out[] = ['handle' => $handle, 'column' => $column];
        }
        return $out;
    }

    /** Pull a concise, token-free reason out of a Sheets error response. */
    private function errorReason(ResponseInterface $response): string
    {
        $decoded = Json::decodeIfJson((string) $response->getBody());
        if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return substr($decoded['error']['message'], 0, 300);
        }
        return substr((string) $response->getBody(), 0, 300);
    }
}
