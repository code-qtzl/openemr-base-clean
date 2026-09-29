<?php

/**
 * Structured source-attribution metadata for one clinical claim: which
 * source it came from, where in that source, and the exact value or quote
 * grounding it. See .claude/skills/eval-citation-validator/SKILL.md for the
 * contract this mirrors.
 *
 * Originally lived under Service\Eval\Citation as an eval-only concern;
 * relocated here once the live Supervisor/CopilotService submit_answer path
 * adopted this same five-field shape for VerificationClaim, since it is now
 * the citation contract for both the live gate and the offline eval gate,
 * not just the latter.
 *
 * `documentId`/`bbox` are additive to that original five-field contract --
 * required only when `sourceType` is `lab_pdf` or `intake_form` (a
 * `chart_tool`/`guideline` citation has no PDF page to point at), enforced by
 * CitationValidator/ResponseVerifier, not by this class. `bbox` is
 * model-authored (Claude estimates it from the page it reads during
 * extraction), but `documentId` is never model-invented -- the extraction
 * pipeline cannot know a DB-generated documents.id until after the row is
 * persisted, so it is stamped into the extracted fields_json by
 * DocumentIngestionPipeline after the fact, and the model only ever copies it
 * forward verbatim when citing a previously-extracted document.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Citation;

final readonly class Citation
{
    private function __construct(
        public ?string $sourceType,
        public ?string $sourceId,
        public ?string $pageOrSection,
        public ?string $fieldOrChunkId,
        public ?string $quoteOrValue,
        public ?string $documentId,
        public ?CitationBoundingBox $bbox,
    ) {
    }

    /**
     * Parses an untrusted `citation` payload (from model output, a golden-set
     * fixture, or any other external shape) into a typed value. A field that
     * is present but not a string is treated the same as an absent field --
     * callers reject either way -- rather than coercing it. A malformed
     * `bbox` (wrong shape, non-numeric coordinates) parses to `null` rather
     * than rejecting the whole citation, the same tolerance already applied
     * to the five string fields.
     */
    public static function fromMixed(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        return new self(
            self::stringOrNull($raw['source_type'] ?? null),
            self::stringOrNull($raw['source_id'] ?? null),
            self::stringOrNull($raw['page_or_section'] ?? null),
            self::stringOrNull($raw['field_or_chunk_id'] ?? null),
            self::stringOrNull($raw['quote_or_value'] ?? null),
            self::stringOrNull($raw['document_id'] ?? null),
            CitationBoundingBox::fromMixed($raw['bbox'] ?? null),
        );
    }

    /**
     * @return array{
     *     source_type: ?string,
     *     source_id: ?string,
     *     page_or_section: ?string,
     *     field_or_chunk_id: ?string,
     *     quote_or_value: ?string,
     *     document_id: ?string,
     *     bbox: ?array{page: int, x0: float, y0: float, x1: float, y1: float},
     * }
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'page_or_section' => $this->pageOrSection,
            'field_or_chunk_id' => $this->fieldOrChunkId,
            'quote_or_value' => $this->quoteOrValue,
            'document_id' => $this->documentId,
            'bbox' => $this->bbox?->toArray(),
        ];
    }

    /**
     * True when this citation's source_type requires document-linkage
     * (documentId + bbox) to support a click-to-source PDF overlay --
     * `lab_pdf`/`intake_form` only. `chart_tool`/`guideline` citations have
     * no PDF page to point at.
     */
    public function requiresDocumentLinkage(): bool
    {
        return in_array($this->sourceType, ['lab_pdf', 'intake_form'], true);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
