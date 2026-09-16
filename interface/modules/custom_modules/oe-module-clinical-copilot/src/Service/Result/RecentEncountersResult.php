<?php

/**
 * Result of the get_recent_encounters tool call.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

final readonly class RecentEncountersResult extends AbstractToolResult
{
    /** @param list<RecentEncounterRow> $rows */
    public static function ok(array $rows): self
    {
        return new self(true, $rows);
    }

    public static function failed(string $error): self
    {
        return new self(false, [], $error);
    }
}
