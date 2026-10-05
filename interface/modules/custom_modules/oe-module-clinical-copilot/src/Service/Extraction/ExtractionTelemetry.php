<?php

/**
 * Timing, token usage and a deterministic completeness measure for one VLM
 * extraction call, captured for LangfuseTracer. Holds counts and schema field
 * *names* only -- never extracted values, which are patient data.
 *
 * Completeness is the share of the doc type's required schema fields that
 * came back present and well-formed (SchemaValidator's own rules). It is not
 * a model-reported confidence: it cannot be hallucinated, and a low value
 * makes an incomplete extraction visible without trusting the model to say so.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

final readonly class ExtractionTelemetry
{
    /**
     * @param float $startedAt microtime(true) reading before the API call.
     * @param float $endedAt microtime(true) reading after it returned.
     * @param int $fieldsExpected Count of the doc type's required fields.
     * @param list<string> $missingFields Required field names that were
     *                                    missing, empty or the wrong shape.
     */
    public function __construct(
        public string $model,
        public float $startedAt,
        public float $endedAt,
        public int $inputTokens,
        public int $outputTokens,
        public int $fieldsExpected,
        public array $missingFields,
    ) {
    }

    public function fieldsPresent(): int
    {
        return max(0, $this->fieldsExpected - count($this->missingFields));
    }

    public function completeness(): float
    {
        return $this->fieldsExpected === 0 ? 0.0 : round($this->fieldsPresent() / $this->fieldsExpected, 4);
    }
}
