<?php

/**
 * Stores a DocumentPayload's raw bytes as an OpenEMR document, linked back
 * to its `clinical_copilot_extracted_document` row.
 *
 * Calls `\Document::createDocument()` directly rather than
 * `OpenEMR\Services\DocumentService::insertAtPath()` -- that wrapper does
 * not expose `foreign_reference_id`/`foreign_reference_table`, the
 * documented extension point (`documents.foreign_reference_id`/
 * `foreign_reference_table` columns, both nullable/indexed) this class
 * needs for cross-module linkage. `createDocument()` itself returns no
 * document id, so storage is a two-phase write: pass the already-created
 * extraction row's id as the foreign reference, then look the resulting
 * `documents.id` back up by that same pair.
 *
 * `isWhiteFile()`'s `is_uploaded_file()`-adjacent checks live only in
 * `insertAtPath()`, not in `createDocument()` itself -- confirmed by
 * reading both; `createDocument()` is a plain mime-type + file-bytes API,
 * not gated on the request having been an actual HTTP upload. This
 * class's own DocumentPayload mime allowlist is the equivalent guard at
 * this layer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Services\DocumentService;
use RuntimeException;

final class DocumentAttachmentService
{
    private const FOREIGN_REFERENCE_TABLE = 'clinical_copilot_extracted_document';

    /**
     * @var array<string, string> Existing OpenEMR document category path
     *                            per doc type (verified present in a
     *                            default install's `categories` table).
     *                            Spaces in a category's display name are
     *                            written as underscores here --
     *                            DocumentService::isValidPath()/
     *                            getLastIdOfPath() strip underscores from
     *                            the path segment and spaces from the
     *                            stored name separately before comparing,
     *                            so "Lab Report" only resolves via
     *                            "Lab_Report", never a literal space.
     */
    private const CATEGORY_PATH = [
        'lab_pdf' => 'Categories/Lab_Report',
        'intake_form' => 'Categories/Medical_Record',
    ];

    public function __construct(
        private readonly DocumentService $documentService = new DocumentService(),
    ) {
    }

    /**
     * @throws RuntimeException if the category path is missing or the
     *                           underlying document store rejects the
     *                           file (see \Document::createDocument()'s
     *                           error-string-on-failure contract).
     */
    public function store(int $patientId, SchemaDocType $docType, DocumentPayload $payload, int $extractionId): int
    {
        $categoryPath = self::CATEGORY_PATH[$docType->value];
        if (!$this->documentService->isValidPath($categoryPath)) {
            throw new RuntimeException(sprintf('Document category path does not exist: %s', $categoryPath));
        }

        $categoryIdRaw = $this->documentService->getLastIdOfPath($categoryPath);
        if (!is_numeric($categoryIdRaw)) {
            throw new RuntimeException(sprintf('Could not resolve a category id for path: %s', $categoryPath));
        }

        $data = $payload->bytes;
        $document = new \Document();
        $error = $document->createDocument(
            (string) $patientId,
            (int) $categoryIdRaw,
            $payload->filename,
            $payload->mimeType,
            $data,
            foreign_reference_id: (string) $extractionId,
            foreign_reference_table: self::FOREIGN_REFERENCE_TABLE,
        );
        if ($error !== '') {
            throw new RuntimeException(sprintf('Failed to store document: %s', $error));
        }

        return $this->findDocumentId($extractionId);
    }

    private function findDocumentId(int $extractionId): int
    {
        $row = QueryUtils::querySingleRow(
            'SELECT `id` FROM `documents`
              WHERE `foreign_reference_table` = ? AND `foreign_reference_id` = ?
              ORDER BY `id` DESC LIMIT 1',
            [self::FOREIGN_REFERENCE_TABLE, $extractionId],
        );

        if ($row === false || !is_numeric($row['id'] ?? null)) {
            throw new RuntimeException('Document was stored but could not be located by its foreign reference.');
        }

        return (int) $row['id'];
    }
}
