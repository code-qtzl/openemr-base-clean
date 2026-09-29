<?php

/**
 * `clinical_copilot_extracted_document`-backed persistence for a validated
 * document extraction.
 *
 * Unlike SqlConversationStore (which deliberately swallows failures because
 * degrading a chat reply to memory-less is always safe), a failure here
 * must propagate: a physician who uploads a document expects it to
 * actually be stored, and a swallowed write failure would report success
 * on data that was never persisted. Per this project's error-handling
 * standard ("let exceptions propagate; only catch when the caller can
 * meaningfully recover"), SqlQueryException is not caught here --
 * DocumentIngestionPipeline decides how to surface it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;

final class SqlExtractedDocumentStore
{
    /**
     * @throws \OpenEMR\Common\Database\SqlQueryException
     */
    public function save(int $patientId, SchemaDocType $docType, string $fieldsJson): int
    {
        $id = QueryUtils::sqlInsert(
            'INSERT INTO `clinical_copilot_extracted_document`
                (`pid`, `doc_type`, `fields_json`, `created_at`, `last_updated`)
             VALUES (?, ?, ?, NOW(), NOW())',
            [$patientId, $docType->value, $fieldsJson],
        );

        return (int) $id;
    }

    /**
     * Links the row to its stored source file and, in the same write,
     * replaces `fields_json` with a copy that has the same `documents.id`
     * stamped into `fields.source_citation.document_id`. That id cannot
     * exist at save()-time (the document row isn't created yet), so this is
     * the one place ground-truth document linkage gets written -- never
     * model-authored. See Citation's docblock.
     *
     * @throws \OpenEMR\Common\Database\SqlQueryException
     */
    public function attachDocumentId(int $extractionId, int $documentId, string $fieldsJson): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `clinical_copilot_extracted_document`
                SET `document_id` = ?, `fields_json` = ?, `last_updated` = NOW()
              WHERE `id` = ?',
            [$documentId, $fieldsJson, $extractionId],
        );
    }

    public function find(int $extractionId): ?ExtractedDocumentRecord
    {
        $row = QueryUtils::querySingleRow(
            'SELECT `id`, `pid`, `document_id`, `doc_type`, `fields_json`, `created_at`
               FROM `clinical_copilot_extracted_document`
              WHERE `id` = ?',
            [$extractionId],
        );

        if ($row === false) {
            return null;
        }

        return ExtractedDocumentRecord::fromRow($row);
    }

    /**
     * DocumentIngestionPipeline's compensating action when document storage
     * fails after this row was already inserted -- see its docblock. Not
     * used for anything else; a normal, successful extraction is never
     * deleted.
     *
     * @throws \OpenEMR\Common\Database\SqlQueryException
     */
    public function delete(int $extractionId): void
    {
        QueryUtils::sqlStatementThrowException(
            'DELETE FROM `clinical_copilot_extracted_document` WHERE `id` = ?',
            [$extractionId],
        );
    }
}
