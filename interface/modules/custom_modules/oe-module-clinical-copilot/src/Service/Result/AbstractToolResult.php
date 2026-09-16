<?php

/**
 * Shared envelope for one ChartContextTools call outcome.
 *
 * One concrete final subclass exists per tool result shape (A1cSeriesResult,
 * ActiveProblemsResult, MedicationsResult, RecentEncountersResult) so
 * ChartContextTools::call() can declare a closed union return type instead of
 * a bare array. What a tool call may hand back to the model is now enforced
 * by PHPStan, not by convention.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

abstract readonly class AbstractToolResult
{
    /** @param list<ToolResultRow> $rows */
    public function __construct(
        public bool $ok,
        public array $rows = [],
        public ?string $error = null,
    ) {
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * JSON-serializable shape sent back to the model as the tool_result.
     *
     * @return array{ok: bool, count: int, rows: list<array<string, string|null>>, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'count' => $this->count(),
            'rows' => array_map(static fn (ToolResultRow $row): array => $row->toArray(), $this->rows),
            'error' => $this->error,
        ];
    }
}
