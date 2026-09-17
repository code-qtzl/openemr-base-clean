<?php

/**
 * Isolated MedicationStalenessPolicy Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service;

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\Service\MedicationStalenessPolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/MedicationStalenessPolicy.php';

class MedicationStalenessPolicyTest extends TestCase
{
    private const NOW = '2026-09-17';

    public function testRecentOpenEndedMedicationIsNotFlagged(): void
    {
        $warning = MedicationStalenessPolicy::warningFor('2026-01-10', null, self::now());

        self::assertNull($warning);
    }

    public function testMedicationWithAnEndDateIsNeverFlaggedRegardlessOfAge(): void
    {
        // A closed prescription is not an "active without reconciliation"
        // problem -- it has a documented end, however old.
        $warning = MedicationStalenessPolicy::warningFor('1948-06-01', '1949-01-01', self::now());

        self::assertNull($warning);
    }

    public function testDecadesOldOpenEndedMedicationIsFlagged(): void
    {
        // This is the exact AUDIT_Extra.md Finding 1 shape: a prescription
        // from 1948 with no end date, still marked active.
        $warning = MedicationStalenessPolicy::warningFor('1948-06-01', null, self::now());

        self::assertNotNull($warning);
        self::assertStringContainsString('78 year', $warning);
    }

    public function testJustUnderTheThresholdIsNotFlagged(): void
    {
        $warning = MedicationStalenessPolicy::warningFor('2025-06-01', null, self::now());

        self::assertNull($warning);
    }

    public function testAtTheThresholdIsFlagged(): void
    {
        $warning = MedicationStalenessPolicy::warningFor('2024-09-01', null, self::now());

        self::assertNotNull($warning);
    }

    public function testNullStartDateIsNotFlagged(): void
    {
        self::assertNull(MedicationStalenessPolicy::warningFor(null, null, self::now()));
    }

    public function testMalformedStartDateIsNotFlagged(): void
    {
        // A parsing failure is its own data-quality problem; this policy
        // must never throw over it or guess at staleness.
        self::assertNull(MedicationStalenessPolicy::warningFor('not-a-date', null, self::now()));
    }

    public function testFutureStartDateIsNotFlagged(): void
    {
        self::assertNull(MedicationStalenessPolicy::warningFor('2099-01-01', null, self::now()));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
