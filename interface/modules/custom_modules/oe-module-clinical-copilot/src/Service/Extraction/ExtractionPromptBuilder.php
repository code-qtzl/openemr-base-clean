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

        return match ($docType) {
            SchemaDocType::LabPdf => self::labPrompt($fieldList),
            SchemaDocType::IntakeForm => self::intakePrompt($docType, $fieldList),
        };
    }

    private static function labPrompt(string $fieldList): string
    {
        return <<<PROMPT
            You are extracting structured data from a lab_pdf document for a clinical chart.

            A lab report is usually a panel with several test rows. Return ONLY a single JSON
            object, no other text, matching exactly this shape:

            {"doc_type": "lab_pdf", "results": [{...}, {...}]}

            "results" must contain ONE object per test result row shown in the document: a
            report with six test rows yields six objects. Never merge rows, never skip a row,
            and never invent a row that is not on the page. Keep them in the order they appear.

            Every object in "results" must contain every one of these keys: {$fieldList}.

            Each object's "source_citation" must itself be an object describing where in this
            document THAT result's values came from. It must contain these five non-empty
            string keys: "source_type", "source_id", "page_or_section", "field_or_chunk_id",
            "quote_or_value". Use "lab_pdf" for source_type, and a different "field_or_chunk_id"
            for each result (e.g. the test name).

            Each "source_citation" must also contain a "bbox" object locating THAT result's
            value on the page: {"page": <0-based page index, integer>, "x0": <left, 0.0-1.0>,
            "y0": <top, 0.0-1.0>, "x1": <right, 0.0-1.0>, "y1": <bottom, 0.0-1.0>}, normalized
            against that page's own width and height. Estimate this visually from the page --
            each result gets its own box, not one box for the whole table -- and do not guess
            a value if the row is not actually visible on the page. Do not include a
            "document_id" key; that is filled in separately, not by you.

            "abnormal_flag" is the flag the report itself prints for that row (for example "H"
            or "L"). Most reports print nothing for a normal result: use an empty string then,
            and never invent a flag such as "N" that is not on the page.

            If any other value is genuinely absent from the document, reflect that honestly
            (an empty string only where the field is truly blank) -- never invent a value that
            is not actually present in the document.

            Do not include markdown code fences or any explanation -- respond with the JSON
            object only.
            PROMPT;
    }

    private static function intakePrompt(SchemaDocType $docType, string $fieldList): string
    {
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
