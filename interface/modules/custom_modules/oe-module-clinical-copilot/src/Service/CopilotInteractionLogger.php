<?php

/**
 * Durable question/reply log for the Clinical Co-Pilot.
 *
 * clinical_copilot_log is the one part of the disclosure trail that
 * ChartContextTools' audit-log entries cannot cover: they record which tool
 * ran and for which patient, never the question asked or the answer given.
 * This class is what actually writes that row, on every turn, success or
 * failure -- see PUNCH_LIST.md Tier 0.3.
 *
 * A failure to write this row must never take down an otherwise-successful
 * reply, and must never be confused with a co-pilot failure in the caller's
 * own error handling -- so any database error here is logged and swallowed,
 * not propagated.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;

final class CopilotInteractionLogger
{
    /** @param list<string> $toolsUsed */
    public function logSuccess(
        string $correlationId,
        int $patientId,
        string $user,
        string $question,
        string $reply,
        array $toolsUsed,
        string $model,
        int $latencyMs,
        bool $verificationPassed,
    ): void {
        $this->insert(
            $correlationId,
            $patientId,
            $user,
            $question,
            $reply,
            $toolsUsed,
            $model,
            true,
            $latencyMs,
            $verificationPassed,
        );
    }

    public function logFailure(
        string $correlationId,
        int $patientId,
        string $user,
        string $question,
        string $model,
        int $latencyMs,
    ): void {
        // A request that never produced an answer has nothing to have
        // verified -- null, not false, so it reads as "not applicable"
        // rather than "failed verification" in PUNCH_LIST.md 3.3's
        // eventual pass/fail-rate dashboard.
        $this->insert($correlationId, $patientId, $user, $question, null, [], $model, false, $latencyMs, null);
    }

    /** @param list<string> $toolsUsed */
    private function insert(
        string $correlationId,
        int $patientId,
        string $user,
        string $question,
        ?string $reply,
        array $toolsUsed,
        string $model,
        bool $success,
        int $latencyMs,
        ?bool $verificationPassed,
    ): void {
        try {
            QueryUtils::sqlInsert(
                'INSERT INTO `clinical_copilot_log`
                    (`correlation_id`, `pid`, `user`, `asked_at`, `question`, `reply`,
                     `tools_used`, `model`, `success`, `latency_ms`, `verification_passed`)
                 VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)',
                [
                    $correlationId,
                    $patientId,
                    $user,
                    $question,
                    $reply,
                    $toolsUsed === [] ? null : implode(',', $toolsUsed),
                    $model,
                    $success ? 1 : 0,
                    $latencyMs,
                    $verificationPassed === null ? null : ($verificationPassed ? 1 : 0),
                ],
            );
        } catch (SqlQueryException $e) {
            // Losing this row costs traceability, not correctness -- the
            // clinician already has (or has been denied) their answer via
            // the response the caller already built. Never let a logging
            // failure look like a co-pilot failure.
            ServiceContainer::getLogger()->error('Clinical Co-Pilot interaction log write failed', [
                'correlationId' => $correlationId,
                'pid' => $patientId,
                'exception' => $e,
            ]);
        }
    }
}
