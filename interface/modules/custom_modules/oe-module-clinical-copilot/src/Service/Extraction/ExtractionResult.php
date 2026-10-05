<?php

/**
 * DocumentExtractionService's outcome: either schema-valid extractions
 * ready to persist, schema-invalid ones (the model returned something, but
 * SchemaValidator rejected it -- kept for diagnostics, never persisted), or a
 * parse failure (the model's response was not even valid JSON/envelope
 * shape). Mirrors
 * OpenEMR\Modules\ClinicalCopilot\Service\Verification\VerificationOutcome's
 * "success value or rejection reason" pattern.
 *
 * `documents` is a list because a lab PDF is a panel: one document yields one
 * ExtractedDocument per test result row, each keeping the flat single-result
 * schema (and its own source bbox). An intake form always yields exactly one.
 * The outcome is all-or-nothing: if any result fails validation, none are
 * persisted and `validation` names every failing field.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\ExtractedDocument;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaValidationResult;

final readonly class ExtractionResult
{
    /**
     * @param list<ExtractedDocument> $documents Empty only for a parse failure.
     */
    private function __construct(
        public bool $success,
        public array $documents,
        public ?SchemaValidationResult $validation,
        public ?string $failureReason,
        public ?ExtractionTelemetry $telemetry = null,
    ) {
    }

    public function withTelemetry(ExtractionTelemetry $telemetry): self
    {
        return new self($this->success, $this->documents, $this->validation, $this->failureReason, $telemetry);
    }

    /**
     * @param list<ExtractedDocument> $documents
     */
    public static function success(array $documents): self
    {
        return new self(true, $documents, null, null);
    }

    /**
     * @param list<ExtractedDocument> $documents
     */
    public static function schemaInvalid(array $documents, SchemaValidationResult $validation): self
    {
        return new self(false, $documents, $validation, null);
    }

    public static function failedToParse(string $reason): self
    {
        return new self(false, [], null, $reason);
    }
}
