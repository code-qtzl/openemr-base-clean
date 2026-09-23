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
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaValidator;
use RuntimeException;

final class DocumentExtractionService
{
    private const MODEL = 'claude-opus-5';
    private const MAX_TOKENS = 4096;

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

        $client = $this->clientFactory->create($apiKey, $correlationId);

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

        $text = self::firstTextBlock($message->content);
        if ($text === null) {
            return ExtractionResult::failedToParse('model returned no text content');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ExtractionResult::failedToParse('model response was not valid JSON');
        }

        $document = ExtractedDocument::fromMixed($decoded);
        if ($document === null) {
            return ExtractionResult::failedToParse('model response did not match the doc_type/fields envelope');
        }

        if ($document->docType !== $docType) {
            return ExtractionResult::failedToParse(sprintf(
                'model returned doc_type %s but %s was requested',
                $document->docType->value,
                $docType->value,
            ));
        }

        $validation = SchemaValidator::validate($document);
        if (!$validation->schemaValid) {
            return ExtractionResult::schemaInvalid($document, $validation);
        }

        return ExtractionResult::success($document);
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
