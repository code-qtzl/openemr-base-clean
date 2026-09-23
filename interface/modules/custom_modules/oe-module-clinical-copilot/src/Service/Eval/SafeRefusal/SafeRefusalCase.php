<?php

/**
 * One field's worth of a co-pilot response under test: which field, what
 * the case fixture says the underlying source data looks like (see
 * SafeRefusalCaseType), and the claims the response actually made about
 * that field. Parsing raw prose into this claims-list shape is assumed to
 * happen upstream (the same assumption CitationValidator makes about its
 * claim input) -- this class only validates the already-structured result.
 * See .claude/skills/eval-safe-refusal-validator/SKILL.md.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\SafeRefusal;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation\ClinicalClaim;

final readonly class SafeRefusalCase
{
    /**
     * @param list<ClinicalClaim> $claims Claims the response made about
     *                                    $field. Empty when the response
     *                                    made no claim about this field at
     *                                    all (a full refusal/hedge).
     */
    private function __construct(
        public string $field,
        public SafeRefusalCaseType $caseType,
        public array $claims,
    ) {
    }

    /**
     * Parses `{"field": ..., "case_type": ..., "claims": [...]}`. Claim
     * entries use the same shape ClinicalClaim::fromMixed consumes; any
     * entry that fails to parse is dropped rather than failing the whole
     * case, so one malformed fixture entry does not mask the others.
     */
    public static function fromMixed(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $field = $raw['field'] ?? null;
        if (!is_string($field) || trim($field) === '') {
            return null;
        }

        $rawCaseType = $raw['case_type'] ?? null;
        $caseType = is_string($rawCaseType) ? SafeRefusalCaseType::tryFrom($rawCaseType) : null;
        if ($caseType === null) {
            return null;
        }

        $rawClaims = $raw['claims'] ?? [];
        if (!is_array($rawClaims)) {
            return null;
        }

        $claims = [];
        foreach ($rawClaims as $rawClaim) {
            $claim = ClinicalClaim::fromMixed($rawClaim);
            if ($claim !== null) {
                $claims[] = $claim;
            }
        }

        return new self($field, $caseType, $claims);
    }
}
