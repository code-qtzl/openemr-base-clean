<?php

/**
 * DocumentExtractionService's outcome: either a schema-valid extraction
 * ready to persist, a schema-invalid one (the model returned something,
 * but SchemaValidator rejected it -- kept for diagnostics, never
 * persisted), or a parse failure (the model's response was not even valid
 * JSON/envelope shape). Mirrors
 * OpenEMR\Modules\ClinicalCopilot\Service\Verification\VerificationOutcome's
 * "success value or rejection reason" pattern.
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
    private function __construct(
        public bool $success,
        public ?ExtractedDocument $document,
        public ?SchemaValidationResult $validation,
        public ?string $failureReason,
    ) {
    }

    public static function success(ExtractedDocument $document): self
    {
        return new self(true, $document, null, null);
    }

    public static function schemaInvalid(ExtractedDocument $document, SchemaValidationResult $validation): self
    {
        return new self(false, $document, $validation, null);
    }

    public static function failedToParse(string $reason): self
    {
        return new self(false, null, null, $reason);
    }
}
