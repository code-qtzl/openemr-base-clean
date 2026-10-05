<?php

/**
 * Timing plus PHI-free attributes for one sub-step of a co-pilot request
 * (a Voyage embed, a keyword search, a rerank, ...), captured for
 * LangfuseTracer. Attributes carry counts, scores and flags only -- never
 * query text, chunk text or patient data.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Observability;

final readonly class TelemetryStep
{
    /**
     * @param float $startedAt microtime(true) reading before the step.
     * @param float $endedAt microtime(true) reading after the step.
     * @param array<string, string|bool|int|float> $attributes
     */
    public function __construct(
        public string $name,
        public float $startedAt,
        public float $endedAt,
        public array $attributes = [],
    ) {
    }
}
