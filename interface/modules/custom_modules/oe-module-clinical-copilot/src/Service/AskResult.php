<?php

/**
 * CopilotService::ask()'s result: a verified reply plus the bookkeeping the
 * controller needs to log and return it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use OpenEMR\Modules\ClinicalCopilot\Service\Observability\ToolCallSpan;

final readonly class AskResult
{
    /**
     * @param string $reply Text that already passed (or was replaced by the
     *                       fallback of) ResponseVerifier -- always safe to
     *                       show the clinician as-is.
     * @param list<string> $toolsUsed
     * @param bool $verificationPassed Whether every claim in the model's
     *                                 answer was cited to a tool actually
     *                                 called this turn with supporting data.
     * @param ?string $verificationReason Why verification failed, when it
     *                                    did; never shown to the clinician,
     *                                    only logged.
     * @param list<ToolCallSpan> $toolCalls Per-call timing, for
     *                                      LangfuseTracer -- not shown to
     *                                      the clinician or the model.
     * @param int $inputTokens Total input tokens across every turn of this
     *                         request, for LangfuseTracer's generation span.
     * @param int $outputTokens Total output tokens across every turn.
     * @param int $retryCount SDK-level retries across every turn of this
     *                        request (AnthropicClientFactory::retryCount()),
     *                        for LangfuseTracer -- PUNCH_LIST.md 3.3.
     * @param list<array{text: string, citation: ?array<string, mixed>}> $claims
     *                        The verified answer's claims and their citations
     *                        (Citation::toArray() shape, including
     *                        document_id/bbox when present), for the browser
     *                        to render a click-to-source control. Always
     *                        empty when verificationPassed is false -- see
     *                        VerificationOutcome's docblock.
     */
    public function __construct(
        public string $reply,
        public array $toolsUsed,
        public bool $verificationPassed,
        public ?string $verificationReason,
        public array $toolCalls,
        public int $inputTokens,
        public int $outputTokens,
        public int $retryCount,
        public array $claims,
    ) {
    }
}
