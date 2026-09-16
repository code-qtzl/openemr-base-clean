<?php

/**
 * Result of the get_active_problems tool call.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

final readonly class ActiveProblemsResult extends AbstractToolResult
{
    /** @param list<ActiveProblemRow> $rows */
    public static function ok(array $rows): self
    {
        return new self(true, $rows);
    }

    public static function failed(string $error): self
    {
        return new self(false, [], $error);
    }
}
