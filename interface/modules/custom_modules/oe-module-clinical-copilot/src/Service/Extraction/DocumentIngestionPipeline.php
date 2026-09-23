<?php

/**
 * AgentForge2 Core Requirement #1's `attach_and_extract(patient_id,
 * file_path, doc_type)`: accepts a file, extracts and self-validates
 * structured JSON via DocumentExtractionService, and -- only when that
 * validation passes -- stores the source document in OpenEMR and persists
 * the extraction, each linked back to the other.
 *
 * Not wired to an HTTP endpoint or the live chat tool loop yet -- this is
 * the callable pipeline itself; see Eval/README.md's precedent (the eval
 * validators built the same way: standalone, tested logic first, live
 * wiring deferred and documented explicitly rather than dropped silently).
 *
 * Known MVP gap: the document-storage step is not wrapped in a database
 * transaction with the extraction-row insert. If storeDocument() throws
 * after save() has already inserted the extraction row, that row persists
 * with a null document_id -- a recoverable, visible partial-failure state
 * (findable via `document_id IS NULL`), not a silent inconsistency, but
 * not yet auto-repaired either.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;

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
        if (!$extraction->success || $extraction->document === null) {
            return DocumentIngestionResult::extractionFailed($extraction);
        }

        $fieldsJson = json_encode([
            'doc_type' => $extraction->document->docType->value,
            'fields' => $extraction->document->fields,
        ], JSON_THROW_ON_ERROR);

        $extractionId = $this->store->save($patientId, $docType, $fieldsJson);

        $documentId = $this->attachmentService->store($patientId, $docType, $payload, $extractionId);
        $this->store->attachDocumentId($extractionId, $documentId);

        return DocumentIngestionResult::success($extractionId, $documentId, $extraction->document);
    }
}
