<?php

/**
 * Isolated FactualConsistencyValidator Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Eval\FactualConsistency;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\FactualConsistency\FactualConsistencyClaim;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\FactualConsistency\FactualConsistencyValidator;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval\FactualConsistencyGoldenSet;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval\FactualConsistencyGoldenSetCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Citation/Citation.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/FactualConsistency/FactualConsistencyClaim.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/FactualConsistency/FactualConsistencyFinding.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/FactualConsistency/FactualConsistencyResult.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/FactualConsistency/FactualConsistencyValidator.php';

class FactualConsistencyValidatorTest extends TestCase
{
    public function testExactMatchPasses(): void
    {
        $claim = FactualConsistencyClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'asserted_value' => '7.2%',
            'citation' => [
                'source_type' => 'lab_pdf',
                'source_id' => 'lab-001',
                'page_or_section' => 'page_1',
                'field_or_chunk_id' => 'a1c',
                'quote_or_value' => '7.2%',
            ],
        ]);
        self::assertNotNull($claim);

        $result = FactualConsistencyValidator::evaluateClaim($claim);

        self::assertTrue($result->factuallyConsistent);
        self::assertSame([], $result->findings);
    }

    public function testPrecisionFormattingDifferencePasses(): void
    {
        $claim = FactualConsistencyClaim::fromMixed([
            'claim' => "The patient's A1C is 7.20%.",
            'asserted_value' => '7.20%',
            'citation' => [
                'quote_or_value' => '7.2%',
            ],
        ]);
        self::assertNotNull($claim);

        $result = FactualConsistencyValidator::evaluateClaim($claim);

        self::assertTrue($result->factuallyConsistent);
    }

    public function testNumericDriftFails(): void
    {
        $claim = FactualConsistencyClaim::fromMixed([
            'claim' => "The patient's A1C is 7.9%.",
            'asserted_value' => '7.9%',
            'citation' => [
                'quote_or_value' => '7.2%',
            ],
        ]);
        self::assertNotNull($claim);

        $result = FactualConsistencyValidator::evaluateClaim($claim);

        self::assertFalse($result->factuallyConsistent);
        self::assertCount(1, $result->findings);
        self::assertSame('7.9%', $result->findings[0]->assertedValue);
        self::assertSame('7.2%', $result->findings[0]->quoteOrValue);
    }

    public function testNarrativeClaimWithNoDigitIsSkipped(): void
    {
        $claim = FactualConsistencyClaim::fromMixed([
            'claim' => 'The patient reports a known penicillin allergy.',
            'asserted_value' => 'Penicillin allergy noted',
            'citation' => [
                'quote_or_value' => 'Penicillin - rash',
            ],
        ]);
        self::assertNotNull($claim);

        $result = FactualConsistencyValidator::evaluateClaim($claim);

        self::assertTrue($result->factuallyConsistent);
        self::assertSame([], $result->findings);
    }

    public function testClaimWithNoCitationIsSkipped(): void
    {
        $claim = FactualConsistencyClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'asserted_value' => '7.2%',
        ]);
        self::assertNotNull($claim);

        $result = FactualConsistencyValidator::evaluateClaim($claim);

        self::assertTrue($result->factuallyConsistent);
        self::assertSame([], $result->findings);
    }

    public function testBatchFailsWhenOneCheckableClaimDrifts(): void
    {
        $good = FactualConsistencyClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'asserted_value' => '7.2%',
            'citation' => ['quote_or_value' => '7.2%'],
        ]);
        $skipped = FactualConsistencyClaim::fromMixed([
            'claim' => 'The patient reports a known penicillin allergy.',
            'asserted_value' => 'Penicillin allergy noted',
            'citation' => ['quote_or_value' => 'Penicillin - rash'],
        ]);
        $bad = FactualConsistencyClaim::fromMixed([
            'claim' => 'The most recent LDL was 90 mg/dL.',
            'asserted_value' => '90 mg/dL',
            'citation' => ['quote_or_value' => '130 mg/dL'],
        ]);
        self::assertNotNull($good);
        self::assertNotNull($skipped);
        self::assertNotNull($bad);

        $result = FactualConsistencyValidator::evaluateBatch([$good, $skipped, $bad]);

        self::assertFalse($result->factuallyConsistent);
        self::assertCount(1, $result->findings);
        self::assertSame('90 mg/dL', $result->findings[0]->assertedValue);
    }

    /**
     * Matches SKILL.md's "Eval Output" example verbatim: toArray() is the
     * machine-readable shape the Week 2 eval gate consumes.
     */
    public function testToArrayMatchesSkillContractShape(): void
    {
        $claim = FactualConsistencyClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'asserted_value' => '7.2%',
            'citation' => ['quote_or_value' => '7.2%'],
        ]);
        self::assertNotNull($claim);

        $result = FactualConsistencyValidator::evaluateClaim($claim);

        self::assertSame(['factually_consistent' => true, 'failures' => []], $result->toArray());
    }

    #[DataProvider('goldenSetProvider')]
    public function testGoldenSetCase(FactualConsistencyGoldenSetCase $case): void
    {
        $claims = [];
        foreach ($case->claims as $rawClaim) {
            $claim = FactualConsistencyClaim::fromMixed($rawClaim);
            self::assertNotNull($claim, "golden set case '{$case->id}' has a malformed claim fixture");
            $claims[] = $claim;
        }

        $result = FactualConsistencyValidator::evaluateBatch($claims);

        self::assertSame(
            $case->expectedFactuallyConsistent,
            $result->factuallyConsistent,
            "golden set case '{$case->id}' ({$case->description}): " . json_encode($result->toArray()),
        );
    }

    /**
     * @return array<string, array{FactualConsistencyGoldenSetCase}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function goldenSetProvider(): array
    {
        $cases = [];
        foreach (FactualConsistencyGoldenSet::cases() as $case) {
            $cases[$case->id] = [$case];
        }

        return $cases;
    }
}
