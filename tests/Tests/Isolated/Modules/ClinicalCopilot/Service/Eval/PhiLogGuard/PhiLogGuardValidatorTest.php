<?php

/**
 * Isolated PhiLogGuardValidator Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Eval\PhiLogGuard;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\PhiLogGuard\LogEmission;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\PhiLogGuard\PhiLogGuardValidator;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval\PhiLogGuardGoldenSet;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval\PhiLogGuardGoldenSetCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/PhiLogGuard/LogEmission.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/PhiLogGuard/PhiFinding.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/PhiLogGuard/PhiScanResult.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/PhiLogGuard/PhiLogGuardValidator.php';

class PhiLogGuardValidatorTest extends TestCase
{
    public function testCorrelationIdOnlyTelemetryPasses(): void
    {
        $emission = LogEmission::fromMixed([
            'log_target' => 'langfuse_trace',
            'payload' => [
                'correlation_id' => 'c-123',
                'extraction_confidence' => 0.92,
            ],
        ]);
        self::assertNotNull($emission);

        $result = PhiLogGuardValidator::scan($emission);

        self::assertTrue($result->noPhiInLogs);
        self::assertSame([], $result->findings);
    }

    public function testRawExtractedTextFailsRegardlessOfOtherFields(): void
    {
        $emission = LogEmission::fromMixed([
            'log_target' => 'langfuse_trace',
            'payload' => [
                'correlation_id' => 'c-123',
                'extracted_text' => 'Patient Jane Doe, DOB 1958-02-03, A1C 7.2%',
            ],
        ]);
        self::assertNotNull($emission);

        $result = PhiLogGuardValidator::scan($emission);

        self::assertFalse($result->noPhiInLogs);
        self::assertCount(1, $result->findings);
        self::assertSame('extracted_text', $result->findings[0]->field);
        self::assertSame('langfuse_trace', $result->findings[0]->destination);
    }

    public function testApplicationResponseIsExemptEvenWithQuoteOrValue(): void
    {
        $emission = LogEmission::fromMixed([
            'log_target' => 'application_response',
            'payload' => [
                'citation' => [
                    'quote_or_value' => '7.2%',
                ],
            ],
        ]);
        self::assertNotNull($emission);

        $result = PhiLogGuardValidator::scan($emission);

        self::assertTrue($result->noPhiInLogs);
        self::assertSame([], $result->findings);
    }

    public function testSameQuoteOrValueFieldFailsWhenSentToTelemetryInstead(): void
    {
        $emission = LogEmission::fromMixed([
            'log_target' => 'langfuse_trace',
            'payload' => [
                'quote_or_value' => '7.2%',
            ],
        ]);
        self::assertNotNull($emission);

        $result = PhiLogGuardValidator::scan($emission);

        self::assertFalse($result->noPhiInLogs);
        self::assertCount(1, $result->findings);
        self::assertSame('quote_or_value', $result->findings[0]->field);
    }

    public function testExceptionMessageEmbeddingPatientNameFailsViaValuePattern(): void
    {
        $emission = LogEmission::fromMixed([
            'log_target' => 'php_error_log',
            'payload' => [
                'message' => 'Extraction failed for Patient Jane Doe: timeout after 30s',
            ],
        ]);
        self::assertNotNull($emission);

        $result = PhiLogGuardValidator::scan($emission);

        self::assertFalse($result->noPhiInLogs);
        self::assertSame('message', $result->findings[0]->field);
    }

    /**
     * Matches SKILL.md's "Eval Output" example verbatim: toArray() is the
     * machine-readable shape the Week 2 eval gate consumes.
     */
    public function testToArrayMatchesSkillContractShape(): void
    {
        $emission = LogEmission::fromMixed([
            'log_target' => 'langfuse_trace',
            'payload' => [
                'correlation_id' => 'c-123',
                'tool' => 'attach_and_extract',
            ],
        ]);
        self::assertNotNull($emission);

        $result = PhiLogGuardValidator::scan($emission);

        self::assertSame(['no_phi_in_logs' => true, 'failures' => []], $result->toArray());
    }

    #[DataProvider('goldenSetProvider')]
    public function testGoldenSetCase(PhiLogGuardGoldenSetCase $case): void
    {
        $emission = LogEmission::fromMixed($case->emission);
        self::assertNotNull($emission, "golden set case '{$case->id}' has a malformed emission fixture");

        $result = PhiLogGuardValidator::scan($emission);

        self::assertSame(
            $case->expectedNoPhiInLogs,
            $result->noPhiInLogs,
            "golden set case '{$case->id}' ({$case->description}): " . json_encode($result->toArray()),
        );
    }

    /**
     * @return array<string, array{PhiLogGuardGoldenSetCase}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function goldenSetProvider(): array
    {
        $cases = [];
        foreach (PhiLogGuardGoldenSet::cases() as $case) {
            $cases[$case->id] = [$case];
        }

        return $cases;
    }
}
