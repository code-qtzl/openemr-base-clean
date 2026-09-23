<?php

/**
 * One safe_refusal violation: the field under test, the specific claim that
 * caused it (null for the over-refusal direction, where the problem is the
 * absence of a grounded claim rather than a bad one), and why.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\SafeRefusal;

final readonly class SafeRefusalFinding
{
    public function __construct(
        public string $field,
        public ?string $claim,
        public string $reason,
    ) {
    }

    /**
     * @return array{field: string, claim: ?string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'claim' => $this->claim,
            'reason' => $this->reason,
        ];
    }
}
