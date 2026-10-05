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
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentAttachmentService;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentExtractionService;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentIngestionPipeline;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentPayload;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\SqlExtractedDocumentStore;
use OpenEMR\Services\DocumentService;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ScriptedAnthropicClientFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        self::assertCount(1, $result->extractionIds);
        self::assertNotNull($result->documentId);
        $this->extractionIds = [...$this->extractionIds, ...$result->extractionIds];
        $this->documentIds[] = $result->documentId;

        $documentRow = QueryUtils::querySingleRow(
            'SELECT `id`, `foreign_id`, `name` FROM `documents` WHERE `id` = ?',
            [$result->documentId],
        );
        self::assertIsArray($documentRow);
        self::assertIsNumeric($documentRow['foreign_id']);
        self::assertSame($pid, (int) $documentRow['foreign_id']);
        self::assertSame('lab.pdf', $documentRow['name']);

        $extractionRow = (new SqlExtractedDocumentStore())->find($result->extractionIds[0]);
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
        self::assertSame([], $result->extractionIds);
        self::assertNull($result->documentId);

        $countRow = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `clinical_copilot_extracted_document` WHERE `pid` = ?',
            [$pid],
        );
        self::assertIsArray($countRow);
        self::assertIsNumeric($countRow['c']);
        self::assertSame(0, (int) $countRow['c']);
    }

    /**
     * Failure mode guarded against: before this test existed, a document-
     * storage failure after save() had already inserted the extraction row
     * left that row behind with a null document_id -- an orphaned,
     * findable-but-unrepaired partial state. Forces that failure
     * deterministically (a DocumentService double whose isValidPath()
     * always returns false, so DocumentAttachmentService::store() throws
     * before ever touching \Document::createDocument()) and proves the
     * pipeline's compensating delete leaves nothing behind at all: the
     * exception still propagates, and the extraction row is gone.
     */
    #[Test]
    public function documentStorageFailureRollsBackTheOrphanedExtractionRow(): void
    {
        $pid = $this->installPrimaryPatient();
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => self::VALID_LAB_FIELDS,
        ], JSON_THROW_ON_ERROR));

        $alwaysInvalidPathDocumentService = new class () extends DocumentService {
            public function isValidPath(mixed $path): bool
            {
                return false;
            }
        };
        $pipeline = new DocumentIngestionPipeline(
            new DocumentExtractionService($factory),
            new SqlExtractedDocumentStore(),
            new DocumentAttachmentService($alwaysInvalidPathDocumentService),
        );
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        try {
            $pipeline->ingest($pid, 'corr-pipeline-rollback', $payload, SchemaDocType::LabPdf);
            self::fail('Expected DocumentAttachmentService::store() to throw.');
        } catch (RuntimeException) {
            // Expected -- the invalid category path forces this.
        }

        $countRow = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `clinical_copilot_extracted_document` WHERE `pid` = ?',
            [$pid],
        );
        self::assertIsArray($countRow);
        self::assertIsNumeric($countRow['c']);
        self::assertSame(0, (int) $countRow['c'], 'a failed ingest must not leave an orphaned extraction row behind');

        $documentCountRow = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `documents` WHERE `foreign_id` = ?',
            [$pid],
        );
        self::assertIsArray($documentCountRow);
        self::assertIsNumeric($documentCountRow['c']);
        self::assertSame(0, (int) $documentCountRow['c']);
    }

    /**
     * A lab panel is stored as one extraction row per test result, all linked
     * to the single stored document, each with that document's id stamped into
     * its own source_citation (so each result's source chip opens the PDF).
     */
    #[Test]
    public function labPanelPersistsOneRowPerResultAllLinkedToTheOneDocument(): void
    {
        $pid = $this->installPrimaryPatient();
        $pipeline = new DocumentIngestionPipeline(new DocumentExtractionService($this->panelFactory(3)));
        $payload = DocumentPayload::fromBytes('panel.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = $pipeline->ingest($pid, 'corr-pipeline-panel', $payload, SchemaDocType::LabPdf);

        self::assertTrue($result->success);
        self::assertCount(3, $result->extractionIds);
        self::assertCount(3, $result->documents);
        self::assertNotNull($result->documentId);
        $this->extractionIds = [...$this->extractionIds, ...$result->extractionIds];
        $this->documentIds[] = $result->documentId;

        $store = new SqlExtractedDocumentStore();
        foreach ($result->extractionIds as $index => $extractionId) {
            $row = $store->find($extractionId);
            self::assertNotNull($row);
            self::assertSame($result->documentId, $row->documentId);

            $stored = json_decode($row->fieldsJson, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($stored);
            self::assertIsArray($stored['fields']);
            self::assertSame('test-' . $index, $stored['fields']['test_name']);
            self::assertIsArray($stored['fields']['source_citation']);
            self::assertSame((string) $result->documentId, $stored['fields']['source_citation']['document_id']);
        }

        $documentCount = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `documents` WHERE `foreign_id` = ?',
            [$pid],
        );
        self::assertIsArray($documentCount);
        self::assertIsNumeric($documentCount['c']);
        self::assertSame(1, (int) $documentCount['c'], 'one stored document regardless of how many results it yielded');
    }

    /** All-or-nothing: one invalid result in a panel stores no rows and no document. */
    #[Test]
    public function labPanelWithOneInvalidResultPersistsNothing(): void
    {
        $pid = $this->installPrimaryPatient();
        $results = $this->panelResults(3);
        unset($results[1]['unit']);
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'lab_pdf', 'results' => $results],
            JSON_THROW_ON_ERROR,
        ));
        $pipeline = new DocumentIngestionPipeline(new DocumentExtractionService($factory));
        $payload = DocumentPayload::fromBytes('panel.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = $pipeline->ingest($pid, 'corr-pipeline-panel-invalid', $payload, SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertSame([], $result->extractionIds);
        self::assertNotNull($result->extractionFailure);
        self::assertNotNull($result->extractionFailure->validation);
        self::assertSame(
            ['results[1].unit'],
            array_map(static fn ($finding): string => $finding->field, $result->extractionFailure->validation->findings),
        );

        $countRow = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `clinical_copilot_extracted_document` WHERE `pid` = ?',
            [$pid],
        );
        self::assertIsArray($countRow);
        self::assertIsNumeric($countRow['c']);
        self::assertSame(0, (int) $countRow['c']);
    }

    /** A document-storage failure must roll back every row of a multi-result panel, not just one. */
    #[Test]
    public function documentStorageFailureRollsBackEveryRowOfAPanel(): void
    {
        $pid = $this->installPrimaryPatient();
        $alwaysInvalidPathDocumentService = new class () extends DocumentService {
            public function isValidPath(mixed $path): bool
            {
                return false;
            }
        };
        $pipeline = new DocumentIngestionPipeline(
            new DocumentExtractionService($this->panelFactory(3)),
            new SqlExtractedDocumentStore(),
            new DocumentAttachmentService($alwaysInvalidPathDocumentService),
        );
        $payload = DocumentPayload::fromBytes('panel.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        try {
            $pipeline->ingest($pid, 'corr-pipeline-panel-rollback', $payload, SchemaDocType::LabPdf);
            self::fail('Expected DocumentAttachmentService::store() to throw.');
        } catch (RuntimeException) {
            // Expected -- the invalid category path forces this.
        }

        $countRow = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `clinical_copilot_extracted_document` WHERE `pid` = ?',
            [$pid],
        );
        self::assertIsArray($countRow);
        self::assertIsNumeric($countRow['c']);
        self::assertSame(0, (int) $countRow['c'], 'all rows of the panel must be rolled back');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function panelResults(int $count): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = [...self::VALID_LAB_FIELDS, 'test_name' => 'test-' . $i];
        }

        return $results;
    }

    private function panelFactory(int $count): ScriptedAnthropicClientFactory
    {
        return (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'lab_pdf', 'results' => $this->panelResults($count)],
            JSON_THROW_ON_ERROR,
        ));
    }

    private function installPrimaryPatient(): int
    {
        $pid = $this->fixtures->installPrimaryPatient();
        $this->installedPids[] = $pid;

        return $pid;
    }
}
