<?php

/**
 * Isolated CitationValidator Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Eval\Citation;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation\CitationValidator;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation\ClinicalClaim;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval\CitationGoldenSet;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval\CitationGoldenSetCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Citation/Citation.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Citation/CitationBoundingBox.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Citation/ClinicalClaim.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Citation/ClaimCitationResult.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Citation/CitationValidationReport.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Citation/CitationValidator.php';

class CitationValidatorTest extends TestCase
{
    public function testFullyCitedClaimPasses(): void
    {
        $claim = ClinicalClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'citation' => [
                'source_type' => 'lab_pdf',
                'source_id' => 'lab-001',
                'page_or_section' => 'page_1',
                'field_or_chunk_id' => 'a1c',
                'quote_or_value' => '7.2%',
                'document_id' => '4242',
                'bbox' => ['page' => 0, 'x0' => 0.12, 'y0' => 0.30, 'x1' => 0.44, 'y1' => 0.35],
            ],
        ]);
        self::assertNotNull($claim);

        $result = CitationValidator::validateClaim($claim);

        self::assertTrue($result->citationPresent);
        self::assertSame([], $result->missingFields);
    }

    public function testPartialCitationFailsAndReportsMissingFields(): void
    {
        $claim = ClinicalClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'citation' => [
                'source_type' => 'lab_pdf',
                'source_id' => 'lab-001',
            ],
        ]);
        self::assertNotNull($claim);

        $result = CitationValidator::validateClaim($claim);

        self::assertFalse($result->citationPresent);
        self::assertSame(
            ['page_or_section', 'field_or_chunk_id', 'quote_or_value', 'document_id', 'bbox'],
            $result->missingFields,
        );
    }

    public function testLabPdfCitationMissingBboxFailsEvenWithAllFiveTextFields(): void
    {
        $claim = ClinicalClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'citation' => [
                'source_type' => 'lab_pdf',
                'source_id' => 'lab-001',
                'page_or_section' => 'page_1',
                'field_or_chunk_id' => 'a1c',
                'quote_or_value' => '7.2%',
                'document_id' => '4242',
            ],
        ]);
        self::assertNotNull($claim);

        $result = CitationValidator::validateClaim($claim);

        self::assertFalse($result->citationPresent);
        self::assertSame(['bbox'], $result->missingFields);
    }

    public function testLabPdfCitationMissingDocumentIdFailsEvenWithAllFiveTextFields(): void
    {
        $claim = ClinicalClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'citation' => [
                'source_type' => 'lab_pdf',
                'source_id' => 'lab-001',
                'page_or_section' => 'page_1',
                'field_or_chunk_id' => 'a1c',
                'quote_or_value' => '7.2%',
                'bbox' => ['page' => 0, 'x0' => 0.12, 'y0' => 0.30, 'x1' => 0.44, 'y1' => 0.35],
            ],
        ]);
        self::assertNotNull($claim);

        $result = CitationValidator::validateClaim($claim);

        self::assertFalse($result->citationPresent);
        self::assertSame(['document_id'], $result->missingFields);
    }

    /**
     * chart_tool/guideline citations have no PDF page to point at --
     * Citation::requiresDocumentLinkage() must not demand document_id/bbox
     * for them.
     */
    public function testChartToolCitationDoesNotRequireDocumentLinkage(): void
    {
        $claim = ClinicalClaim::fromMixed([
            'claim' => "The patient's most recent A1C was 6.4%.",
            'citation' => [
                'source_type' => 'chart_tool',
                'source_id' => 'get_a1c_series',
                'page_or_section' => 'n/a',
                'field_or_chunk_id' => 'value',
                'quote_or_value' => '6.4%',
            ],
        ]);
        self::assertNotNull($claim);

        $result = CitationValidator::validateClaim($claim);

        self::assertTrue($result->citationPresent);
        self::assertSame([], $result->missingFields);
    }

    public function testMissingCitationObjectFailsWithAllFieldsReportedMissing(): void
    {
        $claim = ClinicalClaim::fromMixed(['claim' => 'The patient is improving.']);
        self::assertNotNull($claim);

        $result = CitationValidator::validateClaim($claim);

        self::assertFalse($result->citationPresent);
        self::assertSame(
            ['source_type', 'source_id', 'page_or_section', 'field_or_chunk_id', 'quote_or_value'],
            $result->missingFields,
        );
    }

    public function testEmptyStringFieldsCountAsMissing(): void
    {
        $claim = ClinicalClaim::fromMixed([
            'claim' => 'The patient has a documented penicillin allergy.',
            'citation' => [
                'source_type' => '',
                'source_id' => '',
                'page_or_section' => '',
                'field_or_chunk_id' => '',
                'quote_or_value' => '   ',
            ],
        ]);
        self::assertNotNull($claim);

        $result = CitationValidator::validateClaim($claim);

        self::assertFalse($result->citationPresent);
        self::assertSame(
            ['source_type', 'source_id', 'page_or_section', 'field_or_chunk_id', 'quote_or_value'],
            $result->missingFields,
        );
    }

    public function testBatchPassesOnlyWhenEveryClaimIsGrounded(): void
    {
        $cited = ClinicalClaim::fromMixed([
            'claim' => "The patient's most recent A1C was 6.4% on 2026-01-15.",
            'citation' => [
                'source_type' => 'lab_pdf',
                'source_id' => 'lab-002',
                'page_or_section' => 'page_1',
                'field_or_chunk_id' => 'a1c',
                'quote_or_value' => '6.4%',
                'document_id' => '4243',
                'bbox' => ['page' => 0, 'x0' => 0.15, 'y0' => 0.20, 'x1' => 0.40, 'y1' => 0.25],
            ],
        ]);
        $uncited = ClinicalClaim::fromMixed(['claim' => 'The patient is at low risk for complications.']);
        self::assertNotNull($cited);
        self::assertNotNull($uncited);

        $report = CitationValidator::validateBatch([$cited, $uncited]);

        self::assertFalse($report->citationPresent);
        self::assertCount(1, $report->failures);
        self::assertSame('The patient is at low risk for complications.', $report->failures[0]->claim);
    }

    /**
     * Matches SKILL.md's "Eval Output" example verbatim: toArray() is the
     * machine-readable shape the Week 2 eval gate consumes.
     */
    public function testToArrayMatchesSkillContractShape(): void
    {
        $claim = ClinicalClaim::fromMixed([
            'claim' => "The patient's A1C is 7.2%.",
            'citation' => [
                'source_type' => 'lab_pdf',
                'source_id' => 'lab-001',
                'page_or_section' => 'page_1',
                'field_or_chunk_id' => 'a1c',
                'quote_or_value' => '7.2%',
                'document_id' => '4242',
                'bbox' => ['page' => 0, 'x0' => 0.12, 'y0' => 0.30, 'x1' => 0.44, 'y1' => 0.35],
            ],
        ]);
        self::assertNotNull($claim);

        $report = CitationValidator::validateBatch([$claim]);

        self::assertSame(['citation_present' => true, 'failures' => []], $report->toArray());
    }

    #[DataProvider('goldenSetProvider')]
    public function testGoldenSetCase(CitationGoldenSetCase $case): void
    {
        $claims = [];
        foreach ($case->claims as $rawClaim) {
            $claim = ClinicalClaim::fromMixed($rawClaim);
            self::assertNotNull($claim, "golden set case '{$case->id}' has a malformed claim fixture");
            $claims[] = $claim;
        }

        $report = CitationValidator::validateBatch($claims);

        self::assertSame(
            $case->expectedCitationPresent,
            $report->citationPresent,
            "golden set case '{$case->id}' ({$case->description}): " . json_encode($report->toArray()),
        );
    }

    /**
     * @return array<string, array{CitationGoldenSetCase}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function goldenSetProvider(): array
    {
        $cases = [];
        foreach (CitationGoldenSet::cases() as $case) {
            $cases[$case->id] = [$case];
        }

        return $cases;
    }
}
