<?php

/**
 * One previously-extracted document row (a lab PDF or intake form the
 * physician uploaded and attached via the Clinical Co-Pilot sidebar).
 * `fieldsJson` is the raw `{"doc_type": ..., "fields": {...}}` envelope
 * DocumentIngestionPipeline persisted, passed through as a single string
 * rather than flattened -- ToolResultRow's contract is `array<string,
 * string|null>`, and the extracted fields are doc-type-specific and
 * sometimes nested (e.g. intake_form's source_citation object), so there is
 * no single flat shape that would fit every row. The model reads embedded
 * JSON text natively; no parsing is needed on this side.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

final readonly class ExtractedDocumentRow implements ToolResultRow
{
    public function __construct(
        public ?string $docType,
        public ?string $extractedAt,
        public ?string $fieldsJson,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'doc_type' => $this->docType,
            'extracted_at' => $this->extractedAt,
            'fields_json' => $this->fieldsJson,
        ];
    }
}
