<?php

/**
 * One persisted row of `clinical_copilot_extracted_document`.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;

final readonly class ExtractedDocumentRecord
{
    public function __construct(
        public int $id,
        public int $patientId,
        public ?int $documentId,
        public SchemaDocType $docType,
        public string $fieldsJson,
        public string $createdAt,
    ) {
    }

    /**
     * Parses a raw QueryUtils row. Returns null on a malformed row rather
     * than throwing -- a store read failure degrades to "not found," the
     * same posture SqlConversationStore takes on decode failure.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = $row['id'] ?? null;
        $patientId = $row['pid'] ?? null;
        $documentId = $row['document_id'] ?? null;
        $docTypeRaw = $row['doc_type'] ?? null;
        $fieldsJson = $row['fields_json'] ?? null;
        $createdAt = $row['created_at'] ?? null;

        if (!is_numeric($id) || !is_numeric($patientId) || !is_string($docTypeRaw) || !is_string($fieldsJson) || !is_string($createdAt)) {
            return null;
        }

        $docType = SchemaDocType::tryFrom($docTypeRaw);
        if ($docType === null) {
            return null;
        }

        return new self(
            (int) $id,
            (int) $patientId,
            $documentId !== null && is_numeric($documentId) ? (int) $documentId : null,
            $docType,
            $fieldsJson,
            $createdAt,
        );
    }
}
