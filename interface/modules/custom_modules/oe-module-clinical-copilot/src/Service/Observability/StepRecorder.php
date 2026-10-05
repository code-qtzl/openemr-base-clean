<?php

/**
 * Request-scoped collector of TelemetryStep timings. Create one per
 * co-pilot request, hand it to the components that do measurable work, and
 * pass steps() to LangfuseTracer::traceAsk().
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Observability;

use Closure;
use Throwable;

final class StepRecorder
{
    /** @var list<TelemetryStep> */
    private array $steps = [];

    /**
     * Runs $work, records how long it took, and returns its result. A step
     * is recorded even when $work throws (flagged `failed`, with the
     * exception class only -- never its message, which can carry SQL or
     * API detail); the exception is then rethrown unchanged.
     *
     * @template T
     * @param Closure(): T $work
     * @param (Closure(T): array<string, string|bool|int|float>)|null $describe
     *        Derives PHI-free attributes from the result (counts, scores).
     * @return T
     */
    public function measure(string $name, Closure $work, ?Closure $describe = null): mixed
    {
        $startedAt = microtime(true);

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->steps[] = new TelemetryStep($name, $startedAt, microtime(true), [
                'failed' => true,
                'exception' => $e::class,
            ]);

            throw $e;
        }

        $this->steps[] = new TelemetryStep(
            $name,
            $startedAt,
            microtime(true),
            $describe !== null ? $describe($result) : [],
        );

        return $result;
    }

    /** @return list<TelemetryStep> */
    public function steps(): array
    {
        return $this->steps;
    }
}
