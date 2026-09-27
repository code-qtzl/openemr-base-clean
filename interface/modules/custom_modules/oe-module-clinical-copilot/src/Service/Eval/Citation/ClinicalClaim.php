<?php

/**
 * One clinical claim from a co-pilot response, paired with the citation
 * metadata (if any) offered as its grounding.
 *
 * Both this DTO and OpenEMR\Modules\ClinicalCopilot\Service\Verification\
 * VerificationClaim now parse the same five-field Citation contract
 * (source_type/source_id/page_or_section/field_or_chunk_id/quote_or_value) --
 * they are kept as two separate classes anyway, deliberately, because they
 * serve two different behaviors with different failure modes: VerificationClaim
 * feeds ResponseVerifier's safety-critical live reject-to-fallback decision,
 * while this class feeds CitationValidator's report-only offline eval gate.
 * Coupling those two *behaviors* to one shared claim class would let a future
 * eval-only rule change silently ripple into live rejection behavior, which
 * is a worse outcome than the duplication. The underlying Citation value
 * object is a safe, inert five-tuple to share; the two workflow entry points
 * that consume it are not.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation;

use OpenEMR\Modules\ClinicalCopilot\Service\Citation\Citation;

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
