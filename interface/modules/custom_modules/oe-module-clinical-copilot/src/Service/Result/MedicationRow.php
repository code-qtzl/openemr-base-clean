<?php

/**
 * One prescription row.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

final readonly class MedicationRow implements ToolResultRow
{
    public function __construct(
        public ?string $drug,
        public ?string $dosage,
        public ?string $form,
        public ?string $interval,
        public ?string $route,
        public ?string $quantity,
        public ?string $startDate,
        public ?string $endDate,
        /**
         * Set by MedicationStalenessPolicy when this row is open-ended
         * (no end_date) and old enough that `active` alone is not good
         * evidence the patient is still taking it -- see PUNCH_LIST.md
         * 1.4(a). Null when the row needs no such caveat.
         */
        public ?string $staleWarning = null,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'drug' => $this->drug,
            'dosage' => $this->dosage,
            'form' => $this->form,
            'interval' => $this->interval,
            'route' => $this->route,
            'quantity' => $this->quantity,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'stale_warning' => $this->staleWarning,
        ];
    }
}
