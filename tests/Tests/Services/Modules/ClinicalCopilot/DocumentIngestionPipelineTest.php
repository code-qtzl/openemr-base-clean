<?php

/**
 * End-to-end DB-backed tests for DocumentIngestionPipeline -- AgentForge2
 * Core Requirement #1's attach_and_extract, exercised with a scripted
 * Anthropic response (no real API call) against the real dev database:
 * confirms a schema-valid extraction actually stores both a `documents`
 * row and a `clinical_copilot_extracted_document` row linked to each
 * other, and that a schema-invalid extraction persists neither.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/require_module.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ClinicalCopilotFixtureManager.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/FakeAnthropicTransporter.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ScriptedAnthropicClientFactory.php';

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentExtractionService;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentIngestionPipeline;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentPayload;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\SqlExtractedDocumentStore;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ScriptedAnthropicClientFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentIngestionPipelineTest extends TestCase
{
    private const VALID_LAB_FIELDS = [
        'test_name' => 'HbA1c',
        'value' => '7.2',
        'unit' => '%',
        'reference_range' => '4.0-5.6',
        'collection_date' => '2026-01-15',
        'abnormal_flag' => true,
        'source_citation' => [
            'source_type' => 'lab_pdf',
            'source_id' => 'lab-001',
            'page_or_section' => 'page_1',
            'field_or_chunk_id' => 'hba1c',
            'quote_or_value' => '7.2%',
        ],
    ];

    private ClinicalCopilotFixtureManager $fixtures;

    /** @var list<int> */
    private array $installedPids = [];

    /** @var list<int> */
    private array $extractionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    protected function setUp(): void
    {
        $this->fixtures = new ClinicalCopilotFixtureManager();
    }

    protected function tearDown(): void
    {
        foreach ($this->documentIds as $id) {
            QueryUtils::sqlStatementThrowException('DELETE FROM `documents` WHERE `id` = ?', [$id]);
        }
        foreach ($this->extractionIds as $id) {
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `clinical_copilot_extracted_document` WHERE `id` = ?',
                [$id],
            );
        }
        $this->fixtures->removeFixtures($this->installedPids);
    }

    #[Test]
    public function schemaValidExtractionPersistsBothDocumentAndExtractionRowsLinkedTogether(): void
    {
        $pid = $this->installPrimaryPatient();
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => self::VALID_LAB_FIELDS,
        ], JSON_THROW_ON_ERROR));
        $pipeline = new DocumentIngestionPipeline(new DocumentExtractionService($factory));
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = $pipeline->ingest($pid, 'corr-pipeline-1', $payload, SchemaDocType::LabPdf);

        self::assertTrue($result->success);
        self::assertNotNull($result->extractionId);
        self::assertNotNull($result->documentId);
        $this->extractionIds[] = $result->extractionId;
        $this->documentIds[] = $result->documentId;

        $documentRow = QueryUtils::querySingleRow(
            'SELECT `id`, `foreign_id`, `name` FROM `documents` WHERE `id` = ?',
            [$result->documentId],
        );
        self::assertIsArray($documentRow);
        self::assertIsNumeric($documentRow['foreign_id']);
        self::assertSame($pid, (int) $documentRow['foreign_id']);
        self::assertSame('lab.pdf', $documentRow['name']);

        $extractionRow = (new SqlExtractedDocumentStore())->find($result->extractionId);
        self::assertNotNull($extractionRow);
        self::assertSame($pid, $extractionRow->patientId);
        self::assertSame($result->documentId, $extractionRow->documentId);
        self::assertSame(SchemaDocType::LabPdf, $extractionRow->docType);
    }

    #[Test]
    public function schemaInvalidExtractionPersistsNeitherDocumentNorExtractionRow(): void
    {
        $pid = $this->installPrimaryPatient();
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => ['test_name' => 'HbA1c'],
        ], JSON_THROW_ON_ERROR));
        $pipeline = new DocumentIngestionPipeline(new DocumentExtractionService($factory));
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = $pipeline->ingest($pid, 'corr-pipeline-2', $payload, SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertNull($result->extractionId);
        self::assertNull($result->documentId);

        $countRow = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `clinical_copilot_extracted_document` WHERE `pid` = ?',
            [$pid],
        );
        self::assertIsArray($countRow);
        self::assertIsNumeric($countRow['c']);
        self::assertSame(0, (int) $countRow['c']);
    }

    private function installPrimaryPatient(): int
    {
        $pid = $this->fixtures->installPrimaryPatient();
        $this->installedPids[] = $pid;

        return $pid;
    }
}
