<?php

/**
 * PHI-in-logs scan outcome for one log emission. `toArray()` matches
 * SKILL.md's "Eval Output" / "Failure Reporting" JSON shape exactly, so it
 * is the machine-readable result the Week 2 eval gate consumes for the
 * `no_phi_in_logs` rubric category.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\PhiLogGuard;

final readonly class PhiScanResult
{
    /**
     * @param list<PhiFinding> $findings Empty when $noPhiInLogs is true.
     */
    public function __construct(
        public bool $noPhiInLogs,
        public array $findings,
    ) {
    }

    /**
     * @return array{
     *     no_phi_in_logs: bool,
     *     failures: list<array{field: string, destination: string, reason: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'no_phi_in_logs' => $this->noPhiInLogs,
            'failures' => array_map(
                static fn (PhiFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
