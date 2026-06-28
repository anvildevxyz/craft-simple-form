<?php

namespace modules\simpleform\examples;

use Craft;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\IntegrationResult;
use anvildev\simpleform\integrations\IntegrationTypeInterface;

/**
 * Example custom outbound integration: POST a submission's data to a configured
 * URL as JSON. Demonstrates the full IntegrationTypeInterface — a settings form,
 * settings validation, and the dispatch in send() returning an IntegrationResult
 * the dispatch log can record (and retry on failure).
 *
 * Register it from your plugin/module init():
 *
 *   \yii\base\Event::on(
 *       \anvildev\simpleform\Plugin::class,
 *       \anvildev\simpleform\Plugin::EVENT_REGISTER_INTEGRATION_TYPES,
 *       fn($e) => $e->types[] = \modules\simpleform\examples\JsonWebhookIntegration::class,
 *   );
 *
 * Scaffold your own with:  php craft simple-form/make/integration
 */
class JsonWebhookIntegration implements IntegrationTypeInterface
{
    public static function handle(): string
    {
        return 'jsonWebhook';
    }

    public static function displayName(): string
    {
        return 'JSON Webhook (example)';
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function settingsHtml(array $settings): string
    {
        $url = htmlspecialchars((string) ($settings['url'] ?? ''), ENT_QUOTES);

        // In a real connector, render this with Craft's CP form macros for
        // consistent styling; plain HTML keeps the example self-contained.
        return '<label>Endpoint URL'
            . '<input type="url" name="url" value="' . $url . '"></label>';
    }

    /**
     * @return array<int, mixed>
     */
    public function defineSettingsRules(): array
    {
        return [
            ['url', 'required'],
            ['url', 'url'],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $url = (string) ($settings['url'] ?? '');
        if ($url === '') {
            return IntegrationResult::failure(null, 'No endpoint URL configured.');
        }

        $payload = (string) json_encode([
            'formId' => $submission->formId,
            'submissionId' => $submission->id,
            'data' => $submission->data ?? [],
        ]);

        try {
            $response = Craft::createGuzzleClient()->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $payload,
                'timeout' => 10,
            ]);
            $code = $response->getStatusCode();

            return $code >= 200 && $code < 300
                ? IntegrationResult::success($code, 'Delivered.')
                : IntegrationResult::failure($code, "Endpoint returned HTTP $code.");
        } catch (\Throwable $e) {
            // Returning failure (not throwing) lets the dispatcher log + retry.
            return IntegrationResult::failure(null, $e->getMessage());
        }
    }
}
