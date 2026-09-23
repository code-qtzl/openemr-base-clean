<?php

/**
 * DB-backed tests for ChartQaWorker -- confirms consult() bundles real
 * results from all four owned tools, against real seeded data, the same
 * way ChartContextTools' own tools are already proven correct.
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
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ActiveProblemsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Supervisor\ChartQaWorker;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChartQaWorkerTest extends TestCase
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
    public function consultReturnsAllFourOwnedToolsKeyedByName(): void
    {
        $results = (new ChartQaWorker($this->tools()))->consult();

        self::assertSame(ChartQaWorker::OWNED_TOOLS, array_keys($results));
    }

    #[Test]
    public function consultReflectsRealSeededChartData(): void
    {
        $this->fixtures->seedActiveProblem($this->pid, 'Type 2 diabetes mellitus');
        $this->fixtures->seedMedication($this->pid, 'Metformin', '2024-01-10');

        $results = (new ChartQaWorker($this->tools()))->consult();

        $problems = $results['get_active_problems'];
        self::assertInstanceOf(ActiveProblemsResult::class, $problems);
        self::assertTrue($problems->ok);
        self::assertCount(1, $problems->rows);

        $medications = $results['get_medications'];
        self::assertInstanceOf(MedicationsResult::class, $medications);
        self::assertTrue($medications->ok);
        self::assertCount(1, $medications->rows);
    }

    #[Test]
    public function consultOnAnEmptyChartReturnsOkWithNoRowsNotAFailure(): void
    {
        $results = (new ChartQaWorker($this->tools()))->consult();

        foreach ($results as $result) {
            self::assertTrue($result->ok);
            self::assertSame(0, $result->count());
        }
    }

    private function tools(): ChartContextTools
    {
        return new ChartContextTools($this->pid, 'admin', 'admin', 'test-correlation');
    }
}
