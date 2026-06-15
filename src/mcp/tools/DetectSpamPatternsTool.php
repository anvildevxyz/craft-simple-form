<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\InsightCorpus;
use fabianhaef\simpleform\mcp\tools\support\SubmissionQueryBuilder;

/**
 * AI-insight tool: flag likely-spam submissions using cheap, explainable
 * heuristics over stored submission text. Does NOT train a model.
 *
 * SIGNAL PROVENANCE
 * -----------------
 * The plugin's honeypot and captcha checks run BEFORE a submission is stored
 * (see {@see \fabianhaef\simpleform\services\SubmissionService}): a filled
 * honeypot or a failed captcha is rejected outright, so those spam attempts
 * never become rows. This tool therefore operates on the submissions that DID
 * get through and reuses the same notion of spam those defences encode (junk
 * content) via content heuristics:
 *
 *  - duplicateContent — the same text body appears in more than one submission
 *    (a hallmark of bot replays the front-line defences can't always stop);
 *  - excessiveLinks   — the body contains many URLs (configurable threshold);
 *  - shouting         — the body is overwhelmingly upper-case.
 *
 * It returns flagged candidates (with the matched signals) for the client to
 * review/act on — it never deletes or mutates anything. Gated behind
 * submissions:read.
 */
class DetectSpamPatternsTool implements ToolInterface
{
    private const MAX_ROWS = 1000;

    /** Default URL count above which a body is flagged. */
    private const DEFAULT_LINK_THRESHOLD = 3;

    /** Min body length before the all-caps "shouting" signal applies. */
    private const SHOUTING_MIN_LENGTH = 20;

    public function name(): string
    {
        return 'detect_spam_patterns';
    }

    public function description(): string
    {
        return 'Flag likely-spam Simple Form submissions using explainable heuristics (duplicate '
            . 'content, excessive links, all-caps shouting) over stored submission text. Honeypot/'
            . 'captcha rejections happen before storage, so this catches junk that got through. '
            . 'Returns flagged candidates with the matched signals; never mutates data.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => QuerySubmissionsTool::filterProperties() + [
                'linkThreshold' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'URL count above which a submission is flagged. Defaults to '
                        . self::DEFAULT_LINK_THRESHOLD . '.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return Scopes::SUBMISSIONS_READ;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{scanned:int, flaggedCount:int, linkThreshold:int, signals:list<string>, flagged:list<array{id:int, dateCreated:?string, signals:list<string>, linkCount:int, text:string}>}|array{isError:true, error:string}
     */
    public function call(array $arguments): array
    {
        $built = SubmissionQueryBuilder::build($arguments);
        if (is_array($built)) {
            return $built;
        }
        /** @var SubmissionQuery $query */
        $query = $built;
        $query->with(['form']);

        $fieldMatch = is_array($arguments['fieldMatch'] ?? null) ? $arguments['fieldMatch'] : [];
        $submissions = SubmissionQueryBuilder::applyFieldMatch($query->all(), $fieldMatch);
        $submissions = array_slice($submissions, 0, self::MAX_ROWS);

        $linkThreshold = max(1, (int)($arguments['linkThreshold'] ?? self::DEFAULT_LINK_THRESHOLD));

        $form = InsightCorpus::resolveForm($arguments, $submissions);
        $textHandles = $form instanceof Form
            ? InsightCorpus::freeTextHandles(InsightCorpus::fieldTypes($form))
            : [];

        // First pass: build each submission's combined text + duplicate index.
        $bodies = [];
        $byNormalized = [];
        foreach ($submissions as $submission) {
            $text = trim(implode("\n", InsightCorpus::textValues($submission, $textHandles)));
            $bodies[(int)$submission->id] = $text;
            $norm = $this->normalize($text);
            if ($norm !== '') {
                $byNormalized[$norm][] = (int)$submission->id;
            }
        }

        // Second pass: evaluate signals per submission.
        $flagged = [];
        foreach ($submissions as $submission) {
            $id = (int)$submission->id;
            $text = $bodies[$id];
            $signals = [];

            $norm = $this->normalize($text);
            if ($norm !== '' && count($byNormalized[$norm]) > 1) {
                $signals[] = 'duplicateContent';
            }

            $linkCount = $this->countLinks($text);
            if ($linkCount >= $linkThreshold) {
                $signals[] = 'excessiveLinks';
            }

            if ($this->isShouting($text)) {
                $signals[] = 'shouting';
            }

            if ($signals !== []) {
                $flagged[] = [
                    'id' => $id,
                    'dateCreated' => $submission->dateCreated?->format('c'),
                    'signals' => $signals,
                    'linkCount' => $linkCount,
                    'text' => $text,
                ];
            }
        }

        return [
            'scanned' => count($submissions),
            'flaggedCount' => count($flagged),
            'linkThreshold' => $linkThreshold,
            'signals' => ['duplicateContent', 'excessiveLinks', 'shouting'],
            'flagged' => $flagged,
        ];
    }

    /** Normalised body for duplicate detection (case/whitespace-insensitive). */
    private function normalize(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    private function countLinks(string $text): int
    {
        // Count http(s):// occurrences and bare www. domains.
        return (int)preg_match_all('#https?://|www\.#i', $text);
    }

    private function isShouting(string $text): bool
    {
        $letters = (string)preg_replace('/[^\p{L}]/u', '', $text);
        if (mb_strlen($letters) < self::SHOUTING_MIN_LENGTH) {
            return false;
        }
        $upper = (string)preg_replace('/[^\p{Lu}]/u', '', $text);
        // >80% of letters upper-case → shouting.
        return mb_strlen($upper) / mb_strlen($letters) > 0.8;
    }
}
