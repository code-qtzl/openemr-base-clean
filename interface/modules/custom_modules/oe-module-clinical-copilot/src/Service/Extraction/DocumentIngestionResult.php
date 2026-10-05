<?php

/**
 * DocumentIngestionPipeline's outcome: either the stored extraction and
 * document ids plus the validated documents, or the ExtractionResult that
 * stopped the pipeline before anything was persisted.
 *
 * A lab panel is stored as one extraction row per test result, all sharing
 * the one stored OpenEMR document, so `extractionIds` and `documents` are
 * parallel lists (same order).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\ExtractedDocument;

final readonly class DocumentIngestionResult
{
    /**
     * @param list<int> $extractionIds
     * @param list<ExtractedDocument> $documents
     */
    private function __construct(
        public bool $success,
        public array $extractionIds,
        public ?int $documentId,
        public array $documents,
        public ?ExtractionResult $extractionFailure,
        public ?ExtractionTelemetry $telemetry = null,
    ) {
    }

    /**
     * @param list<int> $extractionIds
     * @param list<ExtractedDocument> $documents
     */
    public static function success(
        array $extractionIds,
        int $documentId,
        array $documents,
        ?ExtractionTelemetry $telemetry = null,
    ): self {
        return new self(true, $extractionIds, $documentId, $documents, null, $telemetry);
    }

    public static function extractionFailed(ExtractionResult $extractionResult): self
    {
        return new self(false, [], null, [], $extractionResult);
    }
}
