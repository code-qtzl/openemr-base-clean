<?php

/**
 * Deterministic citation-grounding check for the `citation_present` eval
 * category (.claude/skills/eval-citation-validator/SKILL.md). A claim passes
 * only when its citation carries all five required fields -- source_type,
 * source_id, page_or_section, field_or_chunk_id, quote_or_value -- each a
 * non-empty string. This is pure structural validation: it never judges
 * whether a citation is truthful or well-formed prose, only whether the
 * required metadata is present, and it never repairs a missing field.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Citation;

final class CitationValidator
{
    /**
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'source_type',
        'source_id',
        'page_or_section',
        'field_or_chunk_id',
        'quote_or_value',
    ];

    public static function validateClaim(ClinicalClaim $claim): ClaimCitationResult
    {
        $fields = $claim->citation?->toArray() ?? array_fill_keys(self::REQUIRED_FIELDS, null);

        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $fields[$field] ?? null;
            if ($value === null || trim($value) === '') {
                $missing[] = $field;
            }
        }

        return new ClaimCitationResult($claim->claim, $missing === [], $missing);
    }

    /**
     * @param list<ClinicalClaim> $claims Every claim in one response.
     *                                    citation_present is true only if
     *                                    every claim is individually
     *                                    grounded -- one uncited claim fails
     *                                    the whole response, matching
     *                                    SKILL.md's "Multiple claims where
     *                                    one lacks a citation" case.
     */
    public static function validateBatch(array $claims): CitationValidationReport
    {
        $failures = [];
        foreach ($claims as $claim) {
            $result = self::validateClaim($claim);
            if (!$result->citationPresent) {
                $failures[] = $result;
            }
        }

        return new CitationValidationReport($failures === [], $failures);
    }
}
