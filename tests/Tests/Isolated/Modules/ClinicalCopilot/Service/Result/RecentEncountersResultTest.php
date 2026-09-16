<?php

/**
 * Isolated RecentEncountersResult Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Result;

use OpenEMR\Modules\ClinicalCopilot\Service\Result\RecentEncounterRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\RecentEncountersResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ToolResultRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/AbstractToolResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/RecentEncounterRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/RecentEncountersResult.php';

class RecentEncountersResultTest extends TestCase
{
    public function testOkWrapsRowsAndReportsCount(): void
    {
        $rows = [
            new RecentEncounterRow('2026-02-01', 'Follow-up', 'Office Visit'),
        ];

        $result = RecentEncountersResult::ok($rows);

        self::assertTrue($result->ok);
        self::assertSame(1, $result->count());
        self::assertSame([
            'ok' => true,
            'count' => 1,
            'rows' => [
                [
                    'encounter_date' => '2026-02-01',
                    'reason' => 'Follow-up',
                    'encounter_type_description' => 'Office Visit',
                ],
            ],
            'error' => null,
        ], $result->toArray());
    }

    public function testFailedCarriesErrorAndNoRows(): void
    {
        $result = RecentEncountersResult::failed('Could not retrieve that part of the chart.');

        self::assertFalse($result->ok);
        self::assertSame(0, $result->count());
        self::assertSame('Could not retrieve that part of the chart.', $result->toArray()['error']);
    }
}
