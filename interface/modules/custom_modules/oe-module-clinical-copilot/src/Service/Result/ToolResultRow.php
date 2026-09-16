<?php

/**
 * One row handed back to the model as part of a tool result.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

interface ToolResultRow
{
    /** @return array<string, string|null> */
    public function toArray(): array;
}
