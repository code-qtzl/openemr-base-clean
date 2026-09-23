<?php

/**
 * One schema violation: which field, and why -- missing (key absent or
 * null), wrong_shape (present but not the required type), or empty
 * (present as the right shape but empty when non-empty was required).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema;

final readonly class SchemaFinding
{
    public function __construct(
        public string $field,
        public string $reason,
    ) {
    }

    /**
     * @return array{field: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'reason' => $this->reason,
        ];
    }
}
