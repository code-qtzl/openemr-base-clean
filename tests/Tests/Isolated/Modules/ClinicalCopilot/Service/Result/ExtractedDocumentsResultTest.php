<?php

/**
 * Isolated ExtractedDocumentsResult Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Result;

use OpenEMR\Modules\ClinicalCopilot\Service\Result\ExtractedDocumentRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ExtractedDocumentsResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ToolResultRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/AbstractToolResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ExtractedDocumentRow.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Result/ExtractedDocumentsResult.php';

class ExtractedDocumentsResultTest extends TestCase
{
    public function testOkWrapsRowsAndReportsCount(): void
    {
        $rows = [
            new ExtractedDocumentRow(
                'lab_pdf',
                '2026-01-15 10:00:00',
                '{"doc_type":"lab_pdf","fields":{"test_name":"HbA1c","value":"7.2"}}',
            ),
        ];

        $result = ExtractedDocumentsResult::ok($rows);

        self::assertTrue($result->ok);
        self::assertSame(1, $result->count());
        self::assertSame([
            'ok' => true,
            'count' => 1,
            'rows' => [
                [
                    'doc_type' => 'lab_pdf',
                    'extracted_at' => '2026-01-15 10:00:00',
                    'fields_json' => '{"doc_type":"lab_pdf","fields":{"test_name":"HbA1c","value":"7.2"}}',
                ],
            ],
            'error' => null,
        ], $result->toArray());
    }

    /**
     * Failure mode guarded against: a patient with no uploaded documents
     * must not look like a failed tool call to the model -- same distinction
     * PUNCH_LIST.md 1.4 relies on for the other chart-reading tools.
     */
    public function testEmptyDocumentListIsDistinctFromFailure(): void
    {
        $result = ExtractedDocumentsResult::ok([]);

        self::assertTrue($result->ok);
        self::assertNull($result->error);
        self::assertSame(0, $result->count());
    }

    public function testFailedCarriesErrorAndNoRows(): void
    {
        $result = ExtractedDocumentsResult::failed('Could not retrieve that part of the chart.');

        self::assertFalse($result->ok);
        self::assertSame(0, $result->count());
        self::assertSame('Could not retrieve that part of the chart.', $result->toArray()['error']);
    }
}
