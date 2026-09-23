<?php

/**
 * factually_consistent outcome for one claim or one batch of claims.
 * `toArray()` matches SKILL.md's "Eval Output" / "Failure Reporting" JSON
 * shape exactly, so it is the machine-readable result the Week 2 eval gate
 * consumes for the `factually_consistent` rubric category.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\FactualConsistency;

final readonly class FactualConsistencyResult
{
    /**
     * @param list<FactualConsistencyFinding> $findings Empty when $factuallyConsistent is true.
     */
    public function __construct(
        public bool $factuallyConsistent,
        public array $findings,
    ) {
    }

    /**
     * @return array{
     *     factually_consistent: bool,
     *     failures: list<array{claim: string, asserted_value: string, quote_or_value: string, reason: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'factually_consistent' => $this->factuallyConsistent,
            'failures' => array_map(
                static fn (FactualConsistencyFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
