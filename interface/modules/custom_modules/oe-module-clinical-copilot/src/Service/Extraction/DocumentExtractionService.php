<?php

/**
 * Calls Claude with a single-turn, non-tool-loop request
 * (`$client->messages->create()`, not `$client->beta->messages->toolRunner()`
 * -- there is no multi-step tool conversation here, just one document in,
 * one structured JSON answer out) to extract fields from a lab PDF or
 * intake-form document, then self-validates the result against the exact
 * contract SchemaValidator already enforces (Eval/README.md) before
 * returning it. An extraction that fails schema_valid is never silently
 * trusted downstream -- DocumentIngestionPipeline persists nothing on a
 * failure result.
 *
 * A lab PDF is a panel: the model returns one flat result object per test row
 * (`{"doc_type":"lab_pdf","results":[...]}`), and each becomes its own
 * ExtractedDocument with the same single-result schema as before, so nothing
 * downstream of this class had to learn a new shape. The outcome is
 * all-or-nothing -- one invalid result rejects the whole upload and every
 * failing field is reported as `results[i].field`, so an incomplete panel is
 * never stored as if it were complete.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use Anthropic\Messages\TextBlock;
use JsonException;
use OpenEMR\Modules\ClinicalCopilot\Service\AnthropicClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotService;
use OpenEMR\Modules\ClinicalCopilot\Service\DefaultAnthropicClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\ExtractedDocument;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaFinding;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaValidationResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaValidator;
use RuntimeException;

final class DocumentExtractionService
{
    private const MODEL = 'claude-opus-5';

    /**
     * Sized for a full panel: each result carries its own citation and bbox
     * (~150 output tokens), so MAX_RESULTS rows need far more than the 4096
     * a single-result extraction did. The much longer request timeout below
     * goes with it -- see AnthropicClientFactory::create().
     */
    private const MAX_TOKENS = 16000;

    private const REQUEST_TIMEOUT_SECONDS = 300.0;

    /** Hard cap on results taken from one lab document; more is rejected, not truncated. */
    public const MAX_RESULTS = 50;

    public function __construct(
        private readonly AnthropicClientFactory $clientFactory = new DefaultAnthropicClientFactory(),
    ) {
    }

    public function extract(string $correlationId, DocumentPayload $payload, SchemaDocType $docType): ExtractionResult
    {
        $apiKey = CopilotService::apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('Clinical Co-Pilot is not configured.');
        }

        $client = $this->clientFactory->create($apiKey, $correlationId, self::REQUEST_TIMEOUT_SECONDS);

        $startedAt = microtime(true);
        $message = $client->messages->create(
            maxTokens: self::MAX_TOKENS,
            messages: [[
                'role' => 'user',
                'content' => [
                    $payload->toContentBlock(),
                    ['type' => 'text', 'text' => ExtractionPromptBuilder::build($docType)],
                ],
            ]],
            model: self::MODEL,
        );

        $endedAt = microtime(true);

        $result = self::parse($message->content, $docType, $message->stopReason);

        $expectedPerResult = SchemaValidator::requiredFieldNames($docType);
        $validation = $result->validation;
        // No parsed document means nothing usable came back: every field of one
        // result is missing. Otherwise the missing fields are exactly the
        // validator's findings across all results.
        if ($result->documents === []) {
            $missing = $expectedPerResult;
        } elseif ($validation === null) {
            $missing = [];
        } else {
            $missing = array_map(
                static fn (SchemaFinding $finding): string => $finding->field,
                $validation->findings,
            );
        }

        $resultCount = max(1, count($result->documents));

        return $result->withTelemetry(new ExtractionTelemetry(
            model: self::MODEL,
            startedAt: $startedAt,
            endedAt: $endedAt,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            fieldsExpected: count($expectedPerResult) * $resultCount,
            missingFields: $missing,
            resultCount: $resultCount,
        ));
    }

    /**
     * @param list<mixed> $content The model response's content blocks.
     */
    private static function parse(array $content, SchemaDocType $docType, ?string $stopReason): ExtractionResult
    {
        // A response cut off at the token limit is invalid JSON by construction;
        // say why, rather than the misleading "not valid JSON".
        if ($stopReason === 'max_tokens') {
            return ExtractionResult::failedToParse('model response was cut off; the document has too many results to extract at once');
        }

        $text = self::firstTextBlock($content);
        if ($text === null) {
            return ExtractionResult::failedToParse('model returned no text content');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ExtractionResult::failedToParse('model response was not valid JSON');
        }

        if (!is_array($decoded)) {
            return ExtractionResult::failedToParse('model response did not match the doc_type/fields envelope');
        }

        $rawDocType = $decoded['doc_type'] ?? null;
        $declaredDocType = is_string($rawDocType) ? SchemaDocType::tryFrom($rawDocType) : null;
        if ($declaredDocType === null) {
            return ExtractionResult::failedToParse('model response did not match the doc_type/fields envelope');
        }

        if ($declaredDocType !== $docType) {
            return ExtractionResult::failedToParse(sprintf(
                'model returned doc_type %s but %s was requested',
                $declaredDocType->value,
                $docType->value,
            ));
        }

        $isPanel = $docType === SchemaDocType::LabPdf && array_key_exists('results', $decoded);
        $documents = [];
        if ($isPanel) {
            $results = $decoded['results'];
            if (!is_array($results) || !array_is_list($results)) {
                return ExtractionResult::failedToParse('model response did not match the doc_type/results envelope');
            }
            if ($results === []) {
                return ExtractionResult::failedToParse('model response contained no results');
            }
            if (count($results) > self::MAX_RESULTS) {
                return ExtractionResult::failedToParse(sprintf(
                    'document has more than %d results; split it and upload again',
                    self::MAX_RESULTS,
                ));
            }

            foreach ($results as $item) {
                $document = ExtractedDocument::fromMixed(['doc_type' => $docType->value, 'fields' => $item]);
                if ($document === null) {
                    return ExtractionResult::failedToParse('model response did not match the doc_type/results envelope');
                }
                $documents[] = $document;
            }
        } else {
            // An intake form, or a lab response in the original single-result
            // `fields` shape (still accepted).
            $document = ExtractedDocument::fromMixed($decoded);
            if ($document === null) {
                return ExtractionResult::failedToParse('model response did not match the doc_type/fields envelope');
            }
            $documents[] = $document;
        }

        $findings = [];
        foreach ($documents as $index => $document) {
            foreach (SchemaValidator::validate($document)->findings as $finding) {
                $findings[] = $isPanel
                    ? new SchemaFinding(sprintf('results[%d].%s', $index, $finding->field), $finding->reason)
                    : $finding;
            }
        }

        if ($findings !== []) {
            return ExtractionResult::schemaInvalid($documents, new SchemaValidationResult(false, $findings));
        }

        return ExtractionResult::success($documents);
    }

    /**
     * @param list<mixed> $content
     */
    private static function firstTextBlock(array $content): ?string
    {
        foreach ($content as $block) {
            if ($block instanceof TextBlock) {
                return $block->text;
            }
        }

        return null;
    }
}
