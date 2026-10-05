<?php

/**
 * DocumentIngestionPipeline's outcome: either the stored extraction and
 * document ids plus the validated document, or the ExtractionResult that
 * stopped the pipeline before anything was persisted.
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
    private function __construct(
        public bool $success,
        public ?int $extractionId,
        public ?int $documentId,
        public ?ExtractedDocument $document,
        public ?ExtractionResult $extractionFailure,
        public ?ExtractionTelemetry $telemetry = null,
    ) {
    }

    public static function success(
        int $extractionId,
        int $documentId,
        ExtractedDocument $document,
        ?ExtractionTelemetry $telemetry = null,
    ): self {
        return new self(true, $extractionId, $documentId, $document, null, $telemetry);
    }

    public static function extractionFailed(ExtractionResult $extractionResult): self
    {
        return new self(false, null, null, null, $extractionResult);
    }
}
