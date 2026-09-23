<?php

/**
 * One claim under test for factual consistency: its prose (kept only for
 * failure reporting), the specific value it asserts, and the citation it
 * asserts that value against. Distinct from
 * OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation\ClinicalClaim --
 * that DTO has no structured value to compare, only prose, which is
 * exactly what this eval avoids parsing. See
 * .claude/skills/eval-factually-consistent-validator/SKILL.md.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\FactualConsistency;

use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation\Citation;

final readonly class FactualConsistencyClaim
{
    private function __construct(
        public string $claim,
        public string $assertedValue,
        public ?Citation $citation,
    ) {
    }

    /**
     * Parses `{"claim": ..., "asserted_value": ..., "citation": {...}}`.
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

        $assertedValue = $raw['asserted_value'] ?? null;
        if (!is_string($assertedValue) || trim($assertedValue) === '') {
            return null;
        }

        $rawCitation = $raw['citation'] ?? null;
        $citation = $rawCitation === null ? null : Citation::fromMixed($rawCitation);

        return new self(trim($claimText), $assertedValue, $citation);
    }
}
