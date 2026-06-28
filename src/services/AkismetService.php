<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\fields\EmailFieldType;
use anvildev\simpleform\fields\TextFieldType;
use anvildev\simpleform\Plugin;
use Craft;
use craft\helpers\App;
use GuzzleHttp\Client;
use yii\base\Component;

/**
 * Content-based spam scoring via Akismet's comment-check API. Complements
 * captcha: it inspects the submitted text (plus IP/UA) and returns a spam
 * verdict. Fails open — an Akismet outage or missing key never blocks a
 * legitimate submission.
 *
 * @phpstan-import-type SubmissionData from Submission
 */
class AkismetService extends Component
{
    /**
     * Is this submission spam according to Akismet?
     *
     * Returns false (not spam) when Akismet is disabled, unconfigured, or the
     * API call fails — spam scoring must never reject on infrastructure trouble.
     *
     * @param SubmissionData $data the built submission data (field_<id> => {label,type,value})
     */
    public function isSpam(Form $form, array $data): bool
    {
        // Edition-blind at runtime: if Akismet is enabled it keeps scoring
        // regardless of edition, so a Pro->Solo downgrade never silently lets spam
        // through. Solo is prevented from *enabling* it (the settings authoring
        // gate); the toggle below is the real on/off switch.
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->enableAkismet) {
            return false;
        }

        $key = $this->parsedKey($settings->akismetApiKey);
        if ($key === null) {
            Craft::warning('Akismet is enabled but no API key is configured.', 'simple-form');
            return false;
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $params = [
            'blog' => Craft::$app->getSites()->getCurrentSite()->getBaseUrl() ?? $request->getHostInfo(),
            'user_ip' => $request->getUserIP() ?? '',
            'user_agent' => $request->getUserAgent() ?? '',
            'comment_type' => 'contact-form',
            'comment_content' => $this->extractContent($data),
        ];

        $author = $this->extractAuthor($data);
        if ($author['name'] !== null) {
            $params['comment_author'] = $author['name'];
        }
        if ($author['email'] !== null) {
            $params['comment_author_email'] = $author['email'];
        }

        try {
            $response = $this->httpClient()->post(
                "https://$key.rest.akismet.com/1.1/comment-check",
                ['form_params' => $params],
            );
            $body = trim((string) $response->getBody());
        } catch (\Throwable $e) {
            Craft::warning('Akismet check failed: ' . $e->getMessage(), 'simple-form');
            return false;
        }

        return $body === 'true';
    }

    /**
     * Concatenate the submitted text values into a single content blob.
     *
     * @param array<string, mixed> $data
     */
    private function extractContent(array $data): string
    {
        $parts = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['value'] ?? '';
            $value = (string) (is_array($value) ? implode(', ', array_map('strval', $value)) : $value);
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return implode("\n", $parts);
    }

    /**
     * Best-effort author name/email from the submission's email/text fields.
     *
     * @param array<string, mixed> $data
     * @return array{name: ?string, email: ?string}
     */
    private function extractAuthor(array $data): array
    {
        $name = null;
        $email = null;
        foreach ($data as $entry) {
            if (!is_array($entry) || is_array($entry['value'] ?? null) || ($value = (string) ($entry['value'] ?? '')) === '') {
                continue;
            }
            if ($email === null && ($entry['type'] ?? '') === EmailFieldType::getType()) {
                $email = $value;
            } elseif ($name === null && ($entry['type'] ?? '') === TextFieldType::getType()) {
                $name = $value;
            }
        }
        return ['name' => $name, 'email' => $email];
    }

    private function parsedKey(?string $value): ?string
    {
        if (($value ?? '') === '') {
            return null;
        }
        $parsed = App::parseEnv($value);
        return (is_string($parsed) && $parsed !== '') ? $parsed : null;
    }

    protected function httpClient(): Client
    {
        return Craft::createGuzzleClient();
    }
}
