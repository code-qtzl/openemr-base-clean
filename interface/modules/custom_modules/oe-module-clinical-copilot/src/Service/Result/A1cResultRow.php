<?php

/**
 * One Hemoglobin A1c result row.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

final readonly class A1cResultRow implements ToolResultRow
{
    public function __construct(
        public ?string $resultDate,
        public ?string $value,
        public ?string $units,
        public ?string $referenceRange,
        public ?string $abnormalFlag,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'result_date' => $this->resultDate,
            'value' => $this->value,
            'units' => $this->units,
            'reference_range' => $this->referenceRange,
            'abnormal_flag' => $this->abnormalFlag,
        ];
    }
}
