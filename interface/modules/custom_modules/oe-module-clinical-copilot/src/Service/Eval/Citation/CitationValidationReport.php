<?php

/**
 * Citation-validation outcome for a full response (one or more claims).
 * `toArray()` matches SKILL.md's "Eval Output" / "Failure Reporting" JSON
 * shape exactly, so it is the machine-readable result the Week 2 eval gate
 * consumes for the `citation_present` rubric category.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation;

final readonly class CitationValidationReport
{
    /**
     * @param list<ClaimCitationResult> $failures Only the claims that failed
     *                                             validation, in the order
     *                                             they were checked. Empty
     *                                             when $citationPresent is
     *                                             true.
     */
    public function __construct(
        public bool $citationPresent,
        public array $failures,
    ) {
    }

    /**
     * @return array{
     *     citation_present: bool,
     *     failures: list<array{claim: string, missing_fields: list<string>}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'citation_present' => $this->citationPresent,
            'failures' => array_map(
                static fn (ClaimCitationResult $failure): array => $failure->toFailureArray(),
                $this->failures,
            ),
        ];
    }
}
