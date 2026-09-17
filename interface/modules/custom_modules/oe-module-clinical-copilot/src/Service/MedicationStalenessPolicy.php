<?php

/**
 * Decides whether an "active" prescription row is old enough that "active"
 * alone is not good evidence the patient is still taking it.
 *
 * get_medications' `active` column means "not marked discontinued" -- it is
 * never cleared just because time has passed with no documented
 * reconciliation. Synthea-seeded and real charts alike can carry a
 * prescription flagged active for decades (see AUDIT_Extra.md's Data-Quality
 * Finding 1 and PUNCH_LIST.md 1.4(a)). This class does not filter such rows
 * out -- doing so risks hiding a genuinely long-standing chronic medication
 * -- it only computes an advisory warning so neither the model nor the
 * clinician mistakes an old start date for a current fact without checking.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use DateTimeImmutable;

final class MedicationStalenessPolicy
{
    /**
     * Years an open-ended (no end_date) prescription can go without a
     * documented reconciliation before it is flagged. This is not a
     * clinical claim that the drug was stopped -- only a floor past which
     * "active" alone stops being enough evidence to assert current use.
     */
    private const STALE_AFTER_YEARS = 2;

    /**
     * @param ?string $startDate Raw `start_date` column value, expected to
     *                           begin with a `Y-m-d` date if present.
     * @param ?string $endDate   Raw `end_date` column value; any non-null
     *                           value means the prescription has a
     *                           documented close, so it is exempt.
     */
    public static function warningFor(?string $startDate, ?string $endDate, DateTimeImmutable $now): ?string
    {
        if ($endDate !== null || $startDate === null) {
            return null;
        }

        $parsedStart = DateTimeImmutable::createFromFormat('Y-m-d', substr($startDate, 0, 10));
        if ($parsedStart === false || $parsedStart > $now) {
            // Malformed or future-dated input is a data-quality problem of
            // its own, not this policy's to adjudicate -- say nothing rather
            // than guess.
            return null;
        }

        $years = $now->diff($parsedStart)->y;
        if ($years < self::STALE_AFTER_YEARS) {
            return null;
        }

        return sprintf(
            'Started %d year(s) ago with no end date recorded -- confirm this is still current before relying on it.',
            $years,
        );
    }
}
