<?php

/**
 * Citation-validation outcome for a single clinical claim.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation;

final readonly class ClaimCitationResult
{
    /**
     * @param list<string> $missingFields Required citation fields (per
     *                                    SKILL.md's contract) that were
     *                                    absent, non-string, or empty after
     *                                    trimming. Empty when
     *                                    $citationPresent is true.
     */
    public function __construct(
        public string $claim,
        public bool $citationPresent,
        public array $missingFields,
    ) {
    }

    /**
     * @return array{claim: string, missing_fields: list<string>}
     */
    public function toFailureArray(): array
    {
        return [
            'claim' => $this->claim,
            'missing_fields' => $this->missingFields,
        ];
    }
}
