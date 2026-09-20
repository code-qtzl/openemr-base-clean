<?php

/**
 * Decides whether an "active" problem-list entry is old enough that
 * "active" alone is not good evidence the condition is still relevant to
 * today's visit.
 *
 * get_active_problems' `activity = 1` filter means "not marked resolved" --
 * nothing clears it just because decades have passed with no documented
 * reconciliation. A live query against this fork's seeded database
 * (2026-09-19) found 2,943 of 6,126 active problem rows (48%) with an onset
 * date more than 20 years in the past, some as far back as 1946 -- the same
 * shape of gap AUDIT_Extra.md's Data-Quality Finding 1 documents for
 * get_medications, previously the only tool with a staleness policy
 * (PUNCH_LIST_2.md Item 5). Chronic problems legitimately persist for
 * decades (unlike a prescription, which is normally reconciled
 * periodically), so this class does not filter such rows out or use
 * MedicationStalenessPolicy's shorter threshold -- it only computes an
 * advisory warning so neither the model nor the clinician mistakes a
 * decades-old onset date for a fact that has been recently confirmed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use DateTimeImmutable;

final class ActiveProblemStalenessPolicy
{
    /**
     * Years an unresolved (no resolved_date) problem can go without a
     * documented reconciliation before it is flagged. Chronic conditions
     * are expected to persist for years, so this is deliberately much
     * longer than MedicationStalenessPolicy's threshold -- calibrated to
     * this fork's actual data (see class docblock), not an arbitrary guess.
     */
    private const STALE_AFTER_YEARS = 20;

    /**
     * @param ?string $onsetDate    Raw `begdate` column value, expected to
     *                              begin with a `Y-m-d` date if present.
     * @param ?string $resolvedDate Raw `enddate` column value; any non-null
     *                              value means the problem has a documented
     *                              close, so it is exempt.
     */
    public static function warningFor(?string $onsetDate, ?string $resolvedDate, DateTimeImmutable $now): ?string
    {
        if ($resolvedDate !== null || $onsetDate === null) {
            return null;
        }

        $parsedOnset = DateTimeImmutable::createFromFormat('Y-m-d', substr($onsetDate, 0, 10));
        if ($parsedOnset === false || $parsedOnset > $now) {
            // Malformed or future-dated input is a data-quality problem of
            // its own, not this policy's to adjudicate -- say nothing rather
            // than guess.
            return null;
        }

        $years = $now->diff($parsedOnset)->y;
        if ($years < self::STALE_AFTER_YEARS) {
            return null;
        }

        return sprintf(
            'Onset recorded %d year(s) ago with no resolution date -- confirm this is still relevant '
                . 'before relying on it.',
            $years,
        );
    }
}
