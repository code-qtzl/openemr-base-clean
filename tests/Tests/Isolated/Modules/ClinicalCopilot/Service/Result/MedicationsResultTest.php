<?php

/**
 * Isolated MedicationsResult Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Result;

use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationsResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ToolResultRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/AbstractToolResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/MedicationRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/MedicationsResult.php';

class MedicationsResultTest extends TestCase
{
    public function testOkWrapsRowsAndReportsCount(): void
    {
        $rows = [
            new MedicationRow('Metformin', '500mg', 'tablet', 'BID', 'oral', '60', '2024-01-10', null),
        ];

        $result = MedicationsResult::ok($rows);

        self::assertTrue($result->ok);
        self::assertSame(1, $result->count());
        self::assertSame([
            'ok' => true,
            'count' => 1,
            'rows' => [
                [
                    'drug' => 'Metformin',
                    'dosage' => '500mg',
                    'form' => 'tablet',
                    'interval' => 'BID',
                    'route' => 'oral',
                    'quantity' => '60',
                    'start_date' => '2024-01-10',
                    'end_date' => null,
                    'stale_warning' => null,
                ],
            ],
            'error' => null,
        ], $result->toArray());
    }

    public function testStaleWarningIsCarriedThroughToArray(): void
    {
        // PUNCH_LIST.md 1.4(a): a decades-old, open-ended "active" row must
        // surface its staleness warning to the model, not just the raw dates.
        $rows = [
            new MedicationRow(
                'Aspirin',
                '81mg',
                'tablet',
                'QD',
                'oral',
                '30',
                '1948-06-01',
                null,
                'Started 78 year(s) ago with no end date recorded -- confirm this is still current before relying on it.',
            ),
        ];

        $result = MedicationsResult::ok($rows);

        self::assertSame(
            'Started 78 year(s) ago with no end date recorded -- confirm this is still current before relying on it.',
            $result->toArray()['rows'][0]['stale_warning'],
        );
    }

    public function testEmptyMedicationListIsDistinctFromFailure(): void
    {
        // A patient with genuinely no active prescriptions must not look like
        // a failed tool call to the model -- see the verification-layer plan
        // (PUNCH_LIST.md 1.4), which relies on this distinction to guard
        // against a "the patient is on medication X" claim with zero rows.
        $result = MedicationsResult::ok([]);

        self::assertTrue($result->ok);
        self::assertNull($result->error);
        self::assertSame(0, $result->count());
    }

    public function testFailedCarriesErrorAndNoRows(): void
    {
        $result = MedicationsResult::failed('Could not retrieve that part of the chart.');

        self::assertFalse($result->ok);
        self::assertSame(0, $result->count());
        self::assertSame('Could not retrieve that part of the chart.', $result->toArray()['error']);
    }
}
