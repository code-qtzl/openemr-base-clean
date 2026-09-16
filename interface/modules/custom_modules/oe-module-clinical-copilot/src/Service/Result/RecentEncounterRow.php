<?php

/**
 * One encounter row.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

final readonly class RecentEncounterRow implements ToolResultRow
{
    public function __construct(
        public ?string $encounterDate,
        public ?string $reason,
        public ?string $encounterTypeDescription,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'encounter_date' => $this->encounterDate,
            'reason' => $this->reason,
            'encounter_type_description' => $this->encounterTypeDescription,
        ];
    }
}
