<?php

/**
 * Isolated ResponseVerifier Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Verification;

use OpenEMR\Modules\ClinicalCopilot\Service\Verification\ResponseVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Verification/VerificationClaim.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Verification/VerificationOutcome.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Verification/ResponseVerifier.php';

class ResponseVerifierTest extends TestCase
{
    public function testHonestInsufficientInformationPasses(): void
    {
        // PUNCH_LIST.md 2.1's boundary case: an empty chart must produce an
        // honest "I don't know", not a rejection -- this is the model doing
        // the right thing, not a violation to flag.
        $outcome = ResponseVerifier::verify(
            ['insufficient_information' => true, 'summary' => 'The chart has no recorded A1c results.'],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 0],
        );

        self::assertTrue($outcome->passed);
        self::assertSame('The chart has no recorded A1c results.', $outcome->reply);
    }

    public function testInsufficientInformationWithoutSummaryUsesFallback(): void
    {
        $outcome = ResponseVerifier::verify(
            ['insufficient_information' => true, 'summary' => ''],
            calledTools: [],
            toolRowCounts: [],
        );

        self::assertTrue($outcome->passed);
        self::assertSame(ResponseVerifier::FALLBACK_REPLY, $outcome->reply);
    }

    public function testFullyCitedClaimPasses(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'summary' => 'Based on the chart:',
                'claims' => [
                    ['text' => 'A1c was 7.2% on 2026-01-10.', 'source_tool' => 'get_a1c_series'],
                ],
            ],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertTrue($outcome->passed);
        self::assertStringContainsString('A1c was 7.2%', $outcome->reply);
        self::assertStringContainsString('Based on the chart:', $outcome->reply);
    }

    public function testUncitedClaimIsRejected(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    ['text' => 'The patient is improving.', 'source_tool' => null],
                ],
            ],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertFalse($outcome->passed);
        self::assertSame(ResponseVerifier::FALLBACK_REPLY, $outcome->reply);
        self::assertNotNull($outcome->reason);
    }

    public function testClaimCitingAToolNotCalledThisTurnIsRejected(): void
    {
        // Guards against a stale/hallucinated citation to a tool the model
        // never actually invoked in this turn's transcript.
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    ['text' => 'The patient has type 2 diabetes.', 'source_tool' => 'get_active_problems'],
                ],
            ],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertFalse($outcome->passed);
    }

    public function testNoClaimsWithoutInsufficientInformationFlagIsRejected(): void
    {
        // The model must not silently produce a claim-free "definitive"
        // answer without either citing something or admitting it can't.
        $outcome = ResponseVerifier::verify(
            ['insufficient_information' => false, 'claims' => []],
            calledTools: [],
            toolRowCounts: [],
        );

        self::assertFalse($outcome->passed);
    }

    public function testMissingSubmitAnswerCallIsRejected(): void
    {
        // CopilotService passes [] when the forced tool_choice turn produced
        // no submit_answer block at all (e.g. a refusal) -- must fail closed.
        $outcome = ResponseVerifier::verify([], calledTools: [], toolRowCounts: []);

        self::assertFalse($outcome->passed);
    }

    public function testMalformedClaimIsRejected(): void
    {
        $outcome = ResponseVerifier::verify(
            ['insufficient_information' => false, 'claims' => ['not-an-object']],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertFalse($outcome->passed);
    }

    /**
     * PUNCH_LIST.md 1.4(b): a patient with zero medication rows cannot
     * produce a "patient is on X" claim that passes verification, no matter
     * how the model phrases it or how confidently.
     */
    public function testMedicationClaimWithZeroRowsIsRejectedRegardlessOfWording(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    ['text' => 'The patient is currently taking Metformin.', 'source_tool' => 'get_medications'],
                ],
            ],
            calledTools: ['get_medications'],
            toolRowCounts: ['get_medications' => 0],
        );

        self::assertFalse($outcome->passed);
        self::assertStringContainsString('get_medications', (string) $outcome->reason);
    }

    public function testMedicationClaimWithRowsPasses(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    ['text' => 'The patient takes Metformin 500mg BID.', 'source_tool' => 'get_medications'],
                ],
            ],
            calledTools: ['get_medications'],
            toolRowCounts: ['get_medications' => 1],
        );

        self::assertTrue($outcome->passed);
    }
}
