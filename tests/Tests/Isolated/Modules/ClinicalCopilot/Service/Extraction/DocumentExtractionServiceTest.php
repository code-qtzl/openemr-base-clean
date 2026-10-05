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
        self::assertCount(1, $result->documents);
        self::assertSame(SchemaDocType::LabPdf, $result->documents[0]->docType);
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

    /**
     * @return list<array<string, mixed>>
     */
    private static function panelResults(int $count): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = [...self::VALID_LAB_FIELDS, 'test_name' => 'test-' . $i];
        }

        return $results;
    }

    private static function labPayload(): DocumentPayload
    {
        return DocumentPayload::fromBytes('lab.pdf', 'application/pdf', '%PDF-1.4 fake bytes');
    }

    public function testLabPanelYieldsOneDocumentPerResultEachKeepingTheFlatSchema(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'lab_pdf', 'results' => self::panelResults(3)],
            JSON_THROW_ON_ERROR,
        ));

        $result = (new DocumentExtractionService($factory))->extract('corr-p1', self::labPayload(), SchemaDocType::LabPdf);

        self::assertTrue($result->success);
        self::assertCount(3, $result->documents);
        self::assertSame(
            ['test-0', 'test-1', 'test-2'],
            array_map(static fn ($d): mixed => $d->fields['test_name'], $result->documents),
        );
        self::assertNotNull($result->telemetry);
        self::assertSame(3, $result->telemetry->resultCount);
        self::assertSame(21, $result->telemetry->fieldsExpected);
        self::assertSame(1.0, $result->telemetry->completeness());
    }

    /**
     * All-or-nothing: one bad row rejects the whole upload, and every failing
     * field is named with its row index so an incomplete panel is never stored.
     */
    public function testOneInvalidResultRejectsThePanelAndNamesTheFailingFieldsByIndex(): void
    {
        $results = self::panelResults(3);
        unset($results[0]['unit'], $results[2]['reference_range']);
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'lab_pdf', 'results' => $results],
            JSON_THROW_ON_ERROR,
        ));

        $result = (new DocumentExtractionService($factory))->extract('corr-p2', self::labPayload(), SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertNotNull($result->validation);
        self::assertSame(
            ['results[0].unit', 'results[2].reference_range'],
            array_map(static fn ($f): string => $f->field, $result->validation->findings),
        );
        self::assertNotNull($result->telemetry);
        self::assertSame(['results[0].unit', 'results[2].reference_range'], $result->telemetry->missingFields);
        self::assertSame(19, $result->telemetry->fieldsPresent());
    }

    public function testEmptyResultsListFailsToParse(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'lab_pdf', 'results' => []],
            JSON_THROW_ON_ERROR,
        ));

        $result = (new DocumentExtractionService($factory))->extract('corr-p3', self::labPayload(), SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertSame([], $result->documents);
        self::assertStringContainsString('no results', (string) $result->failureReason);
    }

    public function testMoreThanTheMaximumResultsIsRejectedNotTruncated(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'lab_pdf', 'results' => self::panelResults(DocumentExtractionService::MAX_RESULTS + 1)],
            JSON_THROW_ON_ERROR,
        ));

        $result = (new DocumentExtractionService($factory))->extract('corr-p4', self::labPayload(), SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertSame([], $result->documents);
        self::assertStringContainsString((string) DocumentExtractionService::MAX_RESULTS, (string) $result->failureReason);
    }

    public function testExactlyTheMaximumResultsIsAccepted(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'lab_pdf', 'results' => self::panelResults(DocumentExtractionService::MAX_RESULTS)],
            JSON_THROW_ON_ERROR,
        ));

        $result = (new DocumentExtractionService($factory))->extract('corr-p5', self::labPayload(), SchemaDocType::LabPdf);

        self::assertTrue($result->success);
        self::assertCount(DocumentExtractionService::MAX_RESULTS, $result->documents);
    }

    /** A cut-off response is invalid JSON by construction; the reason must say why. */
    public function testResponseCutOffAtTheTokenLimitFailsWithAClearReason(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->truncatedText('{"doc_type": "lab_pdf", "results": [{"test_na');

        $result = (new DocumentExtractionService($factory))->extract('corr-p6', self::labPayload(), SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertStringContainsString('cut off', (string) $result->failureReason);
        self::assertStringNotContainsString('valid JSON', (string) $result->failureReason);
    }

    public function testNonListResultsFailsToParse(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'lab_pdf', 'results' => ['a' => self::VALID_LAB_FIELDS]],
            JSON_THROW_ON_ERROR,
        ));

        $result = (new DocumentExtractionService($factory))->extract('corr-p7', self::labPayload(), SchemaDocType::LabPdf);

        self::assertFalse($result->success);
        self::assertStringContainsString('results', (string) $result->failureReason);
    }

    public function testIntakeFormIgnoresAResultsKeyAndStillRequiresFields(): void
    {
        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode(
            ['doc_type' => 'intake_form', 'results' => self::panelResults(2)],
            JSON_THROW_ON_ERROR,
        ));

        $result = (new DocumentExtractionService($factory))->extract(
            'corr-p8',
            DocumentPayload::fromBytes('intake.pdf', 'application/pdf', '%PDF-1.4 fake bytes'),
            SchemaDocType::IntakeForm,
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('envelope', (string) $result->failureReason);
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
        self::assertSame([], $result->documents);
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
        self::assertSame([], $result->documents);
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
