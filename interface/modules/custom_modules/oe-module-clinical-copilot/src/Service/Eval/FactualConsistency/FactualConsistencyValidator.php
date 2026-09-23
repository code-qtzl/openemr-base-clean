<?php

/**
 * Deterministic value-consistency check for the `factually_consistent`
 * eval category
 * (.claude/skills/eval-factually-consistent-validator/SKILL.md).
 * Deliberately narrow: only numeric/discrete values (quote_or_value
 * containing at least one digit) are checked, and only against the
 * claim's own citation -- never against a re-fetched source document, and
 * never via an LLM judge. Narrative claims and claims with no citation
 * value to check against are silently skipped, not failed -- see
 * SKILL.md's "Checkability" and "Deliberately Narrow Scope" sections for
 * why, in particular the direct response to the "llm-as-a-judge without
 * clear rubric" pitfall.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\FactualConsistency;

final class FactualConsistencyValidator
{
    private const MATCH_EPSILON = 0.0001;

    public static function evaluateClaim(FactualConsistencyClaim $claim): FactualConsistencyResult
    {
        $quoteOrValue = $claim->citation?->quoteOrValue;
        if ($quoteOrValue === null || trim($quoteOrValue) === '') {
            // Not checkable: no citation value to compare against. That is
            // citation_present's failure to report, not this validator's.
            return new FactualConsistencyResult(true, []);
        }

        if (preg_match('/\d/', $quoteOrValue) !== 1) {
            // Not checkable: narrative value, out of this validator's scope.
            return new FactualConsistencyResult(true, []);
        }

        if (self::valuesMatch($claim->assertedValue, $quoteOrValue)) {
            return new FactualConsistencyResult(true, []);
        }

        return new FactualConsistencyResult(false, [
            new FactualConsistencyFinding(
                $claim->claim,
                $claim->assertedValue,
                $quoteOrValue,
                "asserted value does not match the cited source's quote_or_value",
            ),
        ]);
    }

    /**
     * @param list<FactualConsistencyClaim> $claims Every claim in one
     *                                               response. Skipped
     *                                               (non-checkable) claims
     *                                               never cause the batch
     *                                               to fail.
     */
    public static function evaluateBatch(array $claims): FactualConsistencyResult
    {
        $findings = [];
        foreach ($claims as $claim) {
            foreach (self::evaluateClaim($claim)->findings as $finding) {
                $findings[] = $finding;
            }
        }

        return new FactualConsistencyResult($findings === [], $findings);
    }

    private static function valuesMatch(string $assertedValue, string $quoteOrValue): bool
    {
        $normalizedAsserted = self::normalize($assertedValue);
        $normalizedQuote = self::normalize($quoteOrValue);

        $assertedNumeric = self::stripTrailingPercent($normalizedAsserted);
        $quoteNumeric = self::stripTrailingPercent($normalizedQuote);

        if (is_numeric($assertedNumeric) && is_numeric($quoteNumeric)) {
            return abs((float) $assertedNumeric - (float) $quoteNumeric) < self::MATCH_EPSILON;
        }

        return $normalizedAsserted === $normalizedQuote;
    }

    private static function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($value));

        return strtolower($collapsed ?? trim($value));
    }

    private static function stripTrailingPercent(string $value): string
    {
        return str_ends_with($value, '%') ? substr($value, 0, -1) : $value;
    }
}
