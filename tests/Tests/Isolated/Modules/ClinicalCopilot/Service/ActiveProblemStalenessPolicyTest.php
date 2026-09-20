<?php

/**
 * Isolated ActiveProblemStalenessPolicy Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service;

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\Service\ActiveProblemStalenessPolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/ActiveProblemStalenessPolicy.php';

class ActiveProblemStalenessPolicyTest extends TestCase
{
    private const NOW = '2026-09-19';

    public function testRecentUnresolvedProblemIsNotFlagged(): void
    {
        $warning = ActiveProblemStalenessPolicy::warningFor('2026-01-10', null, self::now());

        self::assertNull($warning);
    }

    public function testProblemWithAResolvedDateIsNeverFlaggedRegardlessOfAge(): void
    {
        // A closed problem is not an "active without reconciliation"
        // problem -- it has a documented resolution, however old the onset.
        $warning = ActiveProblemStalenessPolicy::warningFor('1946-10-07', '1950-01-01', self::now());

        self::assertNull($warning);
    }

    public function testDecadesOldUnresolvedProblemIsFlagged(): void
    {
        // This mirrors the real shape found in this fork's own seeded data
        // (see class docblock): the earliest observed onset date, 1946,
        // with no resolved_date, still marked active.
        $warning = ActiveProblemStalenessPolicy::warningFor('1946-10-07', null, self::now());

        self::assertNotNull($warning);
        self::assertStringContainsString('79 year', $warning);
    }

    public function testChronicProblemUnderTwentyYearsIsNotFlagged(): void
    {
        // Chronic conditions legitimately persist for years -- this policy's
        // threshold is deliberately far longer than MedicationStalenessPolicy's.
        $warning = ActiveProblemStalenessPolicy::warningFor('2010-01-01', null, self::now());

        self::assertNull($warning);
    }

    public function testJustUnderTheThresholdIsNotFlagged(): void
    {
        $warning = ActiveProblemStalenessPolicy::warningFor('2006-09-20', null, self::now());

        self::assertNull($warning);
    }

    public function testAtTheThresholdIsFlagged(): void
    {
        $warning = ActiveProblemStalenessPolicy::warningFor('2006-09-01', null, self::now());

        self::assertNotNull($warning);
    }

    public function testNullOnsetDateIsNotFlagged(): void
    {
        self::assertNull(ActiveProblemStalenessPolicy::warningFor(null, null, self::now()));
    }

    public function testMalformedOnsetDateIsNotFlagged(): void
    {
        // A parsing failure is its own data-quality problem; this policy
        // must never throw over it or guess at staleness.
        self::assertNull(ActiveProblemStalenessPolicy::warningFor('not-a-date', null, self::now()));
    }

    public function testFutureOnsetDateIsNotFlagged(): void
    {
        self::assertNull(ActiveProblemStalenessPolicy::warningFor('2099-01-01', null, self::now()));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
