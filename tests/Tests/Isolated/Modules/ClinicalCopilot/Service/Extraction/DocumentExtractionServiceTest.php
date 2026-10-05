<?php

/**
 * Isolated DocumentExtractionService Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentExtractionService;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentPayload;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ScriptedAnthropicClientFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/AnthropicClientFactory.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/DefaultAnthropicClientFactory.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/CopilotService.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaDocType.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaFieldKind.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/ExtractedDocument.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaFinding.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaValidationResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Schema/SchemaValidator.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Extraction/DocumentPayload.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Extraction/ExtractionPromptBuilder.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Extraction/ExtractionTelemetry.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Extraction/ExtractionResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Extraction/DocumentExtractionService.php';
require_once __DIR__ . '/../../../../../../../tests/Tests/Fixtures/ClinicalCopilot/FakeAnthropicTransporter.php';
require_once __DIR__ . '/../../../../../../../tests/Tests/Fixtures/ClinicalCopilot/ScriptedAnthropicClientFactory.php';

class DocumentExtractionServiceTest extends TestCase
{
    private const VALID_LAB_FIELDS = [
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
    ];

    public function testSchemaValidResponsePassesThroughAsSuccess(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => self::VALID_LAB_FIELDS,
        ], JSON_THROW_ON_ERROR));
        $service = new DocumentExtractionService($factory);
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = $service->extract('corr-1', $payload, SchemaDocType::LabPdf);

        self::assertTrue($result->success);
        self::assertNotNull($result->document);
        self::assertSame(SchemaDocType::LabPdf, $result->document->docType);
    }

    public function testSuccessCarriesTelemetryWithFullCompleteness(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => self::VALID_LAB_FIELDS,
        ], JSON_THROW_ON_ERROR));
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = (new DocumentExtractionService($factory))->extract('corr-t1', $payload, SchemaDocType::LabPdf);

        $telemetry = $result->telemetry;
        self::assertNotNull($telemetry);
        self::assertSame('claude-opus-5', $telemetry->model);
        self::assertSame(7, $telemetry->fieldsExpected);
        self::assertSame([], $telemetry->missingFields);
        self::assertSame(1.0, $telemetry->completeness());
        self::assertGreaterThanOrEqual($telemetry->startedAt, $telemetry->endedAt);
        self::assertGreaterThanOrEqual(0, $telemetry->inputTokens);
        self::assertGreaterThanOrEqual(0, $telemetry->outputTokens);
    }

    /**
     * An incomplete extraction must be visible without trusting the model to
     * say so: the missing schema field names fall out of SchemaValidator.
     */
    public function testSchemaInvalidResponseReportsWhichFieldsAreMissing(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => ['test_name' => 'HbA1c', 'value' => '7.2'],
        ], JSON_THROW_ON_ERROR));
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = (new DocumentExtractionService($factory))->extract('corr-t2', $payload, SchemaDocType::LabPdf);

        $telemetry = $result->telemetry;
        self::assertNotNull($telemetry);
        self::assertSame(
            ['unit', 'reference_range', 'collection_date', 'abnormal_flag', 'source_citation'],
            $telemetry->missingFields,
        );
        self::assertSame(2, $telemetry->fieldsPresent());
        self::assertSame(0.2857, $telemetry->completeness());
    }

    public function testUnparseableResponseCountsEveryFieldAsMissing(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText('not json at all');
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = (new DocumentExtractionService($factory))->extract('corr-t3', $payload, SchemaDocType::LabPdf);

        $telemetry = $result->telemetry;
        self::assertNotNull($telemetry);
        self::assertSame(0, $telemetry->fieldsPresent());
        self::assertSame(0.0, $telemetry->completeness());
    }

    public function testSchemaInvalidResponseIsReportedNotSilentlyTrusted(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => ['test_name' => 'HbA1c'],
        ], JSON_THROW_ON_ERROR));
        $service = new DocumentExtractionService($factory);
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = $service->extract('corr-2', $payload, SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertNotNull($result->validation);
        self::assertFalse($result->validation->schemaValid);
        self::assertNull($result->failureReason);
    }

    public function testNonJsonResponseFailsToParse(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText('not json at all');
        $service = new DocumentExtractionService($factory);
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = $service->extract('corr-3', $payload, SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertNull($result->document);
        self::assertNotNull($result->failureReason);
    }

    public function testMismatchedDocTypeFailsToParse(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'intake_form',
            'fields' => self::VALID_LAB_FIELDS,
        ], JSON_THROW_ON_ERROR));
        $service = new DocumentExtractionService($factory);
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $result = $service->extract('corr-4', $payload, SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertNull($result->document);
        self::assertStringContainsString('doc_type', (string) $result->failureReason);
    }

    public function testRequestSentToAnthropicCarriesTheDocumentContentBlock(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => self::VALID_LAB_FIELDS,
        ], JSON_THROW_ON_ERROR));
        $service = new DocumentExtractionService($factory);
        $payload = DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');

        $service->extract('corr-5', $payload, SchemaDocType::LabPdf);

        $sentBodies = $factory->lastTransporter()?->sentBodies() ?? [];
        self::assertNotEmpty($sentBodies);
        self::assertStringContainsString('"media_type":"application/pdf"', $sentBodies[0]);
        self::assertStringContainsString(base64_encode('%PDF-1.4 fake bytes'), $sentBodies[0]);
    }
}
