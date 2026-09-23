<?php

/**
 * DB-backed tests for IntakeExtractorWorker -- confirms consult() returns
 * real previously-extracted document data, against a real seeded row.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/require_module.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ClinicalCopilotFixtureManager.php';

use OpenEMR\Modules\ClinicalCopilot\Service\ChartContextTools;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ExtractedDocumentRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ExtractedDocumentsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Supervisor\IntakeExtractorWorker;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntakeExtractorWorkerTest extends TestCase
{
    private ClinicalCopilotFixtureManager $fixtures;
    private int $pid;

    protected function setUp(): void
    {
        $this->fixtures = new ClinicalCopilotFixtureManager();
        $this->pid = $this->fixtures->installPrimaryPatient();
    }

    protected function tearDown(): void
    {
        $this->fixtures->removeFixtures([$this->pid]);
    }

    #[Test]
    public function consultReturnsOnlyItsOwnedTool(): void
    {
        $results = (new IntakeExtractorWorker($this->tools()))->consult();

        self::assertSame(['get_extracted_documents'], array_keys($results));
    }

    #[Test]
    public function consultReflectsARealPreviouslyExtractedDocument(): void
    {
        $this->fixtures->seedExtractedDocument($this->pid, 'lab_pdf', [
            'test_name' => 'HbA1c',
            'value' => '7.2',
        ]);

        $results = (new IntakeExtractorWorker($this->tools()))->consult();

        $documents = $results['get_extracted_documents'];
        self::assertInstanceOf(ExtractedDocumentsResult::class, $documents);
        self::assertTrue($documents->ok);
        self::assertCount(1, $documents->rows);

        $row = $documents->rows[0];
        self::assertInstanceOf(ExtractedDocumentRow::class, $row);
        self::assertStringContainsString('HbA1c', $row->fieldsJson ?? '');
    }

    #[Test]
    public function consultWithNoUploadedDocumentsReturnsOkWithNoRowsNotAFailure(): void
    {
        $documents = (new IntakeExtractorWorker($this->tools()))->consult()['get_extracted_documents'];

        self::assertTrue($documents->ok);
        self::assertSame(0, $documents->count());
    }

    private function tools(): ChartContextTools
    {
        return new ChartContextTools($this->pid, 'admin', 'admin', 'test-correlation');
    }
}
