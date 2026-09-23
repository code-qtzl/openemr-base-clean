<?php

/**
 * One factual_consistent violation: the claim, the value it asserted, the
 * value its own citation actually carries, and why. There is only one
 * failure mode this validator produces -- a mismatch -- so `reason` is a
 * fixed string rather than a category, unlike SchemaFinding's several
 * distinct shapes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\FactualConsistency;

final readonly class FactualConsistencyFinding
{
    public function __construct(
        public string $claim,
        public string $assertedValue,
        public string $quoteOrValue,
        public string $reason,
    ) {
    }

    /**
     * @return array{claim: string, asserted_value: string, quote_or_value: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'claim' => $this->claim,
            'asserted_value' => $this->assertedValue,
            'quote_or_value' => $this->quoteOrValue,
            'reason' => $this->reason,
        ];
    }
}
