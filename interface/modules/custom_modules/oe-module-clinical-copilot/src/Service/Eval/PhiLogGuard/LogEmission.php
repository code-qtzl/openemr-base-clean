<?php

/**
 * One payload bound for a log/trace sink, paired with where it is going.
 * The destination is what PhiLogGuardValidator gates on -- the same field
 * name (e.g. `quote_or_value`) is required in an `application_response`
 * payload and forbidden in a telemetry payload. See
 * .claude/skills/eval-phi-log-guard/SKILL.md's "The Boundary This Skill
 * Enforces" section.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\PhiLogGuard;

final readonly class LogEmission
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public string $logTarget,
        public array $payload,
    ) {
    }

    /**
     * Parses an untrusted `{"log_target": ..., "payload": {...}}` shape
     * (from a golden-set fixture, or logging/observability code under
     * review) into a typed value.
     */
    public static function fromMixed(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $logTarget = $raw['log_target'] ?? null;
        if (!is_string($logTarget) || trim($logTarget) === '') {
            return null;
        }

        $payload = $raw['payload'] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return new self($logTarget, $payload);
    }
}
