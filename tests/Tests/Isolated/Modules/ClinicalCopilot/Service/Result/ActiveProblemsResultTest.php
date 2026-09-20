<?php

/**
 * Isolated ActiveProblemsResult Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Result;

use OpenEMR\Modules\ClinicalCopilot\Service\Result\ActiveProblemRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ActiveProblemsResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ToolResultRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/AbstractToolResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ActiveProblemRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ActiveProblemsResult.php';

class ActiveProblemsResultTest extends TestCase
{
    public function testOkWrapsRowsAndReportsCount(): void
    {
        $rows = [
            new ActiveProblemRow('Type 2 diabetes mellitus', 'E11.9', '2019-03-01', null, 'improving'),
        ];

        $result = ActiveProblemsResult::ok($rows);

        self::assertTrue($result->ok);
        self::assertSame(1, $result->count());
        self::assertSame([
            'ok' => true,
            'count' => 1,
            'rows' => [
                [
                    'title' => 'Type 2 diabetes mellitus',
                    'diagnosis' => 'E11.9',
                    'onset_date' => '2019-03-01',
                    'resolved_date' => null,
                    'outcome' => 'improving',
                    'stale_warning' => null,
                ],
            ],
            'error' => null,
        ], $result->toArray());
    }

    public function testStaleWarningIsCarriedThroughToArray(): void
    {
        // PUNCH_LIST_2.md Item 5: a decades-old, unresolved "active" problem
        // must surface its staleness warning to the model, not just the raw
        // dates -- mirrors MedicationsResult's own staleness handling.
        $rows = [
            new ActiveProblemRow(
                'Essential hypertension',
                'I10',
                '1946-10-07',
                null,
                'unresolved',
                'Onset recorded 79 year(s) ago with no resolution date -- confirm this is still relevant '
                    . 'before relying on it.',
            ),
        ];

        $result = ActiveProblemsResult::ok($rows);

        self::assertSame(
            'Onset recorded 79 year(s) ago with no resolution date -- confirm this is still relevant '
                . 'before relying on it.',
            $result->toArray()['rows'][0]['stale_warning'],
        );
    }

    public function testFailedCarriesErrorAndNoRows(): void
    {
        $result = ActiveProblemsResult::failed('Could not retrieve that part of the chart.');

        self::assertFalse($result->ok);
        self::assertSame(0, $result->count());
        self::assertSame('Could not retrieve that part of the chart.', $result->toArray()['error']);
    }
}
