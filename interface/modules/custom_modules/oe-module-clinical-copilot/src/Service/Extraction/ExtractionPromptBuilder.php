<?php

/**
 * Builds the per-doc-type prompt instructing Claude to return extracted
 * fields as strict JSON matching SchemaValidator's exact contract. Required
 * field names come from SchemaValidator::requiredFieldNames() -- a single
 * source of truth -- rather than a hand-copied list here, so this prompt
 * cannot silently drift from what DocumentExtractionService validates the
 * response against afterward.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaValidator;

final class ExtractionPromptBuilder
{
    public static function build(SchemaDocType $docType): string
    {
        $fieldNames = SchemaValidator::requiredFieldNames($docType);
        $fieldList = implode(', ', array_map(
            static fn (string $field): string => "\"{$field}\"",
            $fieldNames,
        ));

        return <<<PROMPT
            You are extracting structured data from a {$docType->value} document for a clinical chart.

            Return ONLY a single JSON object, no other text, matching exactly this shape:

            {"doc_type": "{$docType->value}", "fields": {...}}

            The "fields" object must contain every one of these keys: {$fieldList}.

            "source_citation" must itself be an object describing where in this document the
            extracted values came from. It must contain these five non-empty string keys:
            "source_type", "source_id", "page_or_section", "field_or_chunk_id",
            "quote_or_value". Use "{$docType->value}" for source_type.

            "source_citation" must also contain a "bbox" object locating the primary
            extracted value on the page: {"page": <0-based page index, integer>,
            "x0": <left, 0.0-1.0>, "y0": <top, 0.0-1.0>, "x1": <right, 0.0-1.0>,
            "y1": <bottom, 0.0-1.0>}, normalized against that page's own width and height.
            Estimate this visually from the page -- do not guess a value if the field is
            not actually visible on the page. Do not include a "document_id" key; that is
            filled in separately, not by you.

            List-typed fields (e.g. current_medications, allergies) must be a JSON array --
            an empty array is correct when the document genuinely shows none, not a reason
            to omit the key.

            If a value is genuinely absent from the document, reflect that honestly (an
            empty list, or an empty string only where the field is truly blank) -- never
            invent a value that is not actually present in the document.

            Do not include markdown code fences or any explanation -- respond with the JSON
            object only.
            PROMPT;
    }
}
