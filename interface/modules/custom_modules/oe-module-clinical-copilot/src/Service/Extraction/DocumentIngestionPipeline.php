<?php

/**
 * AgentForge2 Core Requirement #1's `attach_and_extract(patient_id,
 * file_path, doc_type)`: accepts a file, extracts and self-validates
 * structured JSON via DocumentExtractionService, and -- only when that
 * validation passes -- stores the source document in OpenEMR and persists
 * the extraction, each linked back to the other.
 *
 * Wired into the live chat tool loop (ChartContextTools::extractedDocuments())
 * and, via CopilotDocumentUploadController, the sidebar's attach-document
 * flow -- this class is the actual attach_and_extract implementation both
 * call into.
 *
 * The document-storage step is not wrapped in a real database transaction
 * with the extraction-row insert -- DocumentAttachmentService::store() does
 * its own file I/O (CouchDB or local filesystem, per Document::createDocument())
 * alongside its own SQL, not reliably coverable by QueryUtils' ADODB
 * transaction wrapper. Instead, a failure there (or in attachDocumentId())
 * is treated as a compensating action: the already-inserted extraction row
 * is deleted before the original exception propagates, so a failed ingest
 * leaves nothing behind rather than an orphaned row with a null
 * document_id. If that compensating delete itself fails (e.g. the DB
 * connection that just failed the insert is also gone), it is logged
 * rather than swallowed, and the original exception still propagates --
 * the caller always learns about the real failure either way.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\ExtractedDocument;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use Throwable;

final class DocumentIngestionPipeline
{
    public function __construct(
        private readonly DocumentExtractionService $extractionService = new DocumentExtractionService(),
        private readonly SqlExtractedDocumentStore $store = new SqlExtractedDocumentStore(),
        private readonly DocumentAttachmentService $attachmentService = new DocumentAttachmentService(),
    ) {
    }

    public function ingest(
        int $patientId,
        string $correlationId,
        DocumentPayload $payload,
        SchemaDocType $docType,
    ): DocumentIngestionResult {
        $extraction = $this->extractionService->extract($correlationId, $payload, $docType);
        if (!$extraction->success || $extraction->documents === []) {
            return DocumentIngestionResult::extractionFailed($extraction);
        }

        // One row per extracted result (a lab panel yields several), all
        // pointing at the single stored document. A failure anywhere below
        // rolls back every row inserted so far, so a failed ingest leaves
        // nothing behind.
        $extractionIds = [];
        $stampedDocuments = [];
        try {
            foreach ($extraction->documents as $document) {
                $extractionIds[] = $this->store->save($patientId, $docType, self::encode($document->docType, $document->fields));
            }

            $documentId = $this->attachmentService->store($patientId, $docType, $payload, $extractionIds[0]);

            foreach ($extraction->documents as $index => $document) {
                $stampedFields = self::stampDocumentId($document->fields, $documentId);
                $this->store->attachDocumentId(
                    $extractionIds[$index],
                    $documentId,
                    self::encode($document->docType, $stampedFields),
                );

                $stampedDocuments[] = ExtractedDocument::fromMixed([
                    'doc_type' => $document->docType->value,
                    'fields' => $stampedFields,
                ]) ?? $document;
            }
        } catch (Throwable $e) {
            $this->rollBackExtractions($extractionIds, $e);

            throw $e;
        }

        return DocumentIngestionResult::success($extractionIds, $documentId, $stampedDocuments, $extraction->telemetry);
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private static function encode(SchemaDocType $docType, array $fields): string
    {
        return json_encode(['doc_type' => $docType->value, 'fields' => $fields], JSON_THROW_ON_ERROR);
    }

    /**
     * Stamps the real `documents.id` into `fields.source_citation.document_id`
     * after DocumentAttachmentService::store() resolves it -- the model
     * cannot know this id at extraction time (see Citation's docblock), so
     * this is the one place it gets written, as a fact, not a guess.
     * `document_id` is stored as a string, matching Citation's typed field.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function stampDocumentId(array $fields, int $documentId): array
    {
        $citation = $fields['source_citation'] ?? null;
        if (!is_array($citation)) {
            return $fields;
        }

        $citation['document_id'] = (string) $documentId;
        $fields['source_citation'] = $citation;

        return $fields;
    }

    /**
     * @param list<int> $extractionIds
     */
    private function rollBackExtractions(array $extractionIds, Throwable $originalException): void
    {
        foreach ($extractionIds as $extractionId) {
            try {
                $this->store->delete($extractionId);
            } catch (SqlQueryException $cleanupException) {
                ServiceContainer::getLogger()->error(
                    'Clinical Co-Pilot failed to roll back an orphaned extraction row after a downstream failure',
                    [
                        'extractionId' => $extractionId,
                        'originalException' => $originalException,
                        'cleanupException' => $cleanupException,
                    ],
                );
            }
        }
    }
}
