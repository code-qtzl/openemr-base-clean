<?php

/**
 * Isolated ExtractionPromptBuilder Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaValidator;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\ExtractionPromptBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaDocType.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaFieldKind.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/ExtractedDocument.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaFinding.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaValidationResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaValidator.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Extraction/ExtractionPromptBuilder.php';

class ExtractionPromptBuilderTest extends TestCase
{
    public function testLabPdfPromptMentionsEveryRequiredField(): void
    {
        $prompt = ExtractionPromptBuilder::build(SchemaDocType::LabPdf);

        foreach (SchemaValidator::requiredFieldNames(SchemaDocType::LabPdf) as $field) {
            self::assertStringContainsString("\"{$field}\"", $prompt, "prompt is missing required field {$field}");
        }
    }

    public function testIntakeFormPromptMentionsEveryRequiredField(): void
    {
        $prompt = ExtractionPromptBuilder::build(SchemaDocType::IntakeForm);

        foreach (SchemaValidator::requiredFieldNames(SchemaDocType::IntakeForm) as $field) {
            self::assertStringContainsString("\"{$field}\"", $prompt, "prompt is missing required field {$field}");
        }
    }

    public function testLabPromptAsksForOneResultObjectPerTestRowWithItsOwnBbox(): void
    {
        $prompt = ExtractionPromptBuilder::build(SchemaDocType::LabPdf);

        self::assertStringContainsString('"results"', $prompt);
        self::assertStringContainsString('ONE object per test result row', $prompt);
        self::assertStringContainsString('each result gets its own box', $prompt);
    }

    public function testLabPromptTellsTheModelToLeaveANormalResultsFlagBlankNotInvent(): void
    {
        $prompt = ExtractionPromptBuilder::build(SchemaDocType::LabPdf);

        self::assertStringContainsString('empty string', $prompt);
        self::assertStringContainsString('never invent a flag', $prompt);
    }

    public function testIntakePromptKeepsTheSingleFieldsShapeAndNeverMentionsResults(): void
    {
        $prompt = ExtractionPromptBuilder::build(SchemaDocType::IntakeForm);

        self::assertStringContainsString('"fields": {...}', $prompt);
        self::assertStringNotContainsString('"results"', $prompt);
    }

    public function testPromptMentionsAllFiveCitationSubFields(): void
    {
        $prompt = ExtractionPromptBuilder::build(SchemaDocType::LabPdf);

        foreach (['source_type', 'source_id', 'page_or_section', 'field_or_chunk_id', 'quote_or_value'] as $citationField) {
            self::assertStringContainsString("\"{$citationField}\"", $prompt);
        }
    }

    public function testPromptNamesTheRequestedDocType(): void
    {
        self::assertStringContainsString('lab_pdf', ExtractionPromptBuilder::build(SchemaDocType::LabPdf));
        self::assertStringContainsString('intake_form', ExtractionPromptBuilder::build(SchemaDocType::IntakeForm));
    }
}
