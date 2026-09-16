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
        ];
    }
}
