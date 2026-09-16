<?php

/**
 * Isolated A1cSeriesResult Test
 *
 * Covers the ok()/failed() factories and the toArray() shape CopilotService
 * JSON-encodes and hands back to the model as a tool_result. This is the
 * boundary between typed chart data and what actually leaves the process.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Result;

use OpenEMR\Modules\ClinicalCopilot\Service\Result\A1cResultRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\A1cSeriesResult;
use PHPUnit\Framework\TestCase;

// The clinical-copilot module's classes are not registered in the root
// composer autoloader (the module is loaded by OpenEMR's runtime module
// system). Pull the files in directly so this isolated test can run without
// bootstrapping the full module loader.
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ToolResultRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/AbstractToolResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/A1cResultRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/A1cSeriesResult.php';

class A1cSeriesResultTest extends TestCase
{
    public function testOkWrapsRowsAndReportsCount(): void
    {
        $rows = [
            new A1cResultRow('2026-01-15', '7.1', '%', '4.0-5.6', 'high'),
            new A1cResultRow('2025-07-02', '6.8', '%', '4.0-5.6', 'high'),
        ];

        $result = A1cSeriesResult::ok($rows);

        self::assertTrue($result->ok);
        self::assertSame(2, $result->count());
        self::assertNull($result->error);
        self::assertSame([
            'ok' => true,
            'count' => 2,
            'rows' => [
                [
                    'result_date' => '2026-01-15',
                    'value' => '7.1',
                    'units' => '%',
                    'reference_range' => '4.0-5.6',
                    'abnormal_flag' => 'high',
                ],
                [
                    'result_date' => '2025-07-02',
                    'value' => '6.8',
                    'units' => '%',
                    'reference_range' => '4.0-5.6',
                    'abnormal_flag' => 'high',
                ],
            ],
            'error' => null,
        ], $result->toArray());
    }

    public function testOkWithNoRowsIsNotConflatedWithFailure(): void
    {
        $result = A1cSeriesResult::ok([]);

        self::assertTrue($result->ok);
        self::assertSame(0, $result->count());
        self::assertSame([], $result->toArray()['rows']);
    }

    public function testFailedCarriesErrorAndNoRows(): void
    {
        $result = A1cSeriesResult::failed('Could not retrieve that part of the chart.');

        self::assertFalse($result->ok);
        self::assertSame(0, $result->count());
        self::assertSame([
            'ok' => false,
            'count' => 0,
            'rows' => [],
            'error' => 'Could not retrieve that part of the chart.',
        ], $result->toArray());
    }
}
