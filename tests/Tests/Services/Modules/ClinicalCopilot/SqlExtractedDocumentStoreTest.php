<?php

/**
 * DB-backed tests for SqlExtractedDocumentStore -- AgentForge2 Core
 * Requirement #1's persistence layer, in isolation from the extraction
 * call and document storage (DocumentIngestionPipelineTest covers the
 * end-to-end wiring).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/require_module.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ClinicalCopilotFixtureManager.php';

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\SqlExtractedDocumentStore;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqlExtractedDocumentStoreTest extends TestCase
{
    private ClinicalCopilotFixtureManager $fixtures;
    private SqlExtractedDocumentStore $store;

    /** @var list<int> */
    private array $installedPids = [];

    /** @var list<int> */
    private array $extractionIds = [];

    protected function setUp(): void
    {
        $this->fixtures = new ClinicalCopilotFixtureManager();
        $this->store = new SqlExtractedDocumentStore();
    }

    protected function tearDown(): void
    {
        foreach ($this->extractionIds as $id) {
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `clinical_copilot_extracted_document` WHERE `id` = ?',
                [$id],
            );
        }
        $this->fixtures->removeFixtures($this->installedPids);
    }

    #[Test]
    public function findForUnknownIdReturnsNull(): void
    {
        self::assertNull($this->store->find(999999999));
    }

    #[Test]
    public function saveThenFindRoundTripsFields(): void
    {
        $pid = $this->installPrimaryPatient();
        $fieldsJson = json_encode(
            ['doc_type' => 'lab_pdf', 'fields' => ['test_name' => 'HbA1c']],
            JSON_THROW_ON_ERROR,
        );

        $id = $this->trackExtraction($this->store->save($pid, SchemaDocType::LabPdf, $fieldsJson));

        $record = $this->store->find($id);
        self::assertNotNull($record);
        self::assertSame($pid, $record->patientId);
        self::assertSame(SchemaDocType::LabPdf, $record->docType);
        self::assertSame($fieldsJson, $record->fieldsJson);
        self::assertNull($record->documentId);
    }

    #[Test]
    public function attachDocumentIdSetsTheDocumentLink(): void
    {
        $pid = $this->installPrimaryPatient();
        $id = $this->trackExtraction($this->store->save($pid, SchemaDocType::IntakeForm, '{}'));

        $this->store->attachDocumentId($id, 424242);

        $record = $this->store->find($id);
        self::assertNotNull($record);
        self::assertSame(424242, $record->documentId);
    }

    private function trackExtraction(int $extractionId): int
    {
        $this->extractionIds[] = $extractionId;

        return $extractionId;
    }

    private function installPrimaryPatient(): int
    {
        $pid = $this->fixtures->installPrimaryPatient();
        $this->installedPids[] = $pid;

        return $pid;
    }
}
