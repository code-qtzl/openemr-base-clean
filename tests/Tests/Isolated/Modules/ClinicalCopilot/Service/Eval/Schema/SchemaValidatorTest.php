<?php

/**
 * Isolated SchemaValidator Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Eval\Schema;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\ExtractedDocument;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaFinding;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaValidator;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval\SchemaGoldenSet;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval\SchemaGoldenSetCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaDocType.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaFieldKind.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/ExtractedDocument.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaFinding.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaValidationResult.php';
require_once __DIR__ . '/../../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaValidator.php';

class SchemaValidatorTest extends TestCase
{
    public function testFullyPopulatedLabPdfPasses(): void
    {
        $document = ExtractedDocument::fromMixed([
            'doc_type' => 'lab_pdf',
            'fields' => [
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
            ],
        ]);
        self::assertNotNull($document);

        $result = SchemaValidator::validate($document);

        self::assertTrue($result->schemaValid);
        self::assertSame([], $result->findings);
    }

    public function testMissingFieldsAreReportedIndividually(): void
    {
        $document = ExtractedDocument::fromMixed([
            'doc_type' => 'lab_pdf',
            'fields' => [
                'test_name' => 'HbA1c',
                'value' => '7.2',
            ],
        ]);
        self::assertNotNull($document);

        $result = SchemaValidator::validate($document);

        self::assertFalse($result->schemaValid);
        $missingFields = array_map(static fn (SchemaFinding $finding): string => $finding->field, $result->findings);
        self::assertSame(
            ['unit', 'reference_range', 'collection_date', 'abnormal_flag', 'source_citation'],
            $missingFields,
        );
        self::assertSame('missing', $result->findings[0]->reason);
    }

    public function testEmptyListFieldsPassButEmptyObjectFieldsFail(): void
    {
        $document = ExtractedDocument::fromMixed([
            'doc_type' => 'intake_form',
            'fields' => [
                'demographics' => [],
                'chief_concern' => 'Annual physical.',
                'current_medications' => [],
                'allergies' => [],
                'family_history' => 'Noncontributory.',
                'source_citation' => [
                    'source_type' => 'intake_form',
                    'source_id' => 'intake-1',
                    'page_or_section' => 'demographics',
                    'field_or_chunk_id' => 'demographics',
                    'quote_or_value' => '29 y/o male',
                ],
            ],
        ]);
        self::assertNotNull($document);

        $result = SchemaValidator::validate($document);

        self::assertFalse($result->schemaValid);
        self::assertCount(1, $result->findings);
        self::assertSame('demographics', $result->findings[0]->field);
        self::assertSame('empty', $result->findings[0]->reason);
    }

    public function testNonStringScalarsAreValid(): void
    {
        $document = ExtractedDocument::fromMixed([
            'doc_type' => 'lab_pdf',
            'fields' => [
                'test_name' => 'HbA1c',
                'value' => 7.2,
                'unit' => '%',
                'reference_range' => '4.0-5.6',
                'collection_date' => '2026-01-15',
                'abnormal_flag' => false,
                'source_citation' => [
                    'source_type' => 'lab_pdf',
                    'source_id' => 'lab-002',
                    'page_or_section' => 'page_1',
                    'field_or_chunk_id' => 'hba1c',
                    'quote_or_value' => '7.2',
                ],
            ],
        ]);
        self::assertNotNull($document);

        $result = SchemaValidator::validate($document);

        self::assertTrue($result->schemaValid);
    }

    /**
     * Matches SKILL.md's "Eval Output" example verbatim: toArray() is the
     * machine-readable shape the Week 2 eval gate consumes.
     */
    public function testToArrayMatchesSkillContractShape(): void
    {
        $document = ExtractedDocument::fromMixed([
            'doc_type' => 'lab_pdf',
            'fields' => [
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
            ],
        ]);
        self::assertNotNull($document);

        $result = SchemaValidator::validate($document);

        self::assertSame(['schema_valid' => true, 'failures' => []], $result->toArray());
    }

    #[DataProvider('goldenSetProvider')]
    public function testGoldenSetCase(SchemaGoldenSetCase $case): void
    {
        $document = ExtractedDocument::fromMixed($case->document);
        self::assertNotNull($document, "golden set case '{$case->id}' has a malformed document fixture");

        $result = SchemaValidator::validate($document);

        self::assertSame(
            $case->expectedSchemaValid,
            $result->schemaValid,
            "golden set case '{$case->id}' ({$case->description}): " . json_encode($result->toArray()),
        );
    }

    /**
     * @return array<string, array{SchemaGoldenSetCase}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function goldenSetProvider(): array
    {
        $cases = [];
        foreach (SchemaGoldenSet::cases() as $case) {
            $cases[$case->id] = [$case];
        }

        return $cases;
    }
}
