<?php

/**
 * Timing for one tool call made during CopilotService::ask(), captured for
 * LangfuseTracer -- not part of what the model or the browser ever see.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Observability;

final readonly class ToolCallSpan
{
    /**
     * @param float $startedAt microtime(true) reading before the call.
     * @param float $endedAt microtime(true) reading after the call.
     */
    public function __construct(
        public string $name,
        public float $startedAt,
        public float $endedAt,
        public bool $success,
    ) {
    }
}
