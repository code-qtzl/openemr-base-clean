<?php

/**
 * One case in the PHI-in-logs golden set: a stable id, a raw log-emission
 * payload (same untrusted shape PhiLogGuardValidator::scan consumes via
 * LogEmission::fromMixed), and the expected `no_phi_in_logs` verdict. See
 * .claude/skills/eval-phi-log-guard/SKILL.md's "Golden Set Integration"
 * section for the case categories this fixture set covers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final readonly class PhiLogGuardGoldenSetCase
{
    /**
     * @param array<string, mixed> $emission Raw {"log_target": ..., "payload": {...}} shape.
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $emission,
        public bool $expectedNoPhiInLogs,
        public array $tags,
    ) {
    }
}
