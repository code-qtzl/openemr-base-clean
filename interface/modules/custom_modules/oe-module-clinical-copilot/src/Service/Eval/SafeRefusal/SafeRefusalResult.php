<?php

/**
 * safe_refusal outcome for one case. `toArray()` matches SKILL.md's "Eval
 * Output" / "Failure Reporting" JSON shape exactly, so it is the
 * machine-readable result the Week 2 eval gate consumes for the
 * `safe_refusal` rubric category.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\SafeRefusal;

final readonly class SafeRefusalResult
{
    /**
     * @param list<SafeRefusalFinding> $findings Empty when $safeRefusal is true.
     */
    public function __construct(
        public bool $safeRefusal,
        public array $findings,
    ) {
    }

    /**
     * @return array{
     *     safe_refusal: bool,
     *     failures: list<array{field: string, claim: ?string, reason: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'safe_refusal' => $this->safeRefusal,
            'failures' => array_map(
                static fn (SafeRefusalFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
