<?php

/**
 * Isolated HealthChecker Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Health;

use OpenEMR\Health\Check\InstallationCheck;
use OpenEMR\Health\HealthChecker;
use OpenEMR\Health\HealthCheckInterface;
use OpenEMR\Health\HealthCheckResult;
use PHPUnit\Framework\TestCase;

class HealthCheckerTest extends TestCase
{
    public function testHealthyRequiresEveryCheckToPass(): void
    {
        $checker = new HealthChecker(registerDefaultChecks: false);
        $checker->addCheck(self::stubCheck(InstallationCheck::NAME, true));
        $checker->addCheck(self::stubCheck('database', true));
        $checker->addCheck(self::stubCheck('cache', false));

        $results = $checker->getResultsArray();

        self::assertSame('ready', $results['status']);
        self::assertFalse($results['healthy']);
        self::assertFalse($results['checks']['cache']);
    }

    /**
     * PUNCH_LIST.md 1.2 regression test: `library/sql.inc.php` reassigns the
     * global `$config` (sqlconf.php's 0/1 install flag) to a
     * DatabaseConnectionOptions instance as part of its own bootstrap, and
     * that file loads during interface/globals.php's normal chain. By the
     * time InstallationCheck runs from meta/health/index.php, it always
     * reports unhealthy on a fully-installed, fully-running instance. If
     * `healthy` were gated on InstallationCheck like every other check, a
     * correctly-running instance's /readyz would return 503 forever. It
     * must not: `healthy` reflects only the real dependency checks.
     */
    public function testHealthyIgnoresInstallationCheckDespiteStatusReflectingIt(): void
    {
        $checker = new HealthChecker(registerDefaultChecks: false);
        $checker->addCheck(self::stubCheck(InstallationCheck::NAME, false));
        $checker->addCheck(self::stubCheck('database', true));
        $checker->addCheck(self::stubCheck('anthropic_api', true));

        $results = $checker->getResultsArray();

        self::assertSame('setup_required', $results['status']);
        self::assertTrue($results['healthy']);
        self::assertFalse($results['checks']['installed'], 'installed check result is still surfaced as-is');
    }

    public function testAllChecksHealthyAndInstalledIsFullyHealthy(): void
    {
        $checker = new HealthChecker(registerDefaultChecks: false);
        $checker->addCheck(self::stubCheck(InstallationCheck::NAME, true));
        $checker->addCheck(self::stubCheck('database', true));

        $results = $checker->getResultsArray();

        self::assertSame('ready', $results['status']);
        self::assertTrue($results['healthy']);
    }

    private static function stubCheck(string $name, bool $healthy): HealthCheckInterface
    {
        return new class ($name, $healthy) implements HealthCheckInterface {
            public function __construct(private readonly string $name, private readonly bool $healthy)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function check(): HealthCheckResult
            {
                return new HealthCheckResult($this->name, $this->healthy);
            }
        };
    }
}
