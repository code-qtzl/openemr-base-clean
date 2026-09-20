<?php

/**
 * One active-problem-list row.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

final readonly class ActiveProblemRow implements ToolResultRow
{
    public function __construct(
        public ?string $title,
        public ?string $diagnosis,
        public ?string $onsetDate,
        public ?string $resolvedDate,
        public ?string $outcome,
        /**
         * Set by ActiveProblemStalenessPolicy when this row is unresolved
         * (no resolved_date) and old enough that `activity = 1` alone is
         * not good evidence the condition is still relevant -- see
         * PUNCH_LIST_2.md Item 5. Null when the row needs no such caveat.
         */
        public ?string $staleWarning = null,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'diagnosis' => $this->diagnosis,
            'onset_date' => $this->onsetDate,
            'resolved_date' => $this->resolvedDate,
            'outcome' => $this->outcome,
            'stale_warning' => $this->staleWarning,
        ];
    }
}
