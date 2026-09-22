<?php

/**
 * One clinical claim from a co-pilot response, paired with the citation
 * metadata (if any) offered as its grounding. Distinct from
 * OpenEMR\Modules\ClinicalCopilot\Service\Verification\VerificationClaim,
 * which only tracks which tool was called this turn -- this DTO carries the
 * richer source_type/source_id/page_or_section/field_or_chunk_id/
 * quote_or_value contract the citation eval validates against.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation;

final readonly class ClinicalClaim
{
    private function __construct(
        public string $claim,
        public ?Citation $citation,
    ) {
    }

    /**
     * Parses one eval-case or logged-response claim, e.g.
     * `{"claim": "...", "citation": {"source_type": ..., ...}}`. Untrusted,
     * schema-shaped input -- this is the one place it becomes a typed value.
     */
    public static function fromMixed(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $claimText = $raw['claim'] ?? null;
        if (!is_string($claimText) || trim($claimText) === '') {
            return null;
        }

        $rawCitation = $raw['citation'] ?? null;
        $citation = $rawCitation === null ? null : Citation::fromMixed($rawCitation);

        return new self(trim($claimText), $citation);
    }
}
