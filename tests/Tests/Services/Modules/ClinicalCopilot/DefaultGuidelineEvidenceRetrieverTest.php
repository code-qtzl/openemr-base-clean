<?php

/**
 * DB-backed integration test for DefaultGuidelineEvidenceRetriever: exercises
 * the real SQL (brute-force cosine similarity over real rows, a real
 * MariaDB FULLTEXT query, real Reciprocal Rank Fusion) against a real
 * clinical_copilot_guideline_chunk table, with only the Voyage HTTP calls
 * faked via ScriptedVoyageClientFactory -- no network access, no real
 * Voyage API key.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/require_module.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/FakeVoyageTransporter.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ScriptedVoyageClientFactory.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ClinicalCopilotFixtureManager.php';

use OpenEMR\Core\OEEnvBag;
use OpenEMR\Modules\ClinicalCopilot\Service\Evidence\DefaultGuidelineEvidenceRetriever;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\GuidelineEvidenceRow;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ScriptedVoyageClientFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefaultGuidelineEvidenceRetrieverTest extends TestCase
{
    private const RELEVANT_SOURCE = 'test-metformin-label';
    private const IRRELEVANT_SOURCE = 'test-unrelated-label';

    private ClinicalCopilotFixtureManager $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = new ClinicalCopilotFixtureManager();
        // OEEnvBag is a singleton that merges $_SERVER/$_ENV/getenv() once
        // at first access (see its own docblock) -- putenv() after that
        // point has no effect, since an earlier test in this same process
        // has almost certainly already triggered CopilotService::apiKey()
        // and frozen the singleton. Mutate the already-constructed
        // ParameterBag directly instead. Not a real key -- retrieve() only
        // checks it is non-empty; the actual HTTP call is faked via
        // ScriptedVoyageClientFactory.
        OEEnvBag::getInstance()->set('OPENEMR__VOYAGE_API_KEY', 'test-fake-voyage-key');
    }

    protected function tearDown(): void
    {
        $this->fixtures->removeGuidelineChunks([self::RELEVANT_SOURCE, self::IRRELEVANT_SOURCE]);
        OEEnvBag::getInstance()->remove('OPENEMR__VOYAGE_API_KEY');
    }

    /**
     * Proves the full hybrid path end to end: a query whose embedding is
     * engineered to point directly at the relevant chunk's vector (dense
     * side) and whose text keyword-matches only the relevant chunk (sparse
     * side) -- both rankings agree, so the relevant chunk must come back
     * first after fusion and rerank, and the irrelevant chunk must not
     * appear in the returned evidence at all (the fake rerank response only
     * scores the relevant one).
     */
    #[Test]
    public function hybridRetrievalReturnsTheSemanticallyAndLexicallyRelevantChunkFirst(): void
    {
        $this->fixtures->seedGuidelineChunk(
            self::RELEVANT_SOURCE,
            'Metformin Hydrochloride Tablets -- FDA Label',
            'contraindications',
            0,
            'Metformin is contraindicated in patients with severe renal impairment.',
            [1.0, 0.0, 0.0],
        );
        $this->fixtures->seedGuidelineChunk(
            self::IRRELEVANT_SOURCE,
            'Some Unrelated Drug -- FDA Label',
            'dosage_and_administration',
            0,
            'Administer once daily with a glass of water, unrelated to renal function.',
            [0.0, 1.0, 0.0],
        );

        $factory = new ScriptedVoyageClientFactory(
            embedResponses: [
                ['data' => [['index' => 0, 'embedding' => [1.0, 0.0, 0.0]]]],
            ],
            rerankResponses: [
                ['data' => [['index' => 0, 'relevance_score' => 0.93]]],
            ],
        );

        $retriever = new DefaultGuidelineEvidenceRetriever($factory);
        $result = $retriever->retrieve('metformin renal impairment', 'test-correlation-retriever');

        self::assertTrue($result->ok);
        self::assertCount(1, $result->rows);

        $row = $result->rows[0];
        self::assertInstanceOf(GuidelineEvidenceRow::class, $row);
        self::assertSame(self::RELEVANT_SOURCE, $row->sourceId);
        self::assertSame('contraindications', $row->section);
        self::assertStringContainsString('renal impairment', (string) $row->chunkText);
        self::assertSame('0.9300', $row->rerankScore);
    }

    #[Test]
    public function emptyCorpusReturnsAnEmptyOkResultWithoutCallingVoyage(): void
    {
        $factory = new ScriptedVoyageClientFactory(embedResponses: [], rerankResponses: []);

        $retriever = new DefaultGuidelineEvidenceRetriever($factory);
        $result = $retriever->retrieve('anything', 'test-correlation-empty-corpus');

        self::assertTrue($result->ok);
        self::assertSame([], $result->rows);
    }

    #[Test]
    public function missingApiKeyThrows(): void
    {
        OEEnvBag::getInstance()->remove('OPENEMR__VOYAGE_API_KEY');

        $this->expectException(\RuntimeException::class);

        (new DefaultGuidelineEvidenceRetriever())->retrieve('anything', 'test-correlation-no-key');
    }
}
