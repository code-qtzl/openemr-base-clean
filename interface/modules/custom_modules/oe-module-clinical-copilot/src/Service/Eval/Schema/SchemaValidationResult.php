<?php

/**
 * schema_valid outcome for one extracted document. `toArray()` matches
 * SKILL.md's "Eval Output" / "Failure Reporting" JSON shape exactly, so it
 * is the machine-readable result the Week 2 eval gate consumes for the
 * `schema_valid` rubric category.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema;

final readonly class SchemaValidationResult
{
    /**
     * @param list<SchemaFinding> $findings Empty when $schemaValid is true.
     */
    public function __construct(
        public bool $schemaValid,
        public array $findings,
    ) {
    }

    /**
     * @return array{
     *     schema_valid: bool,
     *     failures: list<array{field: string, reason: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_valid' => $this->schemaValid,
            'failures' => array_map(
                static fn (SchemaFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
