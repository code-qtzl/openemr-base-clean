<?php

/**
 * One clinical claim from the model's forced submit_answer tool call, and
 * the citation metadata it says supports it.
 *
 * Carries the same five-field Citation contract
 * (source_type/source_id/page_or_section/field_or_chunk_id/quote_or_value)
 * as the offline citation_present eval gate
 * (Service\Eval\Citation\ClinicalClaim/CitationValidator) -- see Citation's
 * own docblock for why the value object is shared while the two claim
 * classes are kept separate.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Verification;

use OpenEMR\Modules\ClinicalCopilot\Service\Citation\Citation;

final readonly class VerificationClaim
{
    private function __construct(
        public string $text,
        public ?Citation $citation,
    ) {
    }

    /**
     * Parses one element of submit_answer's `claims` array. The model's
     * tool-call input is untrusted, schema-shaped JSON, not a guaranteed
     * type -- this is the one place that turns it into a typed value or
     * rejects it outright, so nothing downstream has to re-check shapes.
     *
     * A citation that is present but not itself a valid array is treated as
     * absent (Citation::fromMixed() returns null), not as a parse failure --
     * ResponseVerifier's "citation missing" rejection path covers that case
     * identically to a claim with no citation key at all.
     */
    public static function fromMixed(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $text = $raw['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        $rawCitation = $raw['citation'] ?? null;
        $citation = $rawCitation === null ? null : Citation::fromMixed($rawCitation);

        return new self(trim($text), $citation);
    }

    /**
     * @return array{text: string, citation: ?array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'citation' => $this->citation?->toArray(),
        ];
    }

    /**
     * @param list<self> $claims
     * @return list<array{text: string, citation: ?array<string, mixed>}>
     */
    public static function listToArray(array $claims): array
    {
        return array_map(static fn (self $claim): array => $claim->toArray(), $claims);
    }
}
