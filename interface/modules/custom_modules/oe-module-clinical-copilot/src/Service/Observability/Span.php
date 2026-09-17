<?php

/**
 * One OTLP span: a named interval with attributes, optionally nested under
 * a parent span in the same trace.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Observability;

final readonly class Span
{
    /**
     * @param string $spanId 16 lowercase hex characters (8 bytes), unique
     *                       within the trace.
     * @param ?string $parentSpanId 16 lowercase hex characters, or null for
     *                              a root span.
     * @param float $startedAt Unix timestamp with fractional seconds, as
     *                         returned by microtime(true).
     * @param float $endedAt Unix timestamp with fractional seconds.
     * @param array<string, string|bool|int|float> $attributes
     */
    public function __construct(
        public string $spanId,
        public ?string $parentSpanId,
        public string $name,
        public float $startedAt,
        public float $endedAt,
        public array $attributes,
    ) {
    }

    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
