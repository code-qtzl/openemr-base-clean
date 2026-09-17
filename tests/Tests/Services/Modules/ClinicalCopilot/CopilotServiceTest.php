<?php

/**
 * DB-backed integration tests for CopilotService::ask() and the
 * verification layer it drives -- PUNCH_LIST.md Tier 2.1.
 *
 * The Anthropic API is never called: CopilotService accepts an
 * AnthropicClientFactory (see PUNCH_LIST.md Tier 2's constructor-injection
 * seam), and ScriptedAnthropicClientFactory scripts the model's turns
 * through a fake PSR-18 transporter instead. ChartContextTools and the DB
 * it queries are real -- only the LLM call is faked.
 *
 * AI-Generated Code Notice: This file contains code generated with
 * assistance from Claude Code (Anthropic). The code has been reviewed
 * and tested by the contributor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/require_module.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/FakeAnthropicTransporter.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ScriptedAnthropicClientFactory.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ClinicalCopilotFixtureManager.php';

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\Service\ChartContextTools;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotService;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Verification\ResponseVerifier;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ScriptedAnthropicClientFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CopilotServiceTest extends TestCase
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

    /**
     * Failure mode guarded against: an empty chart (all four tools return
     * zero rows) must produce an honest "not enough information" answer,
     * never a fabricated finding. This is also the harness smoke test for
     * ScriptedAnthropicClientFactory/FakeAnthropicTransporter -- the
     * smallest possible scripted conversation (no tool calls at all).
     */
    #[Test]
    public function emptyChartProducesHonestInsufficientInformationReply(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())
            ->submitAnswer(['insufficient_information' => true, 'summary' => 'The chart has no data recorded.'])
            ->finalText();

        $service = new CopilotService($this->tools(), $factory);
        $result = $service->ask('Is this patient on any medications?', 'test-correlation-empty');

        self::assertTrue($result->verificationPassed);
        self::assertSame('The chart has no data recorded.', $result->reply);
    }

    /**
     * Failure mode guarded against: a model that answers with a clinical
     * claim but calls no supporting tool this turn (an empty chart gives it
     * nothing to cite truthfully) must be rejected to the safe fallback
     * rather than reaching the browser -- this is the "must not fabricate"
     * half of the empty-chart boundary case.
     */
    #[Test]
    public function emptyChartRejectsAClaimWithNoSupportingToolCall(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())
            ->submitAnswer([
                'insufficient_information' => false,
                'claims' => [['text' => 'Patient has diabetes.', 'source_tool' => 'get_active_problems']],
            ])
            ->finalText();

        $service = new CopilotService($this->tools(), $factory);
        $result = $service->ask('What conditions does this patient have?', 'test-correlation-fabricate');

        self::assertFalse($result->verificationPassed);
        self::assertSame(ResponseVerifier::FALLBACK_REPLY, $result->reply);
    }

    /**
     * Failure mode guarded against: PUNCH_LIST.md 1.3's core invariant --
     * every claim must cite a tool actually called this turn. The positive
     * case: a claim citing a tool that WAS called, with supporting data,
     * must pass through to the clinician rather than being rejected.
     */
    #[Test]
    public function claimCitingAToolActuallyCalledPasses(): void
    {
        $this->fixtures->seedActiveProblem($this->pid, 'Type 2 diabetes mellitus');

        $factory = (new ScriptedAnthropicClientFactory())
            ->toolUse('get_active_problems')
            ->submitAnswer([
                'insufficient_information' => false,
                'claims' => [['text' => 'Patient has Type 2 diabetes.', 'source_tool' => 'get_active_problems']],
            ])
            ->finalText();

        $service = new CopilotService($this->tools(), $factory);
        $result = $service->ask('What conditions does this patient have?', 'test-correlation-cited');

        self::assertTrue($result->verificationPassed);
        self::assertStringContainsString('Type 2 diabetes', $result->reply);
        self::assertContains('get_active_problems', $result->toolsUsed);
    }

    /**
     * Failure mode guarded against: the negative half of the citation
     * invariant -- a claim citing a tool never called this turn must be
     * rejected, even though the tool exists and could have supported a
     * similar claim if actually called.
     */
    #[Test]
    public function claimCitingAToolNeverCalledIsRejected(): void
    {
        $this->fixtures->seedActiveProblem($this->pid, 'Type 2 diabetes mellitus');

        $factory = (new ScriptedAnthropicClientFactory())
            ->toolUse('get_active_problems')
            ->submitAnswer([
                'insufficient_information' => false,
                'claims' => [['text' => 'Patient takes metformin.', 'source_tool' => 'get_medications']],
            ])
            ->finalText();

        $service = new CopilotService($this->tools(), $factory);
        $result = $service->ask('What is this patient taking?', 'test-correlation-uncited');

        self::assertFalse($result->verificationPassed);
        self::assertSame(ResponseVerifier::FALLBACK_REPLY, $result->reply);
    }

    /**
     * Failure mode guarded against (documented, permanent regression test
     * for a KNOWN MVP LIMITATION -- see ResponseVerifier's class docblock
     * and PUNCH_LIST.md 1.4(b)): the get_medications zero-row guard cannot
     * distinguish a truthful "no medications on file" claim from a
     * hallucinated one -- both cite get_medications with zero rows
     * returned, so both are rejected to the same safe fallback. This test
     * pins down that a truthful zero-medications claim IS currently
     * rejected -- an accepted over-cautious tradeoff, not a bug to fix here.
     */
    #[Test]
    public function truthfulZeroMedicationsClaimIsConservativelyRejectedKnownLimitation(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())
            ->toolUse('get_medications')
            ->submitAnswer([
                'insufficient_information' => false,
                'claims' => [['text' => 'No active medications are on file.', 'source_tool' => 'get_medications']],
            ])
            ->finalText();

        $service = new CopilotService($this->tools(), $factory);
        $result = $service->ask('Is this patient on any medications?', 'test-correlation-zero-meds');

        self::assertFalse($result->verificationPassed);
        self::assertSame(ResponseVerifier::FALLBACK_REPLY, $result->reply);
    }

    /**
     * Failure mode guarded against: PUNCH_LIST.md 1.4's remaining gap --
     * an "active" prescription with no end_date and a start_date old enough
     * to doubt must carry MedicationStalenessPolicy's advisory warning, so
     * neither the model nor the clinician treats an old start date as
     * unambiguous proof of current use. Exercises ChartContextTools
     * directly against real DB data (no LLM turn needed for this one).
     */
    #[Test]
    public function openEndedOldPrescriptionCarriesStalenessWarning(): void
    {
        $threeYearsAgo = (new DateTimeImmutable('-3 years'))->format('Y-m-d');
        $this->fixtures->seedStaleOpenEndedMedication($this->pid, 'Lisinopril', $threeYearsAgo);

        $result = $this->tools()->call('get_medications');

        self::assertInstanceOf(MedicationsResult::class, $result);
        self::assertTrue($result->ok);
        self::assertCount(1, $result->rows);

        $row = $result->rows[0];
        self::assertInstanceOf(MedicationRow::class, $row);
        self::assertNotNull($row->staleWarning);
        self::assertStringContainsString('no end date recorded', $row->staleWarning);
    }

    private function tools(): ChartContextTools
    {
        return new ChartContextTools($this->pid, 'admin', 'admin', 'test-correlation');
    }
}
